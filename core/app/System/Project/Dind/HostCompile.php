<?php

namespace App\System\Project\Dind;

use App\Exceptions\DeployCancelledException;
use App\System\Project\Dind\Strategy\PythonBase;
use App\Lib\Deploy\Dind\BuildNetwork;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\HostRunProject;
use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Deploy\Platform\Runtime\Ruby\SystemPackages;
use App\Lib\Deploy\Platform\Runtime\RubyRuntime;
use App\Lib\Deploy\Platform\Runtime\RustRuntime;
use App\Lib\Deploy\Platform\Runtime\RustRuntimeLibraries;
use App\Lib\Deploy\Platform\Runtime\Ruby\RubyApp;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\JavaNodeTooling;
use App\Lib\Deploy\Platform\Runtime\PhpRuntime;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\System\Project\Dind as DindProject;
use App\Lib\Deploy\Compose\AppRoot;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\DeployLog\FailureOutput;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Lib\Deploy\Engine\HostBuilder;
use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use App\Lib\Deploy\Platform\Runtime\Php\PhpHostBuild;
use App\Lib\Deploy\Platform\Runtime\StandaloneNodeServe;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Dind\HostBuildSlot;
use App\Lib\Deploy\Dind\DindEngine;
use App\Lib\Deploy\Engine\BuildMemory;
use Symfony\Component\Process\Process;


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

    /** The deploy and figure last announced, so each is said once per deploy. */
    private ?string $memoryAnnouncedIn = null;

    public function __construct(DindProject $project)
    {
        $this->project = $project;
    }

    private function hostBuilder(): HostBuilder
    {
        $builder = $this->project->engine()->hostBuilder();

        return $this->buildNetworkUnavailable && $builder instanceof DindHostBuilder
            ? $builder->withoutNetwork()
            : $builder;
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
        // A host-run project is mounted from its app_root, so it is built
        // there: its package.json, lockfile and node_modules are the ones the
        // container sees at /app.
        $appRoot = $isMounted ? AppRoot::relative($decision) : '';
        $appDir = self::mountedAppDir($projectDir, $appRoot);
        $nodeImage = Images::nodeImage($appDir);
        // A command runtime compiles in its own language image -- the one the
        // recipe resolved and the one the container will run, so a venv built
        // here works there and a binary linked here runs there.
        if (!$isNode) {
            $image = $this->commandRuntimeImage($decision, $projectDir) ?: $nodeImage;
        } elseif ($isNitro || $isMounted) {
            $image = HostNodeBuild::runtimeImage($this->projectPackageManager($appDir), $nodeImage);
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
            $this->runContainer($projectDir, $image, $installCmd, $buildCmd, $recipeEnv, $isolateNodeModules && $cached, $isNode, $appRoot);
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

        if (($decision['strategy'] ?? null) === Strategies::RUST) {
            $this->bundleRustRuntimeLibraries($projectDir, $image, trim((string) ($decision['image'] ?? '')));
        }

        // The static strategy mounts the checkout itself, so that is where an
        // index has to be.
        $output = ($decision['strategy'] ?? null) === Strategies::STATIC
            ? '.'
            : NodeRuntime::safeOutputDir($decision['output_directory'] ?? null, $isNitro ? '.output' : 'dist');
        $this->assertBuildProduced($appDir, $output, $isNitro, $isMounted, $isNode);

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
                $path = $appDir . '/' . $dir;
                if ($system->filesystem()->directoryExists($path)) {
                    $system->exec(['sudo', 'chown', '-R', $chown, $path], [], 60);
                }
            }
        }
        $logger?->ok($isNginx ? 'Static assets compiled' : 'Application compiled');
    }

    /**
     * The directory a host-run project is built in. Refused when it resolves
     * outside the checkout: the chown after the compile runs on the host and
     * would follow a symlinked app_root anywhere.
     */
    private static function mountedAppDir(string $projectDir, string $appRoot): string
    {
        if ($appRoot === '') {
            return $projectDir;
        }
        $dir = rtrim($projectDir, '/') . '/' . $appRoot;
        $real = realpath($dir);
        $base = realpath($projectDir);
        if ($real === false || $base === false || !is_dir($real) || !str_starts_with($real, rtrim($base, '/') . '/')) {
            throw new \Exception("app_root '{$appRoot}' is not a directory inside the project");
        }

        return $dir;
    }

    /**
     * PHP apps with a package.json build script: resolve
     * vendor/ with Composer on the host, then run Vite/webpack there so the
     * inner DinD build skips the heavy Node stage. A successful project-owned
     * build command is the contract; output paths are intentionally not tied to
     * Laravel Vite, Mix, Symfony Encore, or another framework.
     *
     * `$appRoot` is the manifest's application subtree: its composer.json and
     * vendor/ are the application's, and so is its package.json when that
     * has a build script -- the build then runs there. Otherwise the
     * checkout root's package.json is used, as before: the Group Office
     * recipe drives upstream's build from one there.
     *
     * `$frontendBuild` is the manifest's `frontend_build`: false skips the
     * pass, a command replaces the `build` script and runs even when
     * package.json declares none, null keeps the rule above.
     *
     * @param array<string, true> $files
     */
    public function runForPhp(
        string $projectDir,
        array $files,
        string $appRoot = '',
        string|false|null $frontendBuild = null,
        ?string $phpImage = null
    ): bool {
        $appRoot = AppRoot::relative(['app_root' => $appRoot]);
        $prefix = $appRoot === '' ? '' : $appRoot . '/';
        $appDir = $appRoot === '' ? $projectDir : rtrim($projectDir, '/') . '/' . $appRoot;
        $logger = $this->project->shell()->logger();
        if ($frontendBuild === false) {
            $logger?->info('Frontend build turned off by the recipe (frontend_build: false)');

            return false;
        }
        $declared = is_string($frontendBuild) && trim($frontendBuild) !== '' ? trim($frontendBuild) : null;

        $package = null;
        $buildRoot = '';
        foreach (array_unique([$appRoot, '']) as $candidate) {
            $package = $declared === null
                ? $this->packageWithBuild($projectDir, $candidate)
                : $this->packageJson($projectDir, $candidate);
            if ($package !== null) {
                $buildRoot = $candidate;
                break;
            }
        }
        if ($package === null) {
            if ($declared !== null) {
                throw new \Exception('The recipe sets frontend_build, but the project has no package.json to run it against');
            }

            return false;
        }
        $buildDir = $buildRoot === '' ? $projectDir : $appDir;
        if ($buildRoot !== '') {
            $files = ProjectContext::listRootFiles($buildDir);
        }

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
        //
        // The autoloader is looked for where composer.json says it goes:
        // grocy sets `vendor-dir: packages`, and probing vendor/ reran a
        // second install that found "Nothing to install" on every deploy.
        $composerJson = $this->project->projectTree()->readIn($projectDir, $prefix . 'composer.json');
        if ($composerJson !== null
            && !$this->project->system()->filesystem()->fileExists(
                $appDir . '/' . self::vendorDir($composerJson) . '/autoload.php'
            )
        ) {
            $this->runComposer($appRoot);
        }

        $pm = JsPackageManager::detectPackageManager($files, $package);
        $install = JsPackageManager::installCommand($pm, $files, $package, $buildDir);
        // The install stays the engine's (cache, lockfile, git image); only
        // the build step is the recipe's. It runs on a cache hit too.
        $build = $declared ?? JsPackageManager::scriptCommand($pm, 'build');
        $nodeImage = Images::nodeImage($buildDir, $package);
        $image = HostNodeBuild::compilerImage($install, $nodeImage, $pm);
        // A script that calls composer or php (selfoss's postinstall) exits
        // 127 in a Node image, so the build runs in the PHP runtime with Node
        // copied in.
        if ($image === $nodeImage && $phpImage !== null && $phpImage !== ''
            && JsPackageManager::scriptsCallPhp($package, $build)
        ) {
            $image = $this->project->innerDocker()->bases()->ensureNodeBuild($phpImage, $nodeImage) ?? $image;
        }
        $installCmd = $install;
        $buildCmd = $build;
        if ($image === HostNodeBuild::BUN_IMAGE) {
            $installCmd = HostNodeBuild::bunInstallCommand($install);
            $buildCmd = HostNodeBuild::bunBuildCommand($build);
        }
        $recipeEnv = ['NODE_ENV' => 'development'];

        try {
            $this->runContainer($projectDir, $image, $installCmd, $buildCmd, $recipeEnv, $cached, true, $buildRoot);
        } catch (\Exception $e) {
            if ($image !== HostNodeBuild::BUN_IMAGE) {
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
                $cached,
                true,
                $buildRoot
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
     * And Rust, which compiles in the full image and runs in slim: slim has no
     * g++, make, pkg-config or OpenSSL headers, and the account cannot install
     * them. A library the binary links that slim lacks is bundled afterwards
     * by {@see bundleRustRuntimeLibraries()}.
     *
     * @param array<string, mixed> $decision
     */
    private function commandRuntimeImage(array $decision, string $projectDir): string
    {
        $declared = trim((string) ($decision['image'] ?? ''));

        if (($decision['strategy'] ?? null) === Strategies::RUST) {
            return RustRuntime::compileImage($declared);
        }

        if (($decision['strategy'] ?? null) === Strategies::RUBY) {
            $app = RubyApp::at($projectDir, ProjectContext::listRootFiles($projectDir));
            $base = $this->project->innerDocker()->ensureRubyBaseImage(
                RubyRuntime::imageFor($app->project),
                SystemPackages::for($app->gemfile())
            );

            return $base ?: $declared;
        }

        // A Java build that runs npm (Tolgee's Gradle scripts) compiles in the
        // same image with Node copied in; the container still runs $declared.
        if (($decision['strategy'] ?? null) === Strategies::JAVA && $declared !== '') {
            $node = JavaNodeTooling::nodeImageFor($projectDir);
            if ($node !== null) {
                return $this->project->innerDocker()->bases()->ensureNodeBuild($declared, $node) ?? $declared;
            }

            return $declared;
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
     * Rust compiles in the build image and runs in the recipe's slim one, so
     * a binary can link a library only the build image has: focus_flow_cloud's
     * libpq. Checked in the runtime image; only a gap costs the other two
     * containers. {@see RustRuntimeLibraries}
     */
    private function bundleRustRuntimeLibraries(string $projectDir, string $compileImage, string $runtimeImage): void
    {
        if ($runtimeImage === '' || $runtimeImage === $compileImage) {
            return;
        }

        try {
            $this->runContainer($projectDir, $runtimeImage, '', RustRuntimeLibraries::checkScript(), [], false, false);
        } catch (DeployCancelledException $e) {
            throw $e;
        } catch (\Exception $e) {
            // A runtime image that cannot even run the check starts as it did before.
            $this->project->shell()->logger()?->info(
                "Could not check the Rust binary against {$runtimeImage}: " . $e->getMessage()
            );

            return;
        }
        if (!$this->project->system()->filesystem()->fileExists($projectDir . '/' . RustRuntimeLibraries::MISSING_FILE)) {
            return;
        }

        $this->project->shell()->logger()?->info(
            "Bundling the libraries {$runtimeImage} lacks from {$compileImage} into " . RustRuntimeLibraries::DIR
        );
        $this->runContainer($projectDir, $compileImage, '', RustRuntimeLibraries::bundleScript(), [], false, false);
        $this->runContainer($projectDir, $runtimeImage, '', RustRuntimeLibraries::verifyScript(), [], false, false);
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

    /** The project's composer.json, from the same subtree as its lock. */
    private function projectComposerJson(string $appRoot = ''): ?string
    {
        $projectDir = $this->project->engineAccount()->projectDir();
        $prefix = trim($appRoot, '/') === '' ? '' : trim($appRoot, '/') . '/';

        return $this->project->projectTree()->readIn($projectDir, $prefix . 'composer.json');
    }

    /** {@see PhpHostBuild::mirrorsRuntimeLock()}, for the manifest's subtree. */
    private function mirrorsRuntimeLock(string $appRoot = ''): bool
    {
        $composerJson = $this->projectComposerJson($appRoot);

        return $composerJson !== null
            && PhpHostBuild::mirrorsRuntimeLock($composerJson, $this->projectComposerLock($appRoot));
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
        $install = is_string($decision['install_command'] ?? null) ? $decision['install_command'] : '';
        $build = is_string($decision['build_command'] ?? null) ? $decision['build_command'] : '';
        if (PhpHostBuild::script($install, $build, $hasComposer) === '') {
            return;
        }

        $account = $this->project->engineAccount();

        // Before the script: plugins are allowed only when Composer will read
        // this manifest, whose allow-plugins names the installers and refuses
        // the rest. Null (no composer.json, or not writable) keeps --no-plugins.
        $manifest = $this->runtimeComposerManifest($account->projectDir(), $appRoot);

        $script = PhpHostBuild::script(
            $install,
            $build,
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
            $manifest !== null,
            $manifest !== null && $this->mirrorsRuntimeLock($appRoot),
            // For the classmap directories the checkout lacks, which the
            // autoload dump would otherwise die on. {@see PhpHostBuild::classmapDirsStep()}
            $this->projectComposerJson($appRoot)
        );

        $logger = $this->project->shell()->logger();

        // Best-effort. A cache is an optimisation, and a host that will not
        // let the engine create one is no reason to refuse the deploy -- but
        // the mount has to go with it, because docker would otherwise create
        // the missing directory as root and composer, running as the account,
        // would stop on a permission error.
        $withCache = $this->prepareCache();
        $logger?->info('Resolving PHP dependencies on host');

        $process = $this->runHostBuild(
            static fn (HostBuilder $builder): array
                => $builder->phpBuildArgv($account, $image, $script, $appRoot, $withCache, $manifest),
            self::PHP_BUILD_TIMEOUT_SECONDS
        );

        if (!$process->isSuccessful()) {
            throw $this->hostBuildFailure($process, 'Host PHP build failed');
        }

        $logger?->ok('PHP dependencies resolved on host');
    }

    /**
     * Set when the build network could not be made; see prepareBuildNetwork().
     * A flag, not a builder: the builder differs by the project's memory limit.
     */
    private bool $buildNetworkUnavailable = false;

    /**
     * Make sure the build network exists and its firewall is applied (engine#246).
     *
     * Both fail open, to what a build had before this network existed: a
     * network that cannot be made -- on a CSF host `docker network create`
     * fails once CSF has flushed Docker's chains, which is why the installers
     * make it right after restarting Docker -- sends this build to the default
     * bridge, and a firewall that cannot be applied leaves the network
     * unfiltered. Either is a warning in the deploy log, never a failed deploy.
     */
    private function prepareBuildNetwork(): void
    {
        $builder = $this->hostBuilder();
        $network = $builder instanceof DindHostBuilder ? $builder->network() : null;
        if ($network === null) {
            return;
        }

        $system = $this->project->system();
        $logger = $this->project->shell()->logger();
        if (!$this->buildNetworkExists($network)) {
            try {
                $system->exec(BuildNetwork::createArgv($network), [], 60);
            } catch (\Exception $e) {
                // Another build may have made it in the meantime.
                if (!$this->buildNetworkExists($network)) {
                    $this->buildNetworkUnavailable = true;
                    $logger?->warn(
                        "Host build network {$network} could not be created, so this build runs on Docker's "
                            . 'default bridge and can reach the host and its private network: ' . trim($e->getMessage())
                    );

                    return;
                }
            }
        }

        try {
            $system->exec(BuildNetwork::firewallArgv($network), [], 60);
            $logger?->info("Host build network: {$network} (internet only)");
        } catch (\Exception $e) {
            $logger?->warn(
                "Host build network {$network} has no firewall, so this build can reach private addresses: "
                    . trim($e->getMessage())
            );
        }
    }

    private function buildNetworkExists(string $network): bool
    {
        try {
            $this->project->system()->exec(BuildNetwork::inspectArgv($network), [], 30);

            return true;
        } catch (\Exception) {
            return false;
        }
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
        // Every host build path starts here, so this is also where the build
        // network is made ready.
        $this->prepareBuildNetwork();

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

    /**
     * The package.json in `$root` (relative to the checkout), decoded, when it
     * has a non-empty build script; null otherwise.
     *
     * @return ?array<string, mixed>
     */
    private function packageWithBuild(string $projectDir, string $root): ?array
    {
        $package = $this->packageJson($projectDir, $root);
        if ($package === null) {
            return null;
        }
        $build = is_array($package['scripts'] ?? null) ? ($package['scripts']['build'] ?? null) : null;

        return is_string($build) && $build !== '' ? $package : null;
    }

    /**
     * The package.json in `$root` (relative to the checkout), decoded; null
     * when there is none or it is not a JSON object.
     *
     * @return ?array<string, mixed>
     */
    private function packageJson(string $projectDir, string $root): ?array
    {
        $raw = $this->project->projectTree()->readIn($projectDir, ($root === '' ? '' : $root . '/') . 'package.json');
        $package = $raw === null ? null : json_decode($raw, true);

        return is_array($package) ? $package : null;
    }

    /** Composer's `config.vendor-dir`, or `vendor` for anything that could leave the project. */
    private static function vendorDir(string $composerJson): string
    {
        $decoded = json_decode($composerJson, true);
        $dir = is_array($decoded) && is_array($decoded['config'] ?? null) ? ($decoded['config']['vendor-dir'] ?? null) : null;
        $dir = is_string($dir) ? rtrim($dir, '/') : '';

        return AppRoot::relative(['app_root' => $dir]) ?: 'vendor';
    }

    private function runComposer(string $appRoot = ''): void
    {
        $account = $this->project->engineAccount();
        $phpMinor = $this->targetPhpMinor($appRoot);
        // The same decision runPhpBuild() made, so the two Composer passes
        // never resolve against different manifests for the same deploy.
        $manifest = $this->runtimeComposerManifest($account->projectDir(), $appRoot);

        $process = $this->runHostBuild(
            static fn (HostBuilder $builder): array
                => $builder->composerInstallArgv($account, $phpMinor, $manifest, $appRoot),
            1800
        );

        if (!$process->isSuccessful()) {
            throw $this->hostBuildFailure($process, 'Host Composer install failed');
        }
    }

    /**
     * Write the manifest Composer should resolve from, and return its name,
     * or null when there is no composer.json to derive it from.
     *
     * Written for every project with a composer.json: it carries the engine's
     * allow-plugins, which is what lets the host build drop `--no-plugins`
     * ({@see PhpHostBuild::runtimeManifest()}), plus the platform pin and,
     * without a lock, the require-dev drop.
     *
     * The lock, when there is one, is copied beside it under the matching
     * engine name, refreshed on every call: Composer finds a lock by the name
     * of the manifest it was handed, so without the copy it would see no lock
     * at all and resolve the whole graph remotely instead of installing the
     * one the project committed.
     *
     * Best-effort. A project whose manifest cannot be written resolves from
     * its own composer.json with `--no-plugins`, exactly as before.
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
        $runtime = PhpHostBuild::runtimeManifest($composerJson, $composerLock);
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
        bool $isNode = true,
        string $appRoot = ''
    ): void {
        $account = $this->project->engineAccount();
        if ($projectDir !== $account->projectDir()) {
            throw new \InvalidArgumentException('Refusing host build outside the account project directory');
        }
        // The isolated node_modules is a bind mount, and Yarn PnP's link step
        // deletes any node_modules it finds: rmdir gets EBUSY and the install
        // aborts. A compiled frontend does not depend on the linker.
        if ($isolateNodeModules && $isNode && !array_key_exists('YARN_NODE_LINKER', $env)
            && $this->isYarnPnp($projectDir, $appRoot)
        ) {
            $env['YARN_NODE_LINKER'] = 'node-modules';
        }
        $process = $this->runHostBuild(
            static fn (HostBuilder $builder): array => $builder->nodeBuildArgv(
                $account,
                $image,
                $install,
                $build,
                $env,
                $isolateNodeModules,
                $isNode,
                $appRoot
            ),
            3600
        );

        if (!$process->isSuccessful()) {
            throw $this->hostBuildFailure($process, 'Host static asset compile failed');
        }
    }

    private function isYarnPnp(string $projectDir, string $appRoot): bool
    {
        $prefix = trim($appRoot, '/') === '' ? '' : trim($appRoot, '/') . '/';
        $tree = $this->project->projectTree();
        $package = json_decode((string) $tree->readIn($projectDir, $prefix . 'package.json'), true);

        return JsPackageManager::isYarnPnp(
            is_array($package) ? $package : [],
            $tree->readIn($projectDir, $prefix . '.yarnrc.yml'),
            $tree->readIn($projectDir, $prefix . 'yarn.lock')
        );
    }

    /**
     * Why a host build container failed. An OOM kill may leave no text of its
     * own, and the output's last lines were then reported as the cause.
     */
    private function hostBuildFailure(Process $process, string $fallback): \Exception
    {
        $message = FailureOutput::fromStreams($process->getErrorOutput(), $process->getOutput());
        if ($process->getExitCode() === 137 || str_contains($process->getErrorOutput(), DindHostBuilder::OOM_REPORT)) {
            $message = 'The host build container ran out of memory at its limit of '
                . $this->hostBuilder()->memoryLimitMb() . ' MB and the kernel killed the build. That limit is set '
                . 'for the server (DEPLOY_BUILD_MEMORY), not by the project\'s memory limit.'
                . ($message === '' ? '' : "\n" . $message);
        }

        return new \Exception($message !== '' ? $message : $fallback);
    }

    /**
     * Run one host build container while holding the engine's build slot.
     * Each may take up to 8 GB, half the server's RAM, so two at once can
     * exhaust it; {@see HostBuildSlot}.
     *
     * @param callable(HostBuilder): list<string> $argvFor
     */
    private function runHostBuild(callable $argvFor, int $timeout): Process
    {
        $shell = $this->project->shell();
        $logger = $shell->logger();

        return HostBuildSlot::run(
            function () use ($logger, $shell, $argvFor, $timeout): Process {
                $builder = $this->hostBuilder();
                $this->announceBuildMemory($logger, $builder);
                $argv = $argvFor($builder);

                if ($logger === null) {
                    return $this->project->system()->runProcess($argv, [], $timeout);
                }
                $logger->throwIfCancelled();
                $process = $shell->streamProcess($argv, [], $timeout, $logger);
                $logger->throwIfCancelled();

                return $process;
            },
            static fn () => $logger?->info('Waiting for the host build slot: another deploy is building on this server')
        );
    }

    /**
     * Say what the build container gets and who sets it, once per deploy and
     * again only if the figure changes.
     */
    private function announceBuildMemory(?DeployLogger $logger, HostBuilder $builder): void
    {
        if ($logger === null) {
            return;
        }
        $key = $logger->getDeployId() . '|' . $builder->memoryLimitMb();
        if ($key === $this->memoryAnnouncedIn) {
            return;
        }
        $this->memoryAnnouncedIn = $key;
        $logger->info(self::buildMemoryLine($builder->memoryLimitMb(), $builder->memoryOrigin()));
    }

    public static function buildMemoryLine(int $memoryMb, ?BuildMemory $origin = null): string
    {
        $line = 'Host build container memory: ' . $memoryMb . ' MB, ';

        $host = $origin?->host !== null && $origin->host->totalMb > 0 ? $origin->host : null;
        $ceiling = $host === null ? '' : 'at most half the server\'s ' . $host->totalMb . ' MB and never more than its RAM less '
            . $host->engineMb . ' MB for the engine (DEPLOY_ENGINE_MEMORY)';
        if ($origin?->source === BuildMemory::HOST) {
            return $line . ($host === null
                ? 'the default, since the server\'s RAM could not be read; DEPLOY_BUILD_MEMORY replaces it'
                : DindEngine::MAX_BUILD_MEMORY_MB . ' MB by default, ' . $ceiling
                    . '; DEPLOY_BUILD_MEMORY replaces the default, up to ' . DindEngine::buildCeilingMb($host) . ' MB');
        }
        if ($origin?->source === BuildMemory::SETTING_CAPPED) {
            return $line . 'DEPLOY_BUILD_MEMORY held to ' . $ceiling;
        }

        return $line . 'set for the server by DEPLOY_BUILD_MEMORY; the project\'s memory limit does not apply to it';
    }
}
