<?php

namespace App\Lib\Deploy\Platform;

use App\Lib\Deploy\Compose\AppRoot;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\Runtime\ImageResolver;
use App\Lib\Deploy\Platform\Runtime\Requirement;
use App\Lib\Deploy\Platform\Runtime\RuntimeRegistry;
use App\Lib\Deploy\Platform\Probes\AngularOutputProbe;
use App\Lib\Deploy\Platform\Probes\BundlerSpaProbe;
use App\Lib\Deploy\Platform\Probes\NextWorkspaceProbe;
use App\Lib\Deploy\Platform\Probes\ViteOutputDir;
use App\Lib\Deploy\Platform\Runtime\DotnetRuntime;
use App\Lib\Deploy\Platform\Runtime\GoRuntime;
use App\Lib\Deploy\Platform\Runtime\JavaRuntime;
use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use App\Lib\Deploy\Platform\Runtime\RustRuntime;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use App\Lib\Deploy\Platform\Runtime\Php\AssetPublish;

/**
 * Fills in the parts of a decision that depend on the project, not the
 * platform: the base image, resolved from the manifest's `requires` block
 * by `RuntimeRegistry` and assembled by `ImageResolver`; the output directory,
 * from Angular's `angular.json` or a workspace; and the JS package manager,
 * decided by the lockfile.
 *
 * The rest is named resolvers — `output_from`, `commands_from` — and
 * `{{js.*}}` placeholders.
 */
final class PlatformValues
{
    /**
     * The context a manifest's own application sits in: the project root
     * unless `app_root` moved it.
     */
    private static function appRootContext(PlatformManifest $manifest, ProjectContext $context): ProjectContext
    {
        if ($manifest->appRoot === '') {
            return $context;
        }

        $dir = $context->projectDir . '/' . $manifest->appRoot;

        return is_dir($dir) ? ProjectContext::at($dir) : $context;
    }

    /**
     * The Next workspace the probe found, when the repository root has no
     * package.json to install it from; null otherwise.
     *
     * @param array<string, mixed> $decision
     */
    private static function standaloneNextWorkspace(
        PlatformManifest $manifest,
        ProjectContext $context,
        array $decision
    ): ?string {
        if ($manifest->appRoot !== '' || $manifest->outputFrom !== 'next-workspace'
            || $context->hasFile('package.json')
        ) {
            return null;
        }
        $relative = AppRoot::relative(['app_root' => (string) ($decision['workspace_relative'] ?? '')]);
        if ($relative === '' || !is_file($context->projectDir . '/' . $relative . '/package.json')) {
            return null;
        }

        return $relative;
    }

