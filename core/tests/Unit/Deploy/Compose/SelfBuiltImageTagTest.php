<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\GeneratedCompose;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The tag a repository gives the image its own compose builds.
 *
 * dpaste ships a compose file whose `migration` service declares `image: app`
 * and no build — a reference to the image the app service builds. The engine's
 * generated file omitted `image:` on the app service, so `docker compose up`
 * read `app` as a registry reference, tried to pull it, and died with
 *
 *     pull access denied for app, repository does not exist or may require
 *     'docker login'
 *
 * twice: once for the service and once for the pre-pull, which read the same
 * invented tag back out of the compose file.
 *
 * The fix is to build *under the repo's own name*, so the reference resolves to
 * the image the build is about to produce. Only ever a bare, local name: a
 * build tagged `ghcr.io/owner/app` is naming a registry, and pinning that would
 * make the build produce something it was told to fetch. Nothing at that name
 * exists on any registry, so reading it back would have provisioning seed a tag
 * only this deploy can create.
 */
class SelfBuiltImageTagTest extends TestCase
{
    /**
     * @return array<string, mixed> the generated `app` service
     */
    private function app(string $yaml): array
    {
        return Yaml::parse($yaml)['services']['app'];
    }

    public function test_a_build_image_names_the_image_the_app_service_builds(): void
    {
        $service = $this->app(DeployCompose::dockerfile('Dockerfile.web', 8000, ['build_image' => 'app']));

        $this->assertSame('app', $service['image']);
        $this->assertSame('.', $service['build']['context']);
        $this->assertSame('Dockerfile.web', $service['build']['dockerfile']);
        $this->assertSame(['8000:8000'], $service['ports']);
    }

    /**
     * The root Dockerfile keeps the `build: .` shorthand — naming the image
     * must not force the map form the common case deliberately avoids.
     */
    public function test_the_default_dockerfile_keeps_the_build_shorthand(): void
    {
        $service = $this->app(DeployCompose::dockerfile('Dockerfile', 8000, ['build_image' => 'app']));

        $this->assertSame('app', $service['image']);
        $this->assertSame('.', $service['build']);
    }

