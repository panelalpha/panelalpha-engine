<?php

namespace App\Lib\Deploy\Platform;

use App\Lib\Deploy\Compose\AppRoot;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\Probes\AngularOutputProbe;
use App\Lib\Deploy\Platform\Probes\AstroOutputDir;
use App\Lib\Deploy\Platform\Probes\BundlerSpaProbe;
use App\Lib\Deploy\Platform\Probes\NextWorkspaceProbe;
use App\Lib\Deploy\Platform\Probes\ViteOutputDir;
use App\Lib\Deploy\Platform\Runtime\DotnetRuntime;
use App\Lib\Deploy\Platform\Runtime\GoRuntime;
use App\Lib\Deploy\Platform\Runtime\ImageResolver;
use App\Lib\Deploy\Platform\Runtime\JavaRuntime;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use App\Lib\Deploy\Platform\Runtime\Php\AssetPublish;
use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use App\Lib\Deploy\Platform\Runtime\Requirement;
use App\Lib\Deploy\Platform\Runtime\RuntimeRegistry;
use App\Lib\Deploy\Platform\Runtime\RustRuntime;

/**
 * Fills in the parts of a decision that depend on the project, not the platform: base images from `requires`,
 * the output directory, the JS package manager, and the `output_from`/`commands_from` resolvers.
 */
final class PlatformValues
{
    /**
     * The context a manifest's own application sits in, the project root unless `app_root` moved it: toolchains
     * belong to the application, and phpBB's composer.json lives in phpBB/. A probe's `app_root` counts too (Django's).
     *
     * @param  array<string, mixed>  $decision
     */
    private static function appRootContext(PlatformManifest $manifest, ProjectContext $context, array $decision = []): ProjectContext
    {
        $appRoot = $manifest->appRoot !== '' ? $manifest->appRoot : AppRoot::relative($decision);
        if ($appRoot === '') {
            return $context;
        }

        $dir = $context->projectDir.'/'.$appRoot;

        return is_dir($dir) ? ProjectContext::at($dir) : $context;
    }