    /**
     * Resolve every project-dependent field of a decision, in place.
     *
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    public static function apply(
        PlatformManifest $manifest,
        ProjectContext $context,
        array $decision
    ): array {
        // Toolchains belong to the application, not the repository carrying it:
        // phpBB's `require.php` lives in phpBB/composer.json, and resolving at
        // the repository root finds no composer.json at all.
        $runtimeContext = self::appRootContext($manifest, $context);
        // Next in web/ with no package.json at the repository root (storyden):
        // that workspace is the whole JS project, so it becomes the app_root.
        $standalone = self::standaloneNextWorkspace($manifest, $context, $decision);
        if ($standalone !== null) {
            $decision['app_root'] = $standalone;
            $decision['workspace_relative'] = '';
            unset($decision['workspace_package'], $decision['workspace_slug']);
            $runtimeContext = ProjectContext::at($context->projectDir . '/' . $standalone);
        }

        // A manifest naming its own `image` has answered the question, so an
        // unreadable project manifest is not fatal (osTicket ships no
        // composer.json): the requirement is stated at the engine default, for
        // the deploy log.
        $requirements = $manifest->requires === []
            ? []
            : RuntimeRegistry::resolveAll($manifest->requires, $runtimeContext, $manifest->image !== null);

        $decision['image'] = self::image($manifest, $runtimeContext);
        $decision['requirements'] = $requirements;
        // Deploy-log text instead of a bare image tag: which toolchain, which
        // version, and which file said so.
        $decision['toolchain'] = array_map(
            static fn (Requirement $r): string => $r->explain(),
            $requirements
        );
        $decision['build_images'] = ImageResolver::buildImages($requirements);
        // A recipe names its platform, so no detect ran to find the Next
        // workspace; under an app_root it is looked for there.
        if ($manifest->appRoot !== '' && $manifest->outputFrom === 'next-workspace'
            && !isset($decision['workspace_relative'])
        ) {
            $workspace = (new NextWorkspaceProbe())->evaluate($runtimeContext);
            $decision += is_array($workspace) ? $workspace : [];
        }
        $decision['output_directory'] = self::outputDirectory($manifest, $context, $decision);
        $decision['runtime_image'] = self::runtimeImage($manifest, $runtimeContext);
        if (isset($manifest->requires['dotnet'])) {
            $node = DotnetRuntime::nodeBuildImage($runtimeContext->projectDir);
            if ($node !== null) {
                $decision['build_node_image'] = $node;
                $decision['toolchain'][] = "{$node} in the build stage (an MSBuild target runs a JS tool)";
            }
        }
        $decision['resolved_commands'] = self::resolvedCommands($manifest, $context);

        // The JS toolchain is the application's too: under an app_root the
        // repository root may have no package.json at all.
        if (self::isJsProject($manifest, $runtimeContext)) {
            return self::applyJs($manifest, $runtimeContext, $decision);
        }

        return self::applyResolvedCommands($manifest, $decision);
    }

    /**
     * Commands whose text a resolver supplies, keyed by command id.
     *
     * @return array<string, string>
     */
    public static function resolvedCommands(PlatformManifest $manifest, ProjectContext $context): array
    {
        $dir = $context->projectDir;
        $resolved = [];

        foreach ($manifest->commandsFrom as $commandId => $resolver) {
            $resolved[$commandId] = match ($resolver) {
                'python.install' => PythonRuntime::installCommand(
                    $context->files,
                    $context->contents('pyproject.toml')
                ),
                'python.start' => PythonRuntime::startCommand($dir, self::pythonManifests($context)),
                'django.migrate' => PythonRuntime::manageCommand($dir, 'migrate --noinput'),
                'django.collectstatic' => PythonRuntime::manageCommand($dir, 'collectstatic --noinput'),
                'django.start' => PythonRuntime::djangoStartCommand($dir, self::pythonManifests($context)),
                'rust.packages' => RustRuntime::systemPackages(),
                'rust.build' => RustRuntime::buildCommand($dir),
                'rust.start' => RustRuntime::startCommand($dir),
                'go.build' => GoRuntime::buildCommand($dir, $context->sourceUrl),
                'dotnet.build' => DotnetRuntime::buildCommand($dir),
                'dotnet.start' => DotnetRuntime::startCommand(),
                'java.build-gradle' => JavaRuntime::gradleBuildCommand($dir),
                'java.start' => JavaRuntime::startCommand(false),
                'java.start-gradle' => JavaRuntime::startCommand(true),
                'laravel.assets' => AssetPublish::buildCommand(self::appRootContext($manifest, $context)->composer()),
                default => throw new ManifestException(
                    "{$manifest->id}: unknown command resolver '{$resolver}' for '{$commandId}'"
                ),
            };
        }

        return $resolved;
    }

