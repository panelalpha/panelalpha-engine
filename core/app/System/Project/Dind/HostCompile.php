<?php

namespace App\System\Project\Dind;

use App\Exceptions\DeployCancelledException;
use App\System\Project\Dind\Strategy\PythonBase;
use App\Lib\Deploy\Dind\BuildNetwork;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\GoEmbeddedFrontends;
use App\Lib\Deploy\Platform\Runtime\HostRunProject;
use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Deploy\Platform\Runtime\Ruby\SystemPackages;
use App\Lib\Deploy\Platform\Runtime\RubyRuntime;
use App\Lib\Deploy\Platform\Runtime\RustBuildTools;
use App\Lib\Deploy\Platform\Runtime\RustRuntime;
use App\Lib\Deploy\Platform\Runtime\RustRuntimeLibraries;
use App\Lib\Deploy\Platform\Runtime\Ruby\RubyApp;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\JavaNodeTooling;
use App\Lib\Deploy\Platform\Runtime\JavaRuntime;
use App\Lib\Deploy\Platform\Runtime\Requirement;
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
 * Build a project on the *host* Docker daemon instead of inside the account: a
 * throwaway container bind-mounts ~/project and writes the build output back
 * into it, for the account's DinD to serve with a stock image.
 */
class HostCompile
{
    /** Magento's cold-cache `composer install` has been measured past 20 minutes. */
    private const PHP_BUILD_TIMEOUT_SECONDS = 2400;

    private const COMPOSER_INSTALL_TIMEOUT_SECONDS = 1800;

    private const NODE_BUILD_TIMEOUT_SECONDS = 3600;

    private DindProject $project;

    /** The deploy and figure last announced, so each is said once per deploy. */
    private ?string $memoryAnnouncedIn = null;

    /**
     * Set when the build network could not be made; see prepareBuildNetwork().
     * A flag, not a builder: the builder differs by the project's memory limit.
     */
    private bool $buildNetworkUnavailable = false;

    /** @var array<string, true> public images already re-pulled on the host this deploy */
    private array $refreshedOnHost = [];

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
     * Compile a recipe whose output a stock image serves from the account's
     * directory (nginx static, standalone Nitro, a host-run project), since
     * nothing downstream builds it. A host-run project builds in its app_root.
     */
    public function run(): void
    {
        $projectDir = $this->project->userAppDirPath();
        $decision = $this->detectDecision($projectDir);
        $isNginx = ($decision['runtime'] ?? '') === PlatformManifest::RUNTIME_NGINX;
        $isNitro = DeployCompose::isStandaloneNodeOutput($decision);
        $isMounted = DeployCompose::isHostRunProject($decision);
        $install = trim((string) ($decision['install_command'] ?? ''));
        $build = trim((string) ($decision['build_command'] ?? ''));
        if ((!$isNginx && !$isNitro && !$isMounted) || ($install === '' && $build === '')) {
            return;
        }

        $logger = $this->project->shell()->logger();
        $logger?->info(
            $isNginx ? 'Compiling static assets on host' : 'Compiling application on host'
        );
        $cached = $this->prepareCache();

        $isNode = $isNginx || $isNitro || HostRunProject::isNode($decision['strategy'] ?? null);
        $shipsNodeModules = $isNitro || $isMounted;
        $appRoot = $isMounted ? AppRoot::relative($decision) : '';
        $appDir = self::mountedAppDir($projectDir, $appRoot);
        $nodeImage = Images::nodeImage($appDir);
        $image = $this->compileImage($decision, $projectDir, $appDir, $install, $nodeImage, $isNode, $shipsNodeModules);
        $mayRetryOnNode = $isNode && !$shipsNodeModules && $image !== $nodeImage;
        $this->prepareSources($decision, $projectDir, $appDir, $isNitro, $cached);
        $this->compileWithFallbacks(
            $logger,
            $decision,
            $projectDir,
            $image,
            $nodeImage,
            $install,
            $build,
            !$shipsNodeModules && $cached,
            $isNode,
            $appRoot,
            $mayRetryOnNode
        );
        if (($decision['strategy'] ?? null) === Strategies::RUST) {
            $this->bundleRustRuntimeLibraries($projectDir, $image, trim((string) ($decision['image'] ?? '')));
        }

        $output = self::outputDir($decision, $isNitro);
        $this->assertBuildProduced($appDir, $output, $isNitro, $isMounted, $isNode);
        $this->chownCompiled($appDir, $output, $isMounted, $isNitro, $isNode);
        $logger?->ok($isNginx ? 'Static assets compiled' : 'Application compiled');
    }

    /**
     * Detect again, with the request's stage overrides applied: they live on the
     * plan, and a host-compiled recipe has no Dockerfile that would run them.
     *
     * @return array<string, mixed>
     */
    private function detectDecision(string $projectDir): array
    {
        $decision = DetectProjectStrategy::detect(
            $projectDir,
            $this->project->userModel()->getGitRepo(),
            app(RecipeChoiceContext::class)->get()
        );
        $plan = app(DeployPlanContext::class)->get();

        return $plan === null ? $decision : $plan->applyToDecision($decision);
    }