    /**
     * The Next workspace the probe found when the repository root has no package.json to install it from
     * (storyden's web/): that workspace is the whole JS project. Null otherwise.
     *
     * @param  array<string, mixed>  $decision
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
        if ($relative === '' || ! is_file($context->projectDir.'/'.$relative.'/package.json')) {
            return null;
        }

        return $relative;
    }

    /**
     * Resolve every project-dependent field of a decision, in place.
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    public static function apply(
        PlatformManifest $manifest,
        ProjectContext $context,
        array $decision
    ): array {
        $appContext = self::appRootContext($manifest, $context, $decision);
        $runtimeContext = $appContext;
        $standalone = self::standaloneNextWorkspace($manifest, $context, $decision);
        if ($standalone !== null) {
            $decision = self::withStandaloneAppRoot($decision, $standalone);
            $runtimeContext = ProjectContext::at($context->projectDir.'/'.$standalone);
        }

        $decision = self::withImages($manifest, $runtimeContext, $decision);
        $decision = self::withRecipeNextWorkspace($manifest, $runtimeContext, $decision);
        $decision['output_directory'] = self::outputDirectory($manifest, $context, $decision);
        $decision['runtime_image'] = self::runtimeImage($manifest, $runtimeContext);
        $decision = self::withDotnetNodeImage($manifest, $runtimeContext, $decision);
        $decision['resolved_commands'] = self::resolvedCommands($manifest, $appContext);

        if (self::isJsProject($manifest, $runtimeContext)) {
            return self::applyJs($manifest, $runtimeContext, $decision);
        }

        return self::applyResolvedCommands($manifest, $decision);
    }

    /**
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private static function withStandaloneAppRoot(array $decision, string $standalone): array
    {
        $decision['app_root'] = $standalone;
        $decision['workspace_relative'] = '';
        unset($decision['workspace_package'], $decision['workspace_slug']);

        return $decision;
    }

    /**
     * Images and the deploy-log toolchain lines (which version, from which file). A manifest naming its own
     * `image` makes an unreadable project manifest non-fatal (osTicket ships no composer.json).
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private static function withImages(PlatformManifest $manifest, ProjectContext $runtimeContext, array $decision): array
    {
        $requirements = $manifest->requires === []
            ? []
            : RuntimeRegistry::resolveAll($manifest->requires, $runtimeContext, $manifest->image !== null);

        $decision['image'] = self::image($manifest, $runtimeContext);
        $decision['requirements'] = $requirements;
        $decision['toolchain'] = array_map(
            static fn (Requirement $r): string => $r->explain(),
            $requirements
        );
        $decision['build_images'] = ImageResolver::buildImages($requirements);

        return $decision;
    }

    /**
     * A recipe names its platform, so no detect ran to find the Next workspace; under an app_root it is looked for there.
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private static function withRecipeNextWorkspace(PlatformManifest $manifest, ProjectContext $runtimeContext, array $decision): array
    {
        if ($manifest->appRoot !== '' && $manifest->outputFrom === 'next-workspace'
            && ! isset($decision['workspace_relative'])
        ) {
            $workspace = (new NextWorkspaceProbe)->evaluate($runtimeContext);
            $decision += is_array($workspace) ? $workspace : [];
        }

        return $decision;
    }

    /**
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private static function withDotnetNodeImage(PlatformManifest $manifest, ProjectContext $runtimeContext, array $decision): array
    {
        if (isset($manifest->requires['dotnet'])) {
            $node = DotnetRuntime::nodeBuildImage($runtimeContext->projectDir);
            if ($node !== null) {
                $decision['build_node_image'] = $node;
                $decision['toolchain'][] = "{$node} in the build stage (an MSBuild target runs a JS tool)";
            }
        }

        return $decision;
    }

    /**
     * Commands whose text a resolver supplies, keyed by command id, resolved in the application's own directory.
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
                'laravel.assets' => AssetPublish::buildCommand($context->composer()),
                default => throw new ManifestException(
                    "{$manifest->id}: unknown command resolver '{$resolver}' for '{$commandId}'"
                ),
            };
        }

        return $resolved;
    }

    /**
     * `install_command`/`build_command` with an app config's build commands folded in, as the host build reads only
     * those; a resolver answers for the manifest's command, never for the app config's replacement of it.
     *
     * @param  array<string, mixed>  $decision
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
     * A `{{js.install}}`, `{{js.build:…}}` or `{{js.start:…}}` in an app config's command, resolved as the platform's
     * are (a start only for a Node runtime); anything else is returned unchanged.
     *
     * @param  array<string, mixed>  $decision  the platform's, for its output directory and workspace
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
        if (! $context->hasFile('package.json')) {
            return $default;
        }

        $partial = self::placeholderPartial($manifest, $kind, $default, $decision);
        $resolved = NodeRuntime::finalizeProject($partial, $context->projectDir, $context->files);

        return (string) ($resolved[$kind.'_command'] ?? $default);
    }

    /**
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private static function placeholderPartial(PlatformManifest $manifest, string $kind, string $default, array $decision): array
    {
        $partial = [
            'strategy' => $manifest->strategy,
            'label' => $manifest->label,
            'runtime' => $kind === 'start' ? PlatformManifest::RUNTIME_NODE : $manifest->runtime,
            'port_hint' => $manifest->port ?? 3000,
            'output_directory' => $decision['output_directory'] ?? $manifest->outputDirectory,
            'default_build' => $kind === 'build' ? $default : '',
            'default_start' => $kind === 'start' ? $default : '',
            'env' => $manifest->env,
        ];

        return self::withWorkspace($partial, $decision);
    }

    /**
     * What the project declared it depends on, so the start command can prefer a WSGI or ASGI server it ships.
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
     * Project the resolved commands onto the legacy fields the Dockerfile generators read, `optional` re-applied:
     * without it Laravel's package-discover fails every project that lacks the script.
     *
     * @param  array<string, mixed>  $decision
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
        if (! str_contains($manifest->runtimeImage, '{version}')) {
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
     * A Next app in a workspace builds into that workspace.
     *
     * @param  array<string, mixed>  $decision
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
            'astro' => AstroOutputDir::outputDir($context->projectDir, $manifest->outputDirectory ?? 'dist'),
            'webpack' => BundlerSpaProbe::outputDir($context->projectDir, $manifest->outputDirectory ?? 'dist'),
            'vite' => ViteOutputDir::outputDir($context->projectDir, $manifest->outputDirectory ?? 'dist'),
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

        return $relative === '' ? $output : $relative.'/'.$output;
    }

    /**
     * A JS runtime with a package.json under the app root, the only case where the package manager matters; the
     * static strategy serves the checkout as committed, so a package.json there is tooling.
     */
    private static function isJsProject(PlatformManifest $manifest, ProjectContext $context): bool
    {
        return in_array($manifest->runtime, [PlatformManifest::RUNTIME_NODE, PlatformManifest::RUNTIME_NGINX], true)
            && $manifest->strategy !== Strategies::STATIC
            && $context->hasFile('package.json');
    }

    /**
     * Hand the manifest's declared defaults to the recipe layer's package
     * manager resolution and take back what it decides.
     *
     * @param  array<string, mixed>  $decision
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
        $partial = self::withWorkspace($partial, $decision);

        $resolved = NodeRuntime::finalizeProject($partial, $context->projectDir, $context->files);

        return array_merge($decision, $resolved, [
            'strategy' => $manifest->strategy,
            'label' => $manifest->label,
        ]);
    }

    /**
     * The decision's non-empty workspace fields, copied into a partial for the recipe layer.
     *
     * @param  array<string, mixed>  $partial
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private static function withWorkspace(array $partial, array $decision): array
    {
        foreach (['workspace_package', 'workspace_slug', 'workspace_relative'] as $key) {
            if (isset($decision[$key]) && is_string($decision[$key]) && $decision[$key] !== '') {
                $partial[$key] = $decision[$key];
            }
        }

        return $partial;
    }

    /**
     * The manifest's default build, naming the Angular project whose output is
     * served when the workspace holds several.
     *
     * @param  array<string, mixed>  $decision
     */
    private static function defaultBuild(PlatformManifest $manifest, ProjectContext $context, array $decision): string
    {
        $build = self::placeholderDefault($decision['build_command'] ?? '');

        return $manifest->outputFrom === 'angular'
            ? AngularOutputProbe::buildCommand($context->projectDir, $build)
            : $build;
    }

    /** Strip the `{{js.build:...}}` wrapper down to the default it carries, so the pipeline sees the bare command. */
    private static function placeholderDefault(string $command): string
    {
        if (preg_match('/^\{\{js\.[a-z]+(?::(.*))?\}\}$/s', trim($command), $matches) !== 1) {
            return $command;
        }

        return isset($matches[1]) ? trim($matches[1]) : '';
    }
}