    /**
     * `install_command` / `build_command` with an app config's own build
     * commands folded in. The app config's commands are stripped from the
     * manifest it describes, and the host build reads only these two strings,
     * so a recipe's `stage: build` never ran (engine#171). Unchanged when the
     * app config declares no build command.
     *
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    public static function withAppConfigBuild(
        PlatformManifest $manifest,
        ?AppConfig $appConfig,
        ProjectContext $context,
        array $decision
    ): array {
        $own = $appConfig?->commands(PlatformStage::BUILD) ?? [];
        if ($own === []) {
            return $decision;
        }

        $resolved = is_array($decision['resolved_commands'] ?? null) ? $decision['resolved_commands'] : [];
        $dependencies = [];
        $assets = [];
        foreach (StageResolver::commandsFor(PlatformStage::BUILD, $manifest, $appConfig, null, $context) as $command) {
            // A resolver answers for the manifest's command, never for the app
            // config's replacement of it.
            $run = in_array($command, $own, true)
                ? self::resolveJsPlaceholder($manifest, $context, $command->run, $decision)
                : ($resolved[$command->id] ?? $command->run);
            if (trim($run) === '') {
                continue;
            }
            $run = $command->tolerantRun($run);
            if ($command->role === PlatformCommand::ROLE_DEPENDENCIES) {
                $dependencies[] = $run;
            } else {
                $assets[] = $run;
            }
        }
        $decision['install_command'] = implode(' && ', $dependencies);
        $decision['build_command'] = implode(' && ', $assets);

        return $decision;
    }

    /**
     * A `{{js.install}}`, `{{js.build:…}}` or `{{js.start:…}}` an app config
     * wrote in its own command, resolved the way the platform's are: a recipe
     * uses the same vocabulary as the manifest it extends. Anything else is
     * returned unchanged.
     *
     * @param array<string, mixed> $decision the platform's, for its output
     *        directory and workspace
     */
    public static function resolveJsPlaceholder(
        PlatformManifest $manifest,
        ProjectContext $context,
        string $run,
        array $decision = []
    ): string {
        if (preg_match('/^\{\{js\.(install|build|start)(?::(.*))?\}\}$/s', trim($run), $m) !== 1) {
            return $run;
        }
        [, $kind] = $m;
        $default = isset($m[2]) ? trim($m[2]) : '';
        $context = self::appRootContext($manifest, $context);
        if (!$context->hasFile('package.json')) {
            return $default;
        }

        $partial = [
            'strategy' => $manifest->strategy,
            'label' => $manifest->label,
            // A start is only resolved for a Node runtime.
            'runtime' => $kind === 'start' ? PlatformManifest::RUNTIME_NODE : $manifest->runtime,
            'port_hint' => $manifest->port ?? 3000,
            'output_directory' => $decision['output_directory'] ?? $manifest->outputDirectory,
            'default_build' => $kind === 'build' ? $default : '',
            'default_start' => $kind === 'start' ? $default : '',
            'env' => $manifest->env,
        ];
        foreach (['workspace_package', 'workspace_slug', 'workspace_relative'] as $key) {
            if (isset($decision[$key]) && is_string($decision[$key]) && $decision[$key] !== '') {
                $partial[$key] = $decision[$key];
            }
        }
        $resolved = NodeRuntime::finalizeProject($partial, $context->projectDir, $context->files);

        return (string) ($resolved[$kind . '_command'] ?? $default);
    }

    /**
     * What the project declared it depends on, so the start command can prefer
     * a WSGI or ASGI server the project already ships.
     *
     * @return list<string>
     */
    private static function pythonManifests(ProjectContext $context): array
    {
        $contents = [];
        foreach (['requirements.txt', 'pyproject.toml', 'Pipfile'] as $name) {
            $body = $context->contents($name);
            if (is_string($body) && $body !== '') {
                $contents[] = $body;
            }
        }

        return $contents;
    }

    /**
     * Project the resolved commands back onto the legacy decision fields the
     * Dockerfile generators read.
     *
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    private static function applyResolvedCommands(PlatformManifest $manifest, array $decision): array
    {
        $resolved = $decision['resolved_commands'];
        if ($resolved === []) {
            return $decision;
        }

        $dependencies = [];
        $assets = [];
        foreach ($manifest->stage(PlatformStage::BUILD) as $command) {
            $run = $resolved[$command->id] ?? $command->run;
            if (trim($run) === '') {
                continue;
            }
            // `optional` must be re-applied here: without it Laravel's
            // package-discover fails every project that lacks the script.
            $run = $command->tolerantRun($run);
            if ($command->role === PlatformCommand::ROLE_DEPENDENCIES) {
                $dependencies[] = $run;
            } else {
                $assets[] = $run;
            }
        }
        $decision['install_command'] = implode(' && ', $dependencies);
        $decision['build_command'] = implode(' && ', $assets);

        $serve = $manifest->serveCommand();
        if ($serve !== null) {
            $decision['start_command'] = $resolved[$serve->id] ?? $serve->run;
        }

        return $decision;
    }

    /**
     * The image the application runs in, from the manifest's `requires`.
     * A literal `image:` wins when the manifest sets one.
     */
    private static function image(PlatformManifest $manifest, ProjectContext $context): ?string
    {
        if ($manifest->image !== null) {
            return $manifest->image;
        }
        if ($manifest->requires === []) {
            return null;
        }

        return ImageResolver::runtimeImage(
            RuntimeRegistry::resolveAll($manifest->requires, $context)
        );
    }