    /**
     * A command runtime compiles in the image it will run in, so a venv or a
     * binary built here works there. Node compiles in its runtime image when
     * node_modules ships with the app, else in the compiler image.
     *
     * @param array<string, mixed> $decision
     */
    private function compileImage(
        array $decision,
        string $projectDir,
        string $appDir,
        string $install,
        string $nodeImage,
        bool $isNode,
        bool $shipsNodeModules
    ): string {
        if (!$isNode) {
            return $this->commandRuntimeImage($decision, $projectDir) ?: $nodeImage;
        }
        if ($shipsNodeModules) {
            return HostNodeBuild::runtimeImage($this->projectPackageManager($appDir), $nodeImage);
        }

        return HostNodeBuild::compilerImage($install, $nodeImage, $this->projectPackageManager($projectDir));
    }

    /**
     * Build the frontends a Go program embeds, and drop a Nitro `.output` the
     * last deploy moved up.
     *
     * @param array<string, mixed> $decision
     */
    private function prepareSources(array $decision, string $projectDir, string $appDir, bool $isNitro, bool $cached): void
    {
        if (($decision['strategy'] ?? null) === Strategies::GO) {
            $this->buildGoEmbeddedFrontends($projectDir, $cached);
        }
        if ($isNitro) {
            $this->clearRelocatedNitroOutput($appDir);
        }
    }

    /**
     * Compile, recompiling on a newer JDK when javac refuses the project's
     * release, and retrying a failed bun compile on Node when $mayRetryOnNode.
     * Never where node_modules ships with the app: a bun install there would
     * leave bun-only packages for the Node runtime.
     *
     * @param array<string, mixed> $decision
     */
    private function compileWithFallbacks(
        ?DeployLogger $logger,
        array $decision,
        string $projectDir,
        string $image,
        string $nodeImage,
        string $install,
        string $build,
        bool $isolateNodeModules,
        bool $isNode,
        string $appRoot,
        bool $mayRetryOnNode
    ): void {
        [$installCmd, $buildCmd] = self::commandsForImage($image, $install, $build);
        $recipeEnv = is_array($decision['env'] ?? null) ? $decision['env'] : [];
        try {
            $this->runContainer($projectDir, $image, $installCmd, $buildCmd, $recipeEnv, $isolateNodeModules, $isNode, $appRoot);
        } catch (\Exception $e) {
            $jdkImage = $e instanceof DeployCancelledException ? null : self::newerJdkImage($decision, $e->getMessage());
            if ($jdkImage !== null) {
                $this->compileOnNewerJdk($decision, $projectDir, $jdkImage, $installCmd, $buildCmd, $recipeEnv, $appRoot);
            } elseif (!$mayRetryOnNode) {
                throw $e;
            } else {
                $this->retryOnNode($logger, $e, $projectDir, $nodeImage, $install, $build, $recipeEnv, $isolateNodeModules);
            }
        }
    }

    /**
     * The install and build commands to run in $image: their bun forms in the
     * bun image.
     *
     * @return array{string, string}
     */
    private static function commandsForImage(string $image, string $install, string $build): array
    {
        if ($image !== HostNodeBuild::BUN_IMAGE) {
            return [$install, $build];
        }

        return [HostNodeBuild::bunInstallCommand($install), HostNodeBuild::bunBuildCommand($build)];
    }

    /**
     * Retry a failed bun compile in the Node image with the commands' Node
     * forms: a bun project's own `bun install` fails there with `bun: not found`.
     *
     * @param array<string, mixed> $env
     */
    private function retryOnNode(
        ?DeployLogger $logger,
        \Exception $failure,
        string $projectDir,
        string $nodeImage,
        string $install,
        string $build,
        array $env,
        bool $isolateNodeModules,
        string $appRoot = ''
    ): void {
        $logger?->info('Bun compile failed, retrying with Node: ' . $failure->getMessage());
        $this->runContainer(
            $projectDir,
            $nodeImage,
            HostNodeBuild::nodeInstallCommand($install),
            HostNodeBuild::nodeBuildCommand($build),
            $env,
            $isolateNodeModules,
            true,
            $appRoot
        );
    }

    /**
     * Where the build output must be; the static strategy mounts the checkout
     * itself, so its index is at the root.
     *
     * @param array<string, mixed> $decision
     */
    private static function outputDir(array $decision, bool $isNitro): string
    {
        return ($decision['strategy'] ?? null) === Strategies::STATIC
            ? '.'
            : NodeRuntime::safeOutputDir($decision['output_directory'] ?? null, $isNitro ? '.output' : 'dist');
    }

