<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Strategies;

/**
 * The catalogue of base images a deploy can resolve to, and which ones a given
 * project will pull. Per-project version selection lives on the runtimes; here
 * is the fixed set and the version-to-tag mapping.
 */
final class Images
{
    /**
     * What a project gets when it says nothing about Node: an older LTS.
     * engines.node, .nvmrc or .node-version is honoured; a silent project
     * usually has dependencies that break on newer Node (native bindings, old
     * webpack), and older runs nearly everything newer does.
     */
    public const NODE_IMAGE = NodeRuntime::IMAGE;

    public const NGINX_IMAGE = 'nginx:alpine';

    public const PHP_IMAGE = 'php:8.3-' . PhpRuntime::IMAGE_VARIANT;

    public const COMPOSER_IMAGE = 'composer:2';

    /**
     * Where the shared PHP base gets `install-php-extensions` from. Only that
     * build uses it, but the prewarmer and the seeder ask for it too.
     */
    public const PHP_EXTENSION_INSTALLER_IMAGE = 'mlocati/php-extension-installer';

    /**
     * Every PHP minor a deploy can resolve to, for HostPrewarmPlan -- the full
     * set, not phpPreloadImages()'s default-and-newer subset. Delegated so
     * this list cannot drift from PhpRuntime::MINORS.
     *
     * @return list<string>
     */
    public static function phpImageMinors(): array
    {
        return PhpRuntime::minors();
    }

    /**
     * Catalog of recipe base images. Not pulled at DinD boot -- the strategy is
     * unknown until clone and pulling PHP plus node:22 on a Vite/Node 20 site
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
     * Images this deploy actually needs. Static/railpack skip the pull loop via
     * DeployCompose::skipBuild(). With $projectDir set the Node tag follows
     * engines.node / .nvmrc, so node:22 is not pulled for a node:20 build.
     *
     * @return list<string>
     */
    public static function preloadImagesFor(?string $strategy, ?string $runtime = null, string $projectDir = ''): array
    {
        // No $runtime: skipBuild() treats an nginx runtime as nothing to build,
        // but the nginx image is what has to be warmed.
        if ($strategy === null || DeployCompose::skipBuild($strategy)) {
            return [];
        }

        $manifest = PlatformRegistry::findByStrategy($strategy, $runtime);
        if ($manifest === null || $manifest->requires === []) {
            return [];
        }

        // Lenient: the prewarmer asks what a Go app needs before any Go app
        // exists, so an unreadable project means the default.
        $requirements = RuntimeRegistry::resolveAll(
            $manifest->requires,
            ProjectContext::make($projectDir, ProjectContext::listRootFiles($projectDir)),
            true
        );

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
        // A build-only toolchain leaves nothing behind; name the serving image
        // separately.
        if ($manifest->runtime === PlatformManifest::RUNTIME_NGINX) {
            $images[] = self::NGINX_IMAGE;
        }

        return array_values(array_unique($images));
    }

    /**
     * The image a generated Dockerfile builds FROM, given the tag the pipeline
     * resolved: a project whose install compiles a dependency gets the full
     * variant of that tag. Applied to the pipeline's tag, not computed here, so
     * host compile and account build cannot differ in variant.
     */
    public static function nodeBuildImage(string $runtimeImage, string $projectDir): string
    {
        $runtimeImage = trim($runtimeImage);
        // Not an official Node tag (bun, Python, JDK, a pinned image).
        if (!str_starts_with($runtimeImage, 'node:')) {
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
     * Official node tag from engines.node / .nvmrc / .node-version, delegated so
     * it cannot drift from NodeRuntime's parser. A project whose install compiles
     * a dependency gets the full variant of the same major, for every caller at
     * once: the compose file and the seeded image must be one tag.
     */
    public static function nodeImage(string $projectDir, array $package = []): string
    {
        return NodeRuntime::imageFor($projectDir, $package);
    }


    /**
     * Every php tag we might need, plus composer. Catalog and last resort: a
     * deploy resolves one tag, see preloadImagesFor().
     *
     * @return list<string>
     */
    public static function phpPreloadImages(): array
    {
        $images = [];
        $floor = PhpRuntime::normalizePhpVersion(PhpRuntime::defaultMinor());
        foreach (PhpRuntime::minors() as $minor) {
            // Default and newer: older minors are pulled on demand, and warming
            // all five costs disk for versions most hosts never serve.
            if (version_compare($minor . '.0', $floor, '<')) {
                continue;
            }
            $images[] = PhpRuntime::imageTag($minor);
        }
        $images[] = self::COMPOSER_IMAGE;

        return $images;
    }

}
