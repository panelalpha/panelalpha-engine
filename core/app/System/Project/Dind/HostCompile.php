<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind\Strategy\PythonBase;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\HostRunProject;
use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Deploy\Platform\Runtime\Ruby\SystemPackages;
use App\Lib\Deploy\Platform\Runtime\RubyRuntime;
use App\Lib\Deploy\Platform\Runtime\Ruby\RubyApp;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\PhpRuntime;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\System\Project\Dind as DindProject;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Lib\Deploy\Engine\HostBuilder;
use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use App\Lib\Deploy\Platform\Runtime\Php\PhpHostBuild;
use App\Lib\Deploy\Platform\Runtime\StandaloneNodeServe;


/**
 * Build a JS project on the *host* Docker daemon instead of inside the account.
 *
 * npm/pnpm/bun + bundler run in a throwaway host container that bind-mounts
 * ~/project, writing dist/, .output/, and — for a stock Node runtime —
 * node_modules back into it. Inner DinD then serves those folders from
 * nginx or Node. Static nginx compiles keep node_modules on the host cache
 * so they never occupy the account.
 */
class HostCompile
{
    /**
     * Magento's `composer install` is the reason this is not the JS build's
     * hour: it resolves ~200 packages and has been measured past 20 minutes
     * on a cold cache.
     */
    private const PHP_BUILD_TIMEOUT_SECONDS = 2400;

    private DindProject $project;

    public function __construct(DindProject $project)
    {
        $this->project = $project;
    }

    private function hostBuilder(): HostBuilder
    {
        return $this->project->engine()->hostBuilder();
    }