    /** Give the compile's output to the account; a host-run project writes to its whole mount at runtime. */
    private function chownCompiled(string $appDir, string $output, bool $isMounted, bool $isNitro, bool $isNode): void
    {
        $chown = $this->project->userModel()->getChownString();
        if (!is_string($chown) || $chown === '') {
            return;
        }
        $dirs = match (true) {
            $isMounted => $isNode ? [$output, 'node_modules'] : ['.'],
            $isNitro => array_merge(StandaloneNodeServe::volumeSources(), ['node_modules']),
            default => [$output],
        };
        $system = $this->project->system();
        foreach ($dirs as $dir) {
            $path = $appDir . '/' . $dir;
            if ($system->filesystem()->directoryExists($path)) {
                $system->exec(['sudo', 'chown', '-R', $chown, $path], [], 60);
            }
        }
    }

    /**
     * The directory a host-run project is built in. Refused when it resolves
     * outside the checkout: the host-side chown would follow a symlinked
     * app_root anywhere. The stat cache is cleared because the long-lived
     * queue worker may have cached the path before a link was swapped in.
     */
    private static function mountedAppDir(string $projectDir, string $appRoot): string
    {
        if ($appRoot === '') {
            return $projectDir;
        }
        $dir = rtrim($projectDir, '/') . '/' . $appRoot;
        clearstatcache(true);
        $real = realpath($dir);
        $base = realpath($projectDir);
        if ($real === false || $base === false || !is_dir($real) || !str_starts_with($real, rtrim($base, '/') . '/')) {
            throw new \Exception("app_root '{$appRoot}' is not a directory inside the project");
        }

        return $dir;
    }

    /**
     * PHP apps with a package.json build script: resolve Composer on the host,
     * then run the project's own build there so the inner build skips the
     * Node stage. It runs in `$appRoot` when that package.json has a build
     * script, else at the checkout root.
     *
     * @param array<string, true> $files
     * @param string|false|null $frontendBuild the manifest's `frontend_build`:
     *        false skips the pass, a command replaces the `build` script (and
     *        runs even when package.json has none), null keeps the rule above
     */
    public function runForPhp(
        string $projectDir,
        array $files,
        string $appRoot = '',
        string|false|null $frontendBuild = null,
        ?string $phpImage = null
    ): bool {
        $appRoot = AppRoot::relative(['app_root' => $appRoot]);
        $appDir = $appRoot === '' ? $projectDir : rtrim($projectDir, '/') . '/' . $appRoot;
        $logger = $this->project->shell()->logger();
        if ($frontendBuild === false) {
            $logger?->info('Frontend build turned off by the recipe (frontend_build: false)');

            return false;
        }
        $declared = is_string($frontendBuild) && trim($frontendBuild) !== '' ? trim($frontendBuild) : null;

        $frontend = $this->frontendPackage($projectDir, $appRoot, $declared);
        if ($frontend === null) {
            if ($declared !== null) {
                throw new \Exception('The recipe sets frontend_build, but the project has no package.json to run it against');
            }

            return false;
        }
        $buildDir = $frontend['root'] === '' ? $projectDir : $appDir;
        if ($frontend['root'] !== '') {
            $files = ProjectContext::listRootFiles($buildDir);
        }

        $logger?->info('Compiling frontend assets on host');
        $cached = $this->prepareCache();
        if ($this->composerStillNeeded($projectDir, $appRoot, $appDir)) {
            $this->runComposer($appRoot);
        }
        $this->buildFrontend($logger, $projectDir, $buildDir, $files, $frontend, $declared, $phpImage, $cached);

        $logger?->ok('Frontend assets compiled on host');

        return true;
    }

    /**
     * The package.json to build from: one with a build script (any, when the
     * recipe declares its own command) in the app_root or the checkout root,
     * else a JS workspace root whose packages carry the build.
     *
     * @return ?array{package: array<string, mixed>, root: string, workspace: bool}
     */
    private function frontendPackage(string $projectDir, string $appRoot, ?string $declared): ?array
    {
        foreach (array_unique([$appRoot, '']) as $candidate) {
            $package = $declared === null
                ? $this->packageWithBuild($projectDir, $candidate)
                : $this->packageJson($projectDir, $candidate);
            if ($package !== null) {
                return ['package' => $package, 'root' => $candidate, 'workspace' => false];
            }
        }

        return $declared === null ? $this->workspacePackage($projectDir, $appRoot) : null;
    }

    /**
     * A workspace root whose build is a workspace's own `build` script
     * (Firefly III's root declares only `workspaces`).
     *
     * @return ?array{package: array<string, mixed>, root: string, workspace: bool}
     */
    private function workspacePackage(string $projectDir, string $appRoot): ?array
    {
        foreach (array_unique([$appRoot, '']) as $candidate) {
            $root = $this->packageJson($projectDir, $candidate);
            $pnpmWorkspace = $this->project->projectTree()
                ->readIn($projectDir, self::subtreePrefix($candidate) . 'pnpm-workspace.yaml') !== null;
            if ($root !== null && JsPackageManager::isJsWorkspace($root, $pnpmWorkspace ? ['pnpm-workspace.yaml' => true] : [])) {
                return ['package' => $root, 'root' => $candidate, 'workspace' => true];
            }
        }

        return null;
    }