    /** No `build_image`, no `image:` — the file is unchanged for everyone else. */
    public function test_without_a_build_image_no_image_is_named(): void
    {
        $this->assertArrayNotHasKey('image', $this->app(DeployCompose::dockerfile('Dockerfile', 8000)));
        $this->assertArrayNotHasKey(
            'image',
            $this->app(DeployCompose::dockerfile('Dockerfile', 8000, ['env' => ['A' => 'b']]))
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function registryPinnedNames(): array
    {
        return [
            'registry host' => ['ghcr.io/owner/app'],
            'a digest' => ['img@sha256:' . str_repeat('a', 64)],
            'a nested name' => ['owner/app'],
            'empty' => [''],
            'whitespace only' => ['   '],
            'a leading dash' => ['-app'],
            'an embedded space' => ['my app'],
        ];
    }

    /**
     * A registry-pinned name is refused, not normalised: the build must never
     * be made to produce a tag we would be fetching from elsewhere.
     */
    #[DataProvider('registryPinnedNames')]
    public function test_a_registry_pinned_build_image_is_refused(string $declared): void
    {
        $service = $this->app(DeployCompose::dockerfile('Dockerfile', 8000, ['build_image' => $declared]));

        $this->assertArrayNotHasKey('image', $service, "'{$declared}' is not a local build tag");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function localBuildTags(): array
    {
        return [
            'bare name' => ['app'],
            'a version tag' => ['app:1.0'],
            'latest' => ['myapp:latest'],
            'a dotted name' => ['my.app:dev'],
        ];
    }

    /**
     * A tag on a local name is kept, tag and all.
     *
     * `image: myapp:latest` beside a `build:` is the commoner spelling of
     * `image: myapp`, and it names no registry — a registry reference needs a
     * path, so without a `/` what follows the last colon can only be a tag.
     * Refusing it left the sibling that says `image: myapp:latest` pointing at
     * something nothing builds, and the deploy died on `pull access denied`.
     */
    #[DataProvider('localBuildTags')]
    public function test_a_local_build_tag_is_kept_with_its_tag(string $declared): void
    {
        $service = $this->app(DeployCompose::dockerfile('Dockerfile', 8000, ['build_image' => $declared]));

        $this->assertSame($declared, $service['image'] ?? null, "'{$declared}' is a local build tag");
    }

    /**
     * The sibling's reference and the build's own tag are compared through one
     * normaliser, so the two spellings of the same image match.
     */
    public function test_a_sibling_referring_to_the_untagged_name_still_matches(): void
    {
        $compose = <<<'YAML'
        services:
          web:
            build: .
            image: myapp:latest
          worker:
            image: myapp:latest
            command: worker
        YAML;

        $this->assertSame('myapp:latest', DeployCompose::builtImageNameFromYaml($compose));
    }

    /**
     * The reference itself is a sibling service in the same file, so excluding
     * it from the pre-pull is the only reliable signal that it is local: a bare
     * `redis` is indistinguishable from a Docker Hub official image by shape.
     */
    public function test_image_refs_skip_an_image_the_same_file_builds(): void
    {
        $compose = <<<'YAML'
        services:
          app:
            build: .
            image: app
          migration:
            image: app
          db:
            image: postgres:16
        YAML;

        $refs = DeployCompose::imageRefs($compose);

        $this->assertSame(['postgres:16'], $refs);
        $this->assertNotContains('app:latest', $refs);
    }

    /** docker-vscode-server: every service is behind a profile nobody set. */
    public function test_image_refs_skip_a_service_behind_an_inactive_profile(): void
    {
        $compose = <<<'YAML'
        services:
          lint:
            image: oxsecurity/megalinter:v7.3.0
            profiles: ["dev"]
          readme:
            image: pandoc/minimal:2.19
            profiles: [dev, docs]
          db:
            image: postgres:16
        YAML;

        $this->assertSame(['postgres:16'], DeployCompose::imageRefs($compose, activeProfiles: []));
        $this->assertSame(['pandoc/minimal:2.19', 'postgres:16'], DeployCompose::imageRefs($compose, activeProfiles: ['docs']));
        $this->assertCount(3, DeployCompose::imageRefs($compose, activeProfiles: ['*']));
        // Teardown asks without profiles: every image the file names.
        $this->assertCount(3, DeployCompose::imageRefs($compose));
    }

    /** The dpaste shape: the app service builds and another service names it. */
    public function test_built_image_name_from_yaml_reads_the_reference_shape(): void
    {
        $compose = <<<'YAML'
        services:
          app:
            build: .
            image: app
          migration:
            image: app
        YAML;

        $this->assertSame('app', DeployCompose::builtImageNameFromYaml($compose));
    }

    /**
     * The app service is the one being deployed, so its own tag is always the
     * name to build under — it does not need a sibling to reference it.
     */
    public function test_the_app_services_own_tag_is_returned_without_a_reference(): void
    {
        $this->assertSame('app', DeployCompose::builtImageNameFromYaml(<<<'YAML'
        services:
          app:
            build: .
            image: app
          db:
            image: postgres:16
        YAML));
    }

    /**
     * A tag nothing names is not load-bearing. A build service that is not the
     * app service is only identified by a sibling's reference to it.
     */
    public function test_a_non_app_build_service_needs_a_sibling_reference(): void
    {
        $this->assertNull(DeployCompose::builtImageNameFromYaml(<<<'YAML'
        services:
          web:
            build: .
            image: unbuilt
          db:
            image: postgres:16
        YAML));
    }

    /** Nothing to read at all is answered with nothing. */
    public function test_built_image_name_from_yaml_tolerates_shapeless_input(): void
    {
        // No `image:` on the build service.
        $this->assertNull(DeployCompose::builtImageNameFromYaml(<<<'YAML'
        services:
          app:
            build: .
        YAML));

        // Nothing parseable to read.
        $this->assertNull(DeployCompose::builtImageNameFromYaml('this: [is: not: valid'));
        $this->assertNull(DeployCompose::builtImageNameFromYaml(''));
    }

    /**
     * A project whose build service is not called `app`: the sibling's
     * reference is what identifies the tag, so it is still found.
     */
    public function test_a_build_service_whose_tag_a_sibling_names_is_found(): void
    {
        $compose = <<<'YAML'
        services:
          web:
            build: .
            image: dpaste
          migration:
            image: dpaste
        YAML;

        $this->assertSame('dpaste', DeployCompose::builtImageNameFromYaml($compose));
    }

    /** A registry-pinned build is left alone however it is referenced. */
    public function test_built_image_name_from_yaml_ignores_a_registry_pinned_build(): void
    {
        $this->assertNull(DeployCompose::builtImageNameFromYaml(<<<'YAML'
        services:
          app:
            build: .
            image: ghcr.io/owner/app
          migration:
            image: ghcr.io/owner/app
        YAML));
    }

    /**
     * A service that builds has no pullable runtime image: its `image:` is the
     * local tag the build is given, and reading it back would have the snapshot
     * provision a tag only this deploy can create.
     */
    public function test_app_image_is_null_when_the_app_service_builds(): void
    {
        $this->assertNull(GeneratedCompose::appImage(<<<'YAML'
        services:
          app:
            build: .
            image: app
        YAML));
    }

    /** A service that does not build is still read back as before. */
    public function test_app_image_is_still_read_for_a_service_that_does_not_build(): void
    {
        $this->assertSame(
            'panelalpha/php:8.3-apache-bookworm-pa01234567',
            GeneratedCompose::appImage(<<<'YAML'
            services:
              app:
                image: panelalpha/php:8.3-apache-bookworm-pa01234567
            YAML)
        );
    }

    /**
     * The two halves together: the generated app service carries the tag the
     * repo's own references need, and that tag is not mistaken for something to
     * pull when the snapshot is read back.
     */
    public function test_the_generated_build_service_is_named_but_not_pullable(): void
    {
        $yaml = DeployCompose::dockerfile('Dockerfile', 8000, ['build_image' => 'app']);

        $this->assertSame('app', $this->app($yaml)['image']);
        $this->assertNull(GeneratedCompose::appImage($yaml));
    }
}