    /**
     * No-op unless the detected recipe serves prebuilt output (nginx static or
     * a standalone Nitro server).
     */
    public function run(): void
    {
        $projectDir = $this->project->userAppDirPath();
        $decision = DetectProjectStrategy::detect(
            $projectDir,
            $this->project->userModel()->getGitRepo(),
            app(RecipeChoiceContext::class)->get()
        );
        // This is a second detection -- SourcePreparation already ran one --
        // and the request's stage overrides live on the plan, not in the
        // project, so a fresh decision does not carry them. Without this the
        // `stages` field was silently ignored by every host compile: the
        // commands it named were written into the generated Dockerfile and
        // then never run, because a host-compiled recipe has no Dockerfile.
        // Observed as a deploy that installed the *default* dependencies and
        // failed on the incompatibility the override existed to pin away.
        $plan = app(DeployPlanContext::class)->get();
        if ($plan !== null) {
            $decision = $plan->applyToDecision($decision);
        }
        $isNginx = ($decision['runtime'] ?? '') === PlatformManifest::RUNTIME_NGINX;
        $isNitro = DeployCompose::isStandaloneNodeOutput($decision);
        // A mounted-project Node app compiles here for the same reason the
        // other two do: the result is served from the account's directory by
        // a stock image, so nothing downstream builds it. {@see HostRunProject}.
        $isMounted = DeployCompose::isHostRunProject($decision);
        if (!$isNginx && !$isNitro && !$isMounted) {
            return;
        }
        $install = trim((string) ($decision['install_command'] ?? ''));
        $build = trim((string) ($decision['build_command'] ?? ''));
        if ($install === '' && $build === '') {
            return;
        }

        $logger = $this->project->shell()->logger();
        $logger?->info(
            $isNginx ? 'Compiling static assets on host' : 'Compiling application on host'
        );
        $system = $this->project->system();
        $cached = $this->prepareCache();

        $isNode = $isNginx || $isNitro || HostRunProject::isNode($decision['strategy'] ?? null);
        $nodeImage = Images::nodeImage($projectDir);
        // A command runtime compiles in its own language image -- the one the
        // recipe resolved and the one the container will run, so a venv built
        // here works there and a binary linked here runs there.
        if (!$isNode) {
            $image = $this->commandRuntimeImage($decision, $projectDir) ?: $nodeImage;
        } elseif ($isNitro || $isMounted) {
            $image = HostNodeBuild::runtimeImage($this->projectPackageManager($projectDir), $nodeImage);
        } else {
            $image = HostNodeBuild::compilerImage(
                $install,
                $nodeImage,
                $this->projectPackageManager($projectDir)
            );
        }
        $installCmd = $install;
        $buildCmd = $build;
        if ($image === HostNodeBuild::BUN_IMAGE) {
            $installCmd = HostNodeBuild::bunInstallCommand($install);
            $buildCmd = HostNodeBuild::bunBuildCommand($build);
        }
        $recipeEnv = is_array($decision['env'] ?? null) ? $decision['env'] : [];
        // node_modules is part of the deployed application for both host-run
        // shapes, so it stays in the project rather than being isolated into
        // the cache the way a throwaway static compile's is.
        $isolateNodeModules = !$isNitro && !$isMounted;
        try {
            $this->runContainer($projectDir, $image, $installCmd, $buildCmd, $recipeEnv, $isolateNodeModules && $cached, $isNode);
        } catch (\Exception $e) {
            // Standalone Node must keep compile and runtime on the same
            // interpreter. Falling back to Node after a bun install leaves
            // bun-only packages in node_modules.
            if ($isNitro || $isMounted || !$isNode || $image === $nodeImage) {
                throw $e;
            }
            $logger?->info('Bun compile failed, retrying with Node: ' . $e->getMessage());
            // The recipe's own commands are bun-flavoured for a bun-lockfile
            // project (`bun install`, `bun run build`), so replaying them in the
            // Node image only fails again with `bun: not found`.
            $this->runContainer(
                $projectDir,
                $nodeImage,
                HostNodeBuild::nodeInstallCommand($install),
                HostNodeBuild::nodeBuildCommand($build),
                $recipeEnv,
                $isolateNodeModules && $cached
            );
        }

        $output = NodeRuntime::safeOutputDir(
            $decision['output_directory'] ?? null,
            $isNitro ? '.output' : 'dist'
        );
        $this->assertBuildProduced($projectDir, $output, $isNitro, $isMounted, $isNode);

        $chown = $this->project->userModel()->getChownString();
        if (is_string($chown) && $chown !== '') {
            $dirs = match (true) {
                // The whole project is mounted and written to at runtime, so
                // everything the compile produced has to belong to the account.
                $isMounted => $isNode ? [$output, 'node_modules'] : ['.'],
                $isNitro => array_merge(StandaloneNodeServe::volumeSources(), ['node_modules']),
                default => [$output],
            };
            foreach ($dirs as $dir) {
                $path = $projectDir . '/' . $dir;
                if ($system->filesystem()->directoryExists($path)) {
                    $system->exec(['sudo', 'chown', '-R', $chown, $path], [], 60);
                }
            }
        }
        $logger?->ok($isNginx ? 'Static assets compiled' : 'Application compiled');
    }