    /**
     * Whether the frontend pass has to run Composer: only with a composer.json
     * and no autoloader yet in its `vendor-dir`. The PHP strategy's own pass
     * normally ran first in the runtime image, and Composer cannot run without
     * a manifest.
     */
    private function composerStillNeeded(string $projectDir, string $appRoot, string $appDir): bool
    {
        $composerJson = $this->project->projectTree()->readIn($projectDir, self::subtreePrefix($appRoot) . 'composer.json');

        return $composerJson !== null
            && !$this->project->system()->filesystem()->fileExists(
                $appDir . '/' . self::vendorDir($composerJson) . '/autoload.php'
            );
    }

    /**
     * Install with the engine's command (cache, lockfile, image) and build with
     * the recipe's `frontend_build` or the package's script, on a cache hit too.
     *
     * @param array<string, true> $files
     * @param array{package: array<string, mixed>, root: string, workspace: bool} $frontend
     */
    private function buildFrontend(
        ?DeployLogger $logger,
        string $projectDir,
        string $buildDir,
        array $files,
        array $frontend,
        ?string $declared,
        ?string $phpImage,
        bool $cached
    ): void {
        $package = $frontend['package'];
        $pm = JsPackageManager::detectPackageManager($files, $package);
        $install = JsPackageManager::installCommand($pm, $files, $package, $buildDir);
        $build = $declared ?? ($frontend['workspace']
            ? JsPackageManager::workspacesScriptCommand($pm, 'build')
            : JsPackageManager::scriptCommand($pm, 'build'));
        $nodeImage = Images::nodeImage($buildDir, $package);
        $image = $this->frontendImage($install, $nodeImage, $pm, $phpImage, $package, $build, $buildDir);
        [$installCmd, $buildCmd] = self::commandsForImage($image, $install, $build);
        $recipeEnv = ['NODE_ENV' => 'development'];

        try {
            $this->runContainer($projectDir, $image, $installCmd, $buildCmd, $recipeEnv, $cached, true, $frontend['root']);
        } catch (\Exception $e) {
            if ($image !== HostNodeBuild::BUN_IMAGE) {
                throw $e;
            }
            $this->retryOnNode($logger, $e, $projectDir, $nodeImage, $install, $build, $recipeEnv, $cached, $frontend['root']);
        }
    }

