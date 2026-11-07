<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * The base images a deploy can resolve to, and which ones a given project will pull.
 * Per-project version selection lives on the runtimes; here is the fixed set.
 */
final class Images
{
    /**
     * What a project silent about Node gets: an older LTS, since its dependencies often break on newer Node
     * (native bindings, old webpack), while older runs nearly everything newer does.
     */
    public const NODE_IMAGE = NodeRuntime::IMAGE;

    public const NGINX_IMAGE = 'nginx:alpine';

    public const PHP_IMAGE = 'php:8.3-'.PhpRuntime::IMAGE_VARIANT;

    public const COMPOSER_IMAGE = 'composer:2';

    /**
     * Where the shared PHP base gets `install-php-extensions` from. Only that
     * build uses it, but the prewarmer and the seeder ask for it too.
     */
    public const PHP_EXTENSION_INSTALLER_IMAGE = 'mlocati/php-extension-installer';

    /**
     * Every PHP minor a deploy can resolve to, for HostPrewarmPlan: the full set, not phpPreloadImages()'s.
     * Delegated so this list cannot drift from PhpRuntime::MINORS.
     *
     * @return list<string>
     */
    public static function phpImageMinors(): array
    {
        return PhpRuntime::minors();
    }

    /**
     * Recipe base images, not pulled at DinD boot: the strategy is unknown until clone, and pulling them all
     * saturates the inner daemon. preloadImagesFor() runs after detect.
     *
     * @return list<string>
     */
    public static function preloadImages(): array
    {
        return [
            self::NODE_IMAGE,
            self::NGINX_IMAGE,
            ...self::phpPreloadImages(),
        ];
    }

    /**
     * Images this deploy needs, versioned from $projectDir; skipBuild() is asked without $runtime, which would
     * call nginx nothing to build. Lenient: the prewarmer asks before any such project exists.
     *
     * @return list<string>
     */
    public static function preloadImagesFor(?string $strategy, ?string $runtime = null, string $projectDir = ''): array
    {
        if ($strategy === null || DeployCompose::skipBuild($strategy)) {
            return [];
        }

        $manifest = PlatformRegistry::findByStrategy($strategy, $runtime);
        if ($manifest === null || $manifest->requires === []) {
            return [];
        }

        $requirements = RuntimeRegistry::resolveAll(
            $manifest->requires,
            ProjectContext::make($projectDir, ProjectContext::listRootFiles($projectDir)),
            true
        );

        return array_values(array_unique(self::requiredImages($manifest, $requirements, $projectDir)));
    }

    /**
     * The runtime and build images, a .NET build's Node, and nginx: a build-only toolchain leaves no server.
     *
     * @param  list<Requirement>  $requirements
     * @return list<string>
     */
    private static function requiredImages(PlatformManifest $manifest, array $requirements, string $projectDir): array
    {
        $images = [];
        $runtimeImage = ImageResolver::runtimeImage($requirements);
        if ($runtimeImage !== null) {
            $images[] = $runtimeImage;
        }
        foreach (ImageResolver::buildImages($requirements) as $image) {
            $images[] = $image;
        }
        if (isset($manifest->requires['dotnet'])) {
            $node = DotnetRuntime::nodeBuildImage($projectDir);
            if ($node !== null) {
                $images[] = $node;
            }
        }
        if ($manifest->runtime === PlatformManifest::RUNTIME_NGINX) {
            $images[] = self::NGINX_IMAGE;
        }

        return $images;
    }

    /**
     * The pipeline's tag, in its full variant when the install compiles a dependency, so host compile and
     * account build agree. A tag other than an official `node:` one (bun, JDK, a pinned image) is kept.
     */
    public static function nodeBuildImage(string $runtimeImage, string $projectDir): string
    {
        $runtimeImage = trim($runtimeImage);
        if (! str_starts_with($runtimeImage, 'node:')) {
            return $runtimeImage;
        }

        return NodeRuntime::withToolchain($runtimeImage, ProjectContext::at($projectDir));
    }

    /**
     * Base image a known-framework recipe builds FROM.
     */
    public static function recipeBaseImage(?string $strategy, ?string $runtime, string $projectDir = ''): string
    {
        if ($projectDir !== '') {
            $files = ProjectContext::listRootFiles($projectDir);
            $package = ProjectContext::readPackageJson($projectDir) ?? [];
            $pm = JsPackageManager::detectPackageManager($files, $package);
            if ($pm === 'bun') {
                return HostNodeBuild::BUN_IMAGE;
            }

            return self::nodeImage($projectDir, $package);
        }

        return self::NODE_IMAGE;
    }

    /**
     * Node majors a project can resolve to, oldest first. Exposed for
     * HostPrewarmPlan.
     *
     * @return list<string>
     */
    public static function nodeMajors(): array
    {
        return NodeRuntime::majors();
    }

    /**
     * The official node tag from engines.node / .nvmrc / .node-version, full when the install compiles.
     * Delegated to NodeRuntime, so the compose file and the seeded image are one tag.
     */
    public static function nodeImage(string $projectDir, array $package = []): string
    {
        return NodeRuntime::imageFor($projectDir, $package);
    }

    /**
     * The default and newer php tags, plus composer; older minors are pulled on demand, saving disk.
     * A deploy resolves one tag, see preloadImagesFor().
     *
     * @return list<string>
     */
    public static function phpPreloadImages(): array
    {
        $images = [];
        $floor = PhpRuntime::normalizePhpVersion(PhpRuntime::defaultMinor());
        foreach (PhpRuntime::minors() as $minor) {
            if (version_compare($minor.'.0', $floor, '<')) {
                continue;
            }
            $images[] = PhpRuntime::imageTag($minor);
        }
        $images[] = self::COMPOSER_IMAGE;

        return $images;
    }
}