    /**
     * PHP apps with a package.json build script: resolve
     * vendor/ with Composer on the host, then run Vite/webpack there so the
     * inner DinD build skips the heavy Node stage. A successful project-owned
     * build command is the contract; output paths are intentionally not tied to
     * Laravel Vite, Mix, Symfony Encore, or another framework.
     *
     * @param array<string, true> $files
     */
    public function runForPhp(string $projectDir, array $files): bool
    {
        $packageJson = $this->project->projectTree()->readIn($projectDir, 'package.json');
        if ($packageJson === null) {
            return false;
        }
        $package = json_decode($packageJson, true);
        if (!is_array($package)) {
            return false;
        }
        $scripts = $package['scripts'] ?? null;
        if (!is_array($scripts) || !isset($scripts['build']) || !is_string($scripts['build']) || $scripts['build'] === '') {
            return false;
        }

        $logger = $this->project->shell()->logger();
        $logger?->info('Compiling frontend assets on host');
        $system = $this->project->system();
        $cached = $this->prepareCache();

        // Only when nobody has resolved it yet. The PHP strategy runs its own
        // composer pass first, in the account's runtime image; repeating it
        // here in the composer image would resolve the same tree twice, the
        // second time against the wrong PHP with every platform requirement
        // ignored.
        //
        // Only when there is something to resolve: a project can carry a
        // package.json build script beside PHP code and still ship no
        // composer.json at all — a Vue SPA with plain PHP endpoints is the
        // shape. Composer in the `composer:2` image then stops before it
        // starts ("Composer could not find a composer.json file in /app") and
        // kills a build that needed nothing from it. A project that really has
        // Composer dependencies has the manifest, and the manifest is the
        // only thing composer can work from; running it against nothing is
        // not a fallback.
        if (!$this->project->system()->filesystem()->fileExists($projectDir . '/vendor/autoload.php')
            && $this->project->projectTree()->readIn($projectDir, 'composer.json') !== null
        ) {
            $this->runComposer();
        }

        $pm = JsPackageManager::detectPackageManager($files, $package);
        $install = JsPackageManager::installCommand($pm, $files, $package, $projectDir);
        $build = JsPackageManager::scriptCommand($pm, 'build');
        $nodeImage = Images::nodeImage($projectDir, $package);
        $image = HostNodeBuild::compilerImage($install, $nodeImage, $pm);
        $installCmd = $install;
        $buildCmd = $build;
        if ($image === HostNodeBuild::BUN_IMAGE) {
            $installCmd = HostNodeBuild::bunInstallCommand($install);
            $buildCmd = HostNodeBuild::bunBuildCommand($build);
        }
        $recipeEnv = ['NODE_ENV' => 'development'];

        try {
            $this->runContainer($projectDir, $image, $installCmd, $buildCmd, $recipeEnv, $cached);
        } catch (\Exception $e) {
            if ($image === $nodeImage) {
                throw $e;
            }
            $logger?->info('Bun compile failed, retrying with Node: ' . $e->getMessage());
            // Same bun-flavoured-command problem as run() above.
            $this->runContainer(
                $projectDir,
                $nodeImage,
                HostNodeBuild::nodeInstallCommand($install),
                HostNodeBuild::nodeBuildCommand($build),
                $recipeEnv,
                $cached
            );
        }

        $logger?->ok('Frontend assets compiled on host');

        return true;
    }