    /**
     * The compiler image, or the PHP runtime with Node copied in when a script
     * calls composer or php (selfoss's postinstall), which exits 127 in Node.
     *
     * @param array<string, mixed> $package
     */
    private function frontendImage(
        string $install,
        string $nodeImage,
        string $pm,
        ?string $phpImage,
        array $package,
        string $build,
        string $buildDir
    ): string {
        $image = HostNodeBuild::compilerImage($install, $nodeImage, $pm);
        if ($image !== $nodeImage || $phpImage === null || $phpImage === '') {
            return $image;
        }
        $callsPhp = JsPackageManager::scriptsCallPhp(
            $package,
            $build,
            fn (string $file): ?string => $this->project->projectTree()->readIn($buildDir, $file)
        );

        return $callsPhp
            ? ($this->project->innerDocker()->bases()->ensureNodeBuild($phpImage, $nodeImage) ?? $image)
            : $image;
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

    /**
     * Catch a build that reported success but left nothing runnable (a failed
     * one already threw). A host-run app is checked for node_modules, not its
     * output directory, since its build step may legitimately produce none.
     */
    private function assertBuildProduced(
        string $projectDir,
        string $output,
        bool $isNitro,
        bool $isMounted = false,
        bool $isNode = true
    ): void {
        $system = $this->project->system();
        if ($isMounted) {
            if ($isNode && !$system->filesystem()->directoryExists($projectDir . '/node_modules')) {
                throw new \Exception('Install finished but node_modules is missing');
            }

            return;
        }
        if ($isNitro) {
            $this->assertNitroEntry($projectDir);

            return;
        }

        $index = $projectDir . '/' . $output . '/index.html';
        if (!$system->filesystem()->fileExists($index)) {
            throw new \Exception('Static build finished but ' . $output . '/index.html is missing');
        }
    }

    private function assertNitroEntry(string $projectDir): void
    {
        $system = $this->project->system();
        $exists = static fn (string $path): bool => $system->filesystem()->fileExists($path);
        if (StandaloneNodeServe::firstEntry($projectDir, $exists) === null) {
            $this->relocateNestedNitroOutput($projectDir);
        }
        if (StandaloneNodeServe::firstEntry($projectDir, $exists) === null) {
            throw new \Exception(StandaloneNodeServe::missingEntryMessage());
        }
    }

    /**
     * Build the frontends a Go program embeds before compiling it
     * ({@see GoEmbeddedFrontends}). Not fatal: a frontend the binary does not
     * embed may fail to build, and one it does leaves the Go build to fail on
     * the same embed error, with this build's output above it.
     */
    private function buildGoEmbeddedFrontends(string $projectDir, bool $cached): void
    {
        $logger = $this->project->shell()->logger();
        foreach (GoEmbeddedFrontends::plan($projectDir) as $frontend) {
            $dir = $frontend['dir'];
            $logger?->info('Building the frontend in ' . ($dir === '' ? 'the project root' : $dir . '/')
                . " first: {$frontend['embed']} is embedded by the Go build and is not in the checkout");
            try {
                $this->runContainer($projectDir, $frontend['image'], $frontend['install'], $frontend['build'], [], $cached, true, $dir);
            } catch (DeployCancelledException $e) {
                throw $e;
            } catch (\Exception $e) {
                $logger?->info("The frontend build in {$dir}/ failed; compiling Go without it: " . $e->getMessage());
            }
        }
    }

    /**
     * A workspace's Nitro build writes `.output` beside its Vite root (wemux:
     * apps/web/.output), while it is served from the project's `.output`.
     * Exactly one such directory is moved up; several are refused by name.
     */
    private function relocateNestedNitroOutput(string $projectDir): void
    {
        $root = rtrim($projectDir, '/');
        try {
            $listing = $this->project->system()->exec(StandaloneNodeServe::nestedEntriesArgv($root), [], 60);
        } catch (\Exception) {
            return;
        }
        $dirs = StandaloneNodeServe::nestedOutputDirs($root, $listing);
        if ($dirs === []) {
            return;
        }
        if (count($dirs) > 1) {
            throw new \Exception(StandaloneNodeServe::missingEntryMessage()
                . '; several workspace apps built one: ' . implode(', ', $dirs));
        }
        $this->moveNitroOutputUp($root, $dirs[0]);
    }

    private function moveNitroOutputUp(string $root, string $dir): void
    {
        $system = $this->project->system();
        $system->exec(['sudo', 'rm', '-rf', '--', $root . '/.output'], [], 60);
        $system->exec(['sudo', 'mv', '-T', '--', $root . '/' . $dir, $root . '/.output'], [], 60);
        $system->exec(['sudo', 'touch', $root . '/.output/' . StandaloneNodeServe::RELOCATED_MARKER], [], 60);
        $this->project->shell()->logger()?->info(
            "The build wrote its server to {$dir}; moved it to .output, where it is served from"
        );
    }

    /** A `.output` moved up by the last deploy would outlive a build that writes the nested one again. */
    private function clearRelocatedNitroOutput(string $appDir): void
    {
        $output = rtrim($appDir, '/') . '/.output';
        if ($this->project->system()->filesystem()->fileExists($output . '/' . StandaloneNodeServe::RELOCATED_MARKER)) {
            $this->project->system()->exec(['sudo', 'rm', '-rf', '--', $output], [], 60);
        }
    }

    /**
     * The image a command-runtime host compile runs in: the recipe's own,
     * except for Rust and Ruby. Anything else goes through PythonBase, the
     * same answer FrameworkStrategy writes into the compose file, so a venv
     * built here imports there; non-Python images pass through unchanged.
     *
     * @param array<string, mixed> $decision
     */
    private function commandRuntimeImage(array $decision, string $projectDir): string
    {
        $declared = trim((string) ($decision['image'] ?? ''));
        $strategy = $decision['strategy'] ?? null;

        if ($strategy === Strategies::RUST) {
            return $this->rustCompileImage($declared, $projectDir);
        }
        if ($strategy === Strategies::RUBY) {
            return $this->rubyCompileImage($declared, $projectDir);
        }
        if ($strategy === Strategies::JAVA && $declared !== '') {
            return $this->javaCompileImage($declared, $projectDir);
        }

        return PythonBase::imageFor($this->project, $projectDir, $declared);
    }

    /**
     * Rust compiles in the full image (slim has no g++, make, pkg-config or
     * OpenSSL headers), plus protoc, cmake, libclang or mold when the build
     * scripts call them. {@see bundleRustRuntimeLibraries()} covers the gap.
     */
    private function rustCompileImage(string $declared, string $projectDir): string
    {
        $image = RustRuntime::compileImage($declared);
        $tools = RustBuildTools::packages($projectDir);

        return $tools === [] ? $image
            : ($this->project->innerDocker()->bases()->ensureRustBuild($image, $tools) ?? $image);
    }

    /**
     * Ruby gems are often native, so they compile in the PanelAlpha base with
     * the apt packages, the same image the container runs.
     */
    private function rubyCompileImage(string $declared, string $projectDir): string
    {
        $app = RubyApp::at($projectDir, ProjectContext::listRootFiles($projectDir));
        $base = $this->project->innerDocker()->ensureRubyBaseImage(
            RubyRuntime::imageFor($app->project),
            SystemPackages::for($app->gemfile())
        );

        return $base ?: $declared;
    }

    /**
     * A Java build that runs npm (Tolgee's Gradle scripts) compiles with Node
     * copied in; the container still runs $declared.
     */
    private function javaCompileImage(string $declared, string $projectDir): string
    {
        $node = JavaNodeTooling::nodeImageFor($projectDir);
        if ($node !== null) {
            return $this->project->innerDocker()->bases()->ensureNodeBuild($declared, $node) ?? $declared;
        }

        return $declared;
    }

    /**
     * The engine's JDK image for the release javac just refused, or null when
     * that is the image it ran on or the recipe names its own. A fetched parent
     * pom (Tigase's) can set a release detection never saw.
     *
     * @param array<string, mixed> $decision
     */
    public static function newerJdkImage(array $decision, string $failure): ?string
    {
        if (($decision['strategy'] ?? null) !== Strategies::JAVA
            || preg_match('/error: release version ([0-9]+) not supported/i', $failure, $release) !== 1
        ) {
            return null;
        }
        $declared = trim((string) ($decision['image'] ?? ''));
        foreach (is_array($decision['requirements'] ?? null) ? $decision['requirements'] : [] as $requirement) {
            if (!$requirement instanceof Requirement || $requirement->id !== 'java'
                || preg_match('/^\d+-(maven|gradle)$/', $requirement->version, $tool) !== 1
                || JavaRuntime::imageTag($requirement->version) !== $declared
            ) {
                continue;
            }
            $image = JavaRuntime::imageTag(JavaRuntime::toolchain($tool[1], (int) $release[1]));

            return $image !== $declared ? $image : null;
        }

        return null;
    }

    /**
     * Compile again on $jdkImage, then run the app on it: a class file built
     * for a newer release does not load on the older JDK.
     *
     * @param array<string, mixed> $decision
     * @param array<string, mixed> $env
     */
    private function compileOnNewerJdk(
        array $decision,
        string $projectDir,
        string $jdkImage,
        string $install,
        string $build,
        array $env,
        string $appRoot
    ): void {
        $declared = trim((string) ($decision['image'] ?? ''));
        $this->project->shell()->logger()?->info(
            "The JDK in {$declared} cannot compile the Java release this project targets; compiling again with {$jdkImage}"
        );
        $image = $this->commandRuntimeImage(['image' => $jdkImage] + $decision, $projectDir);
        $this->runContainer($projectDir, $image, $install, $build, $env, false, false, $appRoot);
        $this->project->composeWriter()->replaceAppImage(
            $declared,
            $jdkImage,
            $this->project->userModel()->getChownString()
        );
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
        if (!$this->runtimeLacksRustLibraries($projectDir, $runtimeImage)) {
            return;
        }

        $this->project->shell()->logger()?->info(
            "Bundling the libraries {$runtimeImage} lacks from {$compileImage} into " . RustRuntimeLibraries::DIR
        );
        $this->runContainer($projectDir, $compileImage, '', RustRuntimeLibraries::bundleScript(), [], false, false);
        $this->runContainer($projectDir, $runtimeImage, '', RustRuntimeLibraries::verifyScript(), [], false, false);
    }

    /** False too when the runtime image cannot even run the check: it then starts unbundled. */
    private function runtimeLacksRustLibraries(string $projectDir, string $runtimeImage): bool
    {
        try {
            $this->runContainer($projectDir, $runtimeImage, '', RustRuntimeLibraries::checkScript(), [], false, false);
        } catch (DeployCancelledException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->project->shell()->logger()?->info(
                "Could not check the Rust binary against {$runtimeImage}: " . $e->getMessage()
            );

            return false;
        }

        return $this->project->system()->filesystem()->fileExists($projectDir . '/' . RustRuntimeLibraries::MISSING_FILE);
    }

    /**
     * The PHP minor the deployed app will run on, from the app_root's
     * composer.json (phpBB's application is its `phpBB/` subtree), so the host
     * install resolves for it rather than for the Composer image's own PHP.
     */
    private function targetPhpMinor(string $appRoot = ''): ?string
    {
        $composerJson = $this->projectComposerJson($appRoot);
        if ($composerJson === null) {
            return null;
        }

        return PhpRuntime::requirementFor($composerJson, $this->projectComposerLock($appRoot))->version;
    }

    /** The project's composer.lock from the app_root, or null when it ships none. */
    private function projectComposerLock(string $appRoot = ''): ?string
    {
        return $this->readFromAppRoot($appRoot, 'composer.lock');
    }

    /** The project's composer.json, from the same subtree as its lock. */
    private function projectComposerJson(string $appRoot = ''): ?string
    {
        return $this->readFromAppRoot($appRoot, 'composer.json');
    }

    /**
     * Read through readIn(): read() wants an absolute path and silently
     * answers null for a relative one.
     */
    private function readFromAppRoot(string $appRoot, string $file): ?string
    {
        $projectDir = $this->project->engineAccount()->projectDir();

        return $this->project->projectTree()->readIn($projectDir, self::subtreePrefix($appRoot) . $file);
    }

    private static function subtreePrefix(string $appRoot): string
    {
        $root = trim($appRoot, '/');

        return $root === '' ? '' : $root . '/';
    }

    /** {@see PhpHostBuild::mirrorsRuntimeLock()}, for the manifest's subtree. */
    private function mirrorsRuntimeLock(string $appRoot = ''): bool
    {
        $composerJson = $this->projectComposerJson($appRoot);

        return $composerJson !== null
            && PhpHostBuild::mirrorsRuntimeLock($composerJson, $this->projectComposerLock($appRoot));
    }

    /**
     * Whether the project's lock pins a PHP its own packages reject, or holds a
     * package rejecting the PHP composer.json allows and the deploy runs.
     * Never without a composer.json: Composer cannot install from a bare lock.
     */
    private function lockPhpContradicted(string $appRoot = ''): bool
    {
        if ($this->projectComposerJson($appRoot) === null) {
            return false;
        }

        $lock = $this->projectComposerLock($appRoot);
        if (PhpRuntime::lockedPhpContradicted($lock)) {
            return true;
        }
        $minor = $this->targetPhpMinor($appRoot);
        $package = $minor === null ? null
            : PhpRuntime::lockedPackageRejecting($this->projectComposerJson($appRoot), $lock, $minor);
        if ($package !== null) {
            $this->project->shell()->logger()?->info(
                "composer.lock holds {$package}, which rejects PHP {$minor} that composer.json allows; "
                . 'installing the locked set without the php platform check'
            );
        }

        return $package !== null;
    }

    /**
     * The PHP project's own build (`composer install` and the manifest's build
     * commands) on the host, in the image it is served from; vendor/ lands in
     * the served mount. Throws, so dependencies that do not resolve fail the
     * deploy instead of an app that 500s on its first request.
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
        $manifest = $this->runtimeComposerManifest($account->projectDir(), $appRoot);
        $script = $this->phpBuildScript($install, $build, $hasComposer, $appRoot, $manifest);

        $logger = $this->project->shell()->logger();
        $withCache = $this->prepareCache();
        $logger?->info('Resolving PHP dependencies on host');

        $this->ensureHostImage($image);

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
     * The host build script, pinned to the engine's PHP minor over a project's
     * own `config.platform`, without the php platform check for a lock that
     * contradicts itself (egroupware), and with plugins allowed only when
     * Composer reads the engine's $manifest. composer.json names the classmap
     * directories the checkout lacks ({@see PhpHostBuild::classmapDirsStep()}).
     */
    private function phpBuildScript(
        string $install,
        string $build,
        bool $hasComposer,
        string $appRoot,
        ?string $manifest
    ): string {
        return PhpHostBuild::script(
            $install,
            $build,
            $hasComposer,
            $this->targetPhpMinor($appRoot),
            $this->lockPhpContradicted($appRoot),
            $manifest !== null,
            $manifest !== null && $this->mirrorsRuntimeLock($appRoot),
            $this->projectComposerJson($appRoot)
        );
    }

    /**
     * Make sure the build network exists and its firewall is applied.
     *
     * Both fail open, with a warning: a network that cannot be made (`docker
     * network create` fails once a firewall flush removed Docker's chains)
     * sends the build to the default bridge, and a firewall that cannot be
     * applied leaves the network unfiltered.
     */
    private function prepareBuildNetwork(): void
    {
        $builder = $this->hostBuilder();
        $network = $builder instanceof DindHostBuilder ? $builder->network() : null;
        if ($network === null) {
            return;
        }

        $logger = $this->project->shell()->logger();
        if ($this->ensureBuildNetwork($network, $logger)) {
            $this->applyBuildNetworkFirewall($network, $logger);
        }
    }

    /** Create the network unless it exists; false when neither this build nor a concurrent one made it. */
    private function ensureBuildNetwork(string $network, ?DeployLogger $logger): bool
    {
        if ($this->buildNetworkExists($network)) {
            return true;
        }
        try {
            $this->project->system()->exec(BuildNetwork::createArgv($network), [], 60);
        } catch (\Exception $e) {
            if ($this->buildNetworkExists($network)) {
                return true;
            }
            $this->buildNetworkUnavailable = true;
            $logger?->warn(
                "Host build network {$network} could not be created, so this build runs on Docker's "
                    . 'default bridge and can reach the host and its private network: ' . trim($e->getMessage())
            );

            return false;
        }

        return true;
    }

    private function applyBuildNetworkFirewall(string $network, ?DeployLogger $logger): void
    {
        try {
            $this->project->system()->exec(BuildNetwork::firewallArgv($network), [], 60);
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
     * Prepare the build network, then create the account's host-side caches and
     * say whether that worked. Best-effort; without a cache the node_modules
     * mount must go too, or docker creates it as root and the build cannot write.
     */
    private function prepareCache(): bool
    {
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
        $raw = $this->project->projectTree()->readIn($projectDir, self::subtreePrefix($root) . 'package.json');
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

    /** Composer install on the host, from the same runtime manifest runPhpBuild() resolves. */
    private function runComposer(string $appRoot = ''): void
    {
        $account = $this->project->engineAccount();
        $phpMinor = $this->targetPhpMinor($appRoot);
        $manifest = $this->runtimeComposerManifest($account->projectDir(), $appRoot);

        $process = $this->runHostBuild(
            static fn (HostBuilder $builder): array
                => $builder->composerInstallArgv($account, $phpMinor, $manifest, $appRoot),
            self::COMPOSER_INSTALL_TIMEOUT_SECONDS
        );

        if (!$process->isSuccessful()) {
            throw $this->hostBuildFailure($process, 'Host Composer install failed');
        }
    }

    /**
     * Write the engine's runtime manifest (allow-plugins, platform pin, and
     * without a lock the require-dev drop) beside the app_root's composer.json
     * and return its name, relative to that directory. Null when there is no
     * composer.json or it cannot be written: Composer then uses composer.json
     * with `--no-plugins`.
     */
    private function runtimeComposerManifest(string $projectDir, string $appRoot = ''): ?string
    {
        $appDir = rtrim($projectDir . '/' . trim($appRoot, '/'), '/');
        $files = $this->project->projectTree();

        $composerJson = $files->readIn($appDir, 'composer.json');
        if ($composerJson === null) {
            return null;
        }

        $composerLock = $files->readIn($appDir, 'composer.lock');
        $runtime = PhpHostBuild::runtimeManifest($composerJson, $composerLock);
        if ($runtime === null || !$this->writeRuntimeManifest($appDir, $runtime, $composerLock)) {
            return null;
        }

        return PhpHostBuild::RUNTIME_MANIFEST_FILE;
    }

    /**
     * The lock is copied beside the manifest on every call: Composer looks for
     * the lock named after the manifest it is handed, and would otherwise
     * resolve the whole graph remotely.
     */
    private function writeRuntimeManifest(string $appDir, string $runtime, ?string $composerLock): bool
    {
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

            return false;
        }

        return true;
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
        if ($isNode) {
            $env = $this->withNodeModulesLinker($env, $projectDir, $appRoot);
            $build = $this->withLegacyOpenssl($build, $projectDir, $appRoot);
        }
        $this->refreshHostImage($image);
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
            self::NODE_BUILD_TIMEOUT_SECONDS
        );

        if (!$process->isSuccessful()) {
            throw $this->hostBuildFailure($process, 'Host static asset compile failed');
        }
    }

    /**
     * Yarn PnP's link step deletes any node_modules it finds, which aborts with
     * EBUSY on the isolated node_modules bind mount, and a host-run app starts
     * from a node_modules PnP never writes.
     *
     * @param array<string, mixed> $env
     * @return array<string, mixed>
     */
    private function withNodeModulesLinker(array $env, string $projectDir, string $appRoot): array
    {
        if (!array_key_exists('YARN_NODE_LINKER', $env) && $this->isYarnPnp($projectDir, $appRoot)) {
            $env['YARN_NODE_LINKER'] = 'node-modules';
        }

        return $env;
    }

    /** Webpack 4 hashes with MD4, which Node 17+ refuses without the legacy OpenSSL provider. */
    private function withLegacyOpenssl(string $build, string $projectDir, string $appRoot): string
    {
        $buildDir = rtrim($projectDir, '/') . (trim($appRoot, '/') === '' ? '' : '/' . trim($appRoot, '/'));
        $legacy = NodeRuntime::withLegacyOpenssl($build, $buildDir);
        if ($legacy !== $build) {
            $this->project->shell()->logger()?->info('Webpack 4 build: enabling the OpenSSL legacy provider');
        }

        return $legacy;
    }

    private function isYarnPnp(string $projectDir, string $appRoot): bool
    {
        $prefix = self::subtreePrefix($appRoot);
        $tree = $this->project->projectTree();
        $package = json_decode((string) $tree->readIn($projectDir, $prefix . 'package.json'), true);

        return JsPackageManager::isYarnPnp(
            is_array($package) ? $package : [],
            $tree->readIn($projectDir, $prefix . '.yarnrc.yml'),
            $tree->readIn($projectDir, $prefix . 'yarn.lock')
        );
    }

    /**
     * Why a host build container failed. An OOM kill is named outright, since
     * it may leave no text of its own and the output's last lines are not why.
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
     * One of our images the host pruned is pulled back from the cache registry:
     * `docker run` would look for it on docker.io, which does not publish our
     * tags. Best-effort: a failed pull leaves the build to report its own error.
     */
    private function ensureHostImage(string $image): void
    {
        $argv = $this->project->innerDocker()->imageStore()->hostEnsureImageArgv($image);
        if ($argv === ['true']) {
            return;
        }
        try {
            $this->project->system()->execOnHost($argv, [], 900);
        } catch (\Throwable $e) {
            $this->project->shell()->logger()?->info(
                "Could not get base image {$image} onto the host; the build will report why: " . $e->getMessage()
            );
        }
    }

    /**
     * A public build image (`node:22-bookworm-slim`) is re-pulled once per
     * deploy, so the host does not build on whatever copy it pulled first.
     * Best-effort: a failed pull builds with that copy.
     */
    private function refreshHostImage(string $image): void
    {
        if (isset($this->refreshedOnHost[$image])) {
            return;
        }
        $this->refreshedOnHost[$image] = true;
        $argv = $this->project->innerDocker()->imageStore()->hostRefreshImageArgv($image);
        if ($argv === ['true']) {
            return;
        }
        try {
            $this->project->system()->execOnHost($argv, [], 900);
        } catch (\Throwable $e) {
            $this->project->shell()->logger()?->info(
                "Could not refresh base image {$image} on the host; building with the copy it has: " . $e->getMessage()
            );
        }
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