    /**
     * The final stage's base, with `{version}` filled in from the requirement
     * that chose the build image, so the two cannot drift apart.
     */
    private static function runtimeImage(PlatformManifest $manifest, ProjectContext $context): ?string
    {
        if ($manifest->runtimeImage === null || $manifest->requires === []) {
            return $manifest->runtimeImage;
        }
        if (!str_contains($manifest->runtimeImage, '{version}')) {
            return $manifest->runtimeImage;
        }

        foreach (RuntimeRegistry::resolveAll($manifest->requires, $context) as $requirement) {
            if ($requirement->version !== '') {
                return str_replace('{version}', $requirement->version, $manifest->runtimeImage);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $decision
     */
    private static function outputDirectory(
        PlatformManifest $manifest,
        ProjectContext $context,
        array $decision
    ): ?string {
        if ($manifest->outputFrom === null) {
            return $manifest->outputDirectory;
        }

        return match ($manifest->outputFrom) {
            'angular' => AngularOutputProbe::outputDir($context->projectDir),
            'webpack' => BundlerSpaProbe::outputDir($context->projectDir, $manifest->outputDirectory ?? 'dist'),
            'vite' => ViteOutputDir::outputDir($context->projectDir, $manifest->outputDirectory ?? 'dist'),
            // A Next app in a workspace builds into that workspace.
            'next-workspace' => self::prefixWorkspace(
                (string) ($decision['workspace_relative'] ?? ''),
                $manifest->outputDirectory ?? '.next'
            ),
            default => throw new ManifestException(
                "{$manifest->id}: unknown output resolver '{$manifest->outputFrom}'"
            ),
        };
    }

    private static function prefixWorkspace(string $relative, string $output): string
    {
        $relative = trim($relative, '/');

        return $relative === '' ? $output : $relative . '/' . $output;
    }

    /**
     * A JS runtime with a package.json: the only case where the package
     * manager matters. A Laravel app's Node build goes through the PHP
     * recipe's own Node stage.
     */
    private static function isJsProject(PlatformManifest $manifest, ProjectContext $context): bool
    {
        // The static strategy serves the checkout as committed; a package.json
        // beside it is tooling, and nothing a build produced would be served.
        return in_array($manifest->runtime, [PlatformManifest::RUNTIME_NODE, PlatformManifest::RUNTIME_NGINX], true)
            && $manifest->strategy !== Strategies::STATIC
            && $context->hasFile('package.json');
    }

    /**
     * Hand the manifest's declared defaults to the recipe layer's package
     * manager resolution and take back what it decides.
     *
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    private static function applyJs(
        PlatformManifest $manifest,
        ProjectContext $context,
        array $decision
    ): array {
        $partial = [
            'strategy' => $manifest->strategy,
            'label' => $manifest->label,
            'runtime' => $manifest->runtime,
            'port_hint' => $manifest->port ?? 3000,
            'output_directory' => $decision['output_directory'],
            'default_build' => self::defaultBuild($manifest, $context, $decision),
            'default_start' => self::placeholderDefault($decision['start_command'] ?? ''),
            'env' => $manifest->env,
        ];
        foreach (['workspace_package', 'workspace_slug', 'workspace_relative'] as $key) {
            if (isset($decision[$key]) && is_string($decision[$key]) && $decision[$key] !== '') {
                $partial[$key] = $decision[$key];
            }
        }

        $resolved = NodeRuntime::finalizeProject($partial, $context->projectDir, $context->files);

        return array_merge($decision, $resolved, [
            'strategy' => $manifest->strategy,
            'label' => $manifest->label,
        ]);
    }

    /**
     * The manifest's default build, naming the Angular project whose output is
     * served when the workspace holds several.
     *
     * @param array<string, mixed> $decision
     */
    private static function defaultBuild(PlatformManifest $manifest, ProjectContext $context, array $decision): string
    {
        $build = self::placeholderDefault($decision['build_command'] ?? '');

        return $manifest->outputFrom === 'angular'
            ? AngularOutputProbe::buildCommand($context->projectDir, $build)
            : $build;
    }

    /**
     * Strip the `{{js.build:...}}` wrapper down to the default it carries, so
     * the rest of the pipeline sees the bare command.
     */
    private static function placeholderDefault(string $command): string
    {
        if (preg_match('/^\{\{js\.[a-z]+(?::(.*))?\}\}$/s', trim($command), $matches) !== 1) {
            return $command;
        }

        return isset($matches[1]) ? trim($matches[1]) : '';
    }
}