    private function projectPackageManager(string $projectDir): string
    {
        $package = [];
        $raw = $this->project->projectTree()->readIn($projectDir, 'package.json');
        if ($raw !== null) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $package = $decoded;
            }
        }

        return JsPackageManager::detectPackageManager(
            ProjectContext::listRootFiles($projectDir),
            $package
        );
    }

    private function assertBuildProduced(
        string $projectDir,
        string $output,
        bool $isNitro,
        bool $isMounted = false,
        bool $isNode = true
    ): void {
        $system = $this->project->system();
        if ($isMounted) {
            // node_modules, not the output directory. The app is started by
            // its own package.json script against the mounted project, and
            // that cannot resolve a single import without this — whereas the
            // output directory is not a reliable invariant: express and
            // fastify declare `dist` but their build step is optional, so a
            // project with no build script legitimately produces none.
            //
            // A build that *failed* has already thrown: runContainer() raises
            // on a non-zero exit. What this catches is the other case — a
            // build that reported success and left nothing runnable behind.
            if ($isNode && !$system->filesystem()->directoryExists($projectDir . '/node_modules')) {
                throw new \Exception('Install finished but node_modules is missing');
            }

            return;
        }
        if ($isNitro) {
            $entry = StandaloneNodeServe::firstEntry($projectDir, static function (string $path) use ($system): bool {
                return $system->filesystem()->fileExists($path);
            });
            if ($entry === null) {
                throw new \Exception(StandaloneNodeServe::missingEntryMessage());
            }

            return;
        }

        $index = $projectDir . '/' . $output . '/index.html';
        if (!$system->filesystem()->fileExists($index)) {
            throw new \Exception('Static build finished but ' . $output . '/index.html is missing');
        }
    }

    /**
     * The image a command-runtime host compile runs in.
     *
     * The recipe's own, except for Ruby: its gems are frequently native, and
     * the image that can compile them is the PanelAlpha base carrying the apt
     * packages -- the same one the container will run. Compiling in the stock
     * slim image and running in the base would build extensions against
     * headers the compile did not have.
     *
     * @param array<string, mixed> $decision
     */
    private function commandRuntimeImage(array $decision, string $projectDir): string
    {
        $declared = trim((string) ($decision['image'] ?? ''));

        if (($decision['strategy'] ?? null) === Strategies::RUBY) {
            $app = RubyApp::at($projectDir, ProjectContext::listRootFiles($projectDir));
            $base = $this->project->innerDocker()->ensureRubyBaseImage(
                RubyRuntime::imageFor($app->project),
                SystemPackages::for($app->gemfile())
            );

            return $base ?: $declared;
        }

        // Python gets the same treatment through the same helper
        // FrameworkStrategy uses when it writes the compose file. It has to be
        // the same answer in both places: the venv is built here and run
        // there, and a compiled extension linked against headers the runtime
        // does not have fails at import rather than at install.
        //
        // A no-op for Go, Rust and Java -- their images are not python tags,
        // so the swap declines and the declared image passes through.
        return PythonBase::imageFor($this->project, $projectDir, $declared);
    }

    /**
     * The PHP minor the deployed app will run on, so the host install
     * resolves for that rather than for the Composer image's own PHP.
     *
     * `$appRoot` is where the application's own composer.json lives, for the
     * manifests that declare one — phpBB is the repository root plus a
     * `phpBB/` directory that is the application. Reading the repository root
     * instead resolves the wrong file, and for such a project no version at
     * all, which is why every other composer question here is asked in the
     * same subtree ({@see runtimeComposerManifest()}, {@see lockPhpContradicted()}).
     */
    private function targetPhpMinor(string $appRoot = ''): ?string
    {
        // readIn(), not read(): the latter takes an absolute path, and
        // handing it a bare name silently answers null - which here would
        // mean falling back to an unpinned resolve without saying so.
        $projectDir = $this->project->engineAccount()->projectDir();
        $files = $this->project->projectTree();
        $prefix = trim($appRoot, '/') === '' ? '' : trim($appRoot, '/') . '/';
        $composerJson = $files->readIn($projectDir, $prefix . 'composer.json');
        if ($composerJson === null) {
            return null;
        }

        return PhpRuntime::requirementFor(
            $composerJson,
            $files->readIn($projectDir, $prefix . 'composer.lock')
        )->version;
    }

    /**
     * The project's composer.lock, or null when it ships none.
     *
     * Read from the same subtree as the manifest, like every other composer
     * question asked here, so an application in its own subtree resolves its
     * own lock rather than one at the repository root.
     */
    private function projectComposerLock(string $appRoot = ''): ?string
    {
        $projectDir = $this->project->engineAccount()->projectDir();
        $prefix = trim($appRoot, '/') === '' ? '' : trim($appRoot, '/') . '/';

        return $this->project->projectTree()->readIn($projectDir, $prefix . 'composer.lock');
    }

    /**
     * Whether the project's lock pins a PHP its own packages reject.
     *
     * Without a composer.json there is no install to relax: a project with a
     * lock and no manifest is not one Composer can install from at all.
     */
    private function lockPhpContradicted(string $appRoot = ''): bool
    {
        $projectDir = $this->project->engineAccount()->projectDir();
        $prefix = trim($appRoot, '/') === '' ? '' : trim($appRoot, '/') . '/';
        if ($this->project->projectTree()->readIn($projectDir, $prefix . 'composer.json') === null) {
            return false;
        }

        return PhpRuntime::lockedPhpContradicted($this->projectComposerLock($appRoot));
    }

    /**
     * The PHP project's own build, on the host, in the image it will be
     * served from.
     *
     * Runs for every PHP deploy, not only the ones with a frontend: this is
     * where `composer install` lives now that there is no per-project image
     * to hold it. The `vendor/` it writes lands in ~/project, which is the
     * mount the container serves, so the result is live without a restart.
     *
     * Throws on failure, and that is deliberate. The old arrangement failed a
     * bad `composer install` as a failed image build, which stopped the
     * deploy; keeping that means an application whose dependencies do not
     * resolve is reported as broken rather than started with no vendor/ and
     * left to 500 on its first request.
     *
     * @param array<string, mixed> $decision the matched manifest's, whose
     *        `install_command` and `build_command` are its build-stage
     *        commands split by role
     */
    public function runPhpBuild(
        string $image,
        array $decision,
        string $appRoot = '',
        bool $hasComposer = false
    ): void {
        $script = PhpHostBuild::script(
            is_string($decision['install_command'] ?? null) ? $decision['install_command'] : '',
            is_string($decision['build_command'] ?? null) ? $decision['build_command'] : '',
            $hasComposer,
            // The engine's PHP minor, so this resolve answers the same
            // question the build image was chosen for. Without it a project
            // pinning an older `config.platform` won over the deployed
            // interpreter -- YOURLS, and htmly, are how that was found.
            $this->targetPhpMinor($appRoot),
            // A lock whose own packages reject the PHP it pins cannot be
            // installed strictly, and `install` may not rewrite it. egroupware
            // is the case: its CI locks under --ignore-platform-reqs, so the
            // engine stops enforcing the one requirement the lock contradicts
            // instead of failing a deploy that would have worked.
            $this->lockPhpContradicted($appRoot),
            // Read for one decision: whether the plugins this lock pins are
            // installers, which may run, or an application's build tooling,
            // which may not. {@see PhpHostBuild::mayRunPlugins()}
            $this->projectComposerLock($appRoot)
        );
        if ($script === '') {
            return;
        }

        $account = $this->project->engineAccount();
        $shell = $this->project->shell();
        $logger = $shell->logger();
        $system = $this->project->system();

        // Best-effort. A cache is an optimisation, and a host that will not
        // let the engine create one is no reason to refuse the deploy -- but
        // the mount has to go with it, because docker would otherwise create
        // the missing directory as root and composer, running as the account,
        // would stop on a permission error.
        $withCache = $this->prepareCache();
        $logger?->info('Resolving PHP dependencies on host');

        // Written before the argv, so a Composer step that is dropped from
        // the manifest (an app with no dependencies) does not leave a stale
        // runtime manifest behind for the next deploy to read.
        $manifest = $this->runtimeComposerManifest($account->projectDir(), $appRoot);

        $argv = $this->hostBuilder()->phpBuildArgv($account, $image, $script, $appRoot, $withCache, $manifest);
        if ($logger === null) {
            $process = $system->runProcess($argv, [], self::PHP_BUILD_TIMEOUT_SECONDS);
        } else {
            $logger->throwIfCancelled();
            $process = $shell->streamProcess($argv, [], self::PHP_BUILD_TIMEOUT_SECONDS, $logger);
            $logger->throwIfCancelled();
        }

        if (!$process->isSuccessful()) {
            $message = $process->getErrorOutput() ?: $process->getOutput();
            throw new \Exception($message !== '' ? $message : 'Host PHP build failed');
        }

        $logger?->ok('PHP dependencies resolved on host');
    }

    /**
     * Create the account's host-side caches, and say whether it worked.
     *
     * Best-effort, because a cache is an optimisation and a host that will not
     * let the engine create one is no reason to refuse the deploy. The answer
     * matters to the caller though: with no cache directory the node_modules
     * mount has to go too, or docker creates the missing path as root and the
     * build -- which runs as the account -- cannot write into it.
     */
    private function prepareCache(): bool
    {
        try {
            $this->project->system()->exec(
                $this->hostBuilder()->prepareCacheArgv($this->project->engineAccount()),
                [],
                30
            );

            return true;
        } catch (\Exception $e) {
            $this->project->shell()->logger()?->info(
                'Host build cache unavailable, compiling without it: ' . $e->getMessage()
            );

            return false;
        }
    }

    private function runComposer(): void
    {
        $shell = $this->project->shell();
        $logger = $shell->logger();
        $account = $this->project->engineAccount();
        $argv = $this->hostBuilder()->composerInstallArgv(
            $account,
            $this->targetPhpMinor(),
            // The same decision runPhpBuild() made, so the two Composer
            // passes never resolve against different manifests for the same
            // deploy.
            $this->runtimeComposerManifest($account->projectDir())
        );

        if ($logger === null) {
            $process = $this->project->system()->runProcess($argv, [], 1800);
        } else {
            $logger->throwIfCancelled();
            $process = $shell->streamProcess($argv, [], 1800, $logger);
            $logger->throwIfCancelled();
        }

        if (!$process->isSuccessful()) {
            $message = $process->getErrorOutput() ?: $process->getOutput();
            throw new \Exception($message !== '' ? $message : 'Host Composer install failed');
        }
    }

    /**
     * Write the manifest Composer should resolve from, and return its name,
     * or null when the project's own composer.json is fine as-is.
     *
     * Two cases write one: a project with no lock and a require-dev to drop
     * (the manifest is the fix itself), and a locked project that also needs
     * a platform pin (the manifest is an untouched copy of composer.json,
     * only so the pin has somewhere to land other than the client's file).
     * {@see PhpHostBuild::runtimeManifest()} decides which, if either.
     *
     * The lock is copied beside it under the matching engine name in the
     * second case, refreshed on every call: Composer finds a lock by the name
     * of the manifest it was handed, so without a copy filed under the
     * runtime manifest's name it would see no lock at all and resolve the
     * whole graph remotely instead of installing the one the project
     * committed.
     *
     * Best-effort. A project whose manifest cannot be written -- or that
     * needs none of this -- resolves from its own composer.json, exactly as
     * before.
     */
    private function runtimeComposerManifest(string $projectDir, string $appRoot = ''): ?string
    {
        // Beside the composer.json the build will actually read. For a
        // project whose application is a subtree the build runs in that
        // subtree (`-w /app/<root>`), not at the mount root, so the manifest
        // has to be its sibling and the returned name is relative to it.
        $appDir = rtrim($projectDir . '/' . trim($appRoot, '/'), '/');
        $files = $this->project->projectTree();

        $composerJson = $files->readIn($appDir, 'composer.json');
        if ($composerJson === null) {
            return null;
        }

        $composerLock = $files->readIn($appDir, 'composer.lock');
        $runtime = PhpHostBuild::runtimeManifest($composerJson, $composerLock, $this->targetPhpMinor($appRoot));
        if ($runtime === null) {
            return null;
        }

        $filesystem = $this->project->system()->filesystem();
        $chown = $this->project->userModel()->getChownString();
        try {
            $filesystem->filePutContents(
                $appDir . '/' . PhpHostBuild::RUNTIME_MANIFEST_FILE,
                $runtime,
                $chown,
                '644'
            );
            if ($composerLock !== null && trim($composerLock) !== '') {
                $filesystem->filePutContents(
                    $appDir . '/' . PhpHostBuild::RUNTIME_LOCK_FILE,
                    $composerLock,
                    $chown,
                    '644'
                );
            }
        } catch (\Exception $e) {
            $this->project->shell()->logger()?->warn(
                'Could not write a runtime-only Composer manifest, resolving from composer.json: '
                    . $e->getMessage()
            );

            return null;
        }

        return PhpHostBuild::RUNTIME_MANIFEST_FILE;
    }

    /**
     * @param array<string, mixed> $env
     */
    private function runContainer(
        string $projectDir,
        string $image,
        string $install,
        string $build,
        array $env = [],
        bool $isolateNodeModules = true,
        bool $isNode = true
    ): void {
        $shell = $this->project->shell();
        $logger = $shell->logger();
        $account = $this->project->engineAccount();
        if ($projectDir !== $account->projectDir()) {
            throw new \InvalidArgumentException('Refusing host build outside the account project directory');
        }
        $argv = $this->hostBuilder()->nodeBuildArgv(
            $account,
            $image,
            $install,
            $build,
            $env,
            $isolateNodeModules,
            $isNode
        );

        if ($logger === null) {
            $process = $this->project->system()->runProcess($argv, [], 3600);
        } else {
            $logger->throwIfCancelled();
            $process = $shell->streamProcess($argv, [], 3600, $logger);
            $logger->throwIfCancelled();
        }

        if (!$process->isSuccessful()) {
            $message = $process->getErrorOutput() ?: $process->getOutput();
            throw new \Exception($message !== '' ? $message : 'Host static asset compile failed');
        }
    }
}
