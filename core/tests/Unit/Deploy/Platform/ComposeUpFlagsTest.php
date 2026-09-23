<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\Strategies;
use PHPUnit\Framework\TestCase;

/**
 * Which build flag `docker compose up` gets, per strategy.
 *
 * `up` on its own builds only when the image is missing. On a redeploy the
 * account's inner daemon still holds last deploy's image, so a strategy that
 * generates a Dockerfile has to ask for a rebuild explicitly or the account
 * keeps serving the previous commit — with a green deploy and nothing in the
 * log to say otherwise.
 *
 * The inverse matters too: strategies whose image is built elsewhere (railpack
 * builds it with buildx; nginx and Nitro serve prebuilt output) must be told
 * *not* to build, or compose tries to build a service that has no context.
 */
class ComposeUpFlagsTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string}> */
    public static function buildingStrategies(): array
    {
        return [
            // Ruby has its own strategy class and still builds an image;
            // a repository's own Dockerfile or compose file always does.
            'rails'      => [Strategies::RAILS, PlatformManifest::RUNTIME_COMMAND],
            'dockerfile' => [Strategies::DOCKERFILE, PlatformManifest::RUNTIME_DOCKERFILE],
            'compose'    => [Strategies::COMPOSE, PlatformManifest::RUNTIME_COMPOSE],
        ];
    }

    /**
     * @dataProvider buildingStrategies
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('buildingStrategies')]
    public function test_a_strategy_that_generates_an_image_must_rebuild_on_redeploy(
        string $strategy,
        ?string $runtime
    ): void {
        $this->assertFalse(
            DeployCompose::skipBuild($strategy, $runtime),
            "{$strategy} builds its own image, so `up` must be told to rebuild it"
        );
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function nonBuildingStrategies(): array
    {
        return [
            'railpack'         => [Strategies::RAILPACK, null],
            'static'           => [Strategies::STATIC, PlatformManifest::RUNTIME_NGINX],
            'fallback'         => [Strategies::FALLBACK, null],
            'vite (nginx)'     => [Strategies::VITE, PlatformManifest::RUNTIME_NGINX],
            'nuxt (nitro)'     => [Strategies::NUXT, PlatformManifest::RUNTIME_NODE],
            // Runs our stock Node image against the mounted project — no
            // build context, so `up --build` would fail rather than no-op.
            'nextjs (mounted)' => [Strategies::NEXTJS, PlatformManifest::RUNTIME_NODE],
            'nestjs (mounted)' => [Strategies::NESTJS, PlatformManifest::RUNTIME_NODE],
            'express (mounted)' => [Strategies::EXPRESS, PlatformManifest::RUNTIME_NODE],
            'go (mounted)' => [Strategies::GO, PlatformManifest::RUNTIME_COMMAND],
            'java (mounted)' => [Strategies::JAVA, PlatformManifest::RUNTIME_COMMAND],
            'sveltekit (mounted)' => [Strategies::SVELTEKIT, PlatformManifest::RUNTIME_NODE],
            'django (mounted)' => [Strategies::DJANGO, PlatformManifest::RUNTIME_COMMAND],
            'tanstack (nitro)' => [Strategies::TANSTACK, PlatformManifest::RUNTIME_NODE],
            // PHP runs the shared base image with ~/project bind-mounted. The
            // service has no `build:` key at all, so `up --build` has nothing
            // to build and fails on the service instead.
            'laravel'          => [Strategies::LARAVEL, PlatformManifest::RUNTIME_PHP],
            'php'              => [Strategies::PHP, PlatformManifest::RUNTIME_PHP],
        ];
    }

    /**
     * @dataProvider nonBuildingStrategies
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonBuildingStrategies')]
    public function test_a_strategy_whose_image_comes_from_elsewhere_must_not_build(
        string $strategy,
        ?string $runtime
    ): void {
        $this->assertTrue(
            DeployCompose::skipBuild($strategy, $runtime),
            "{$strategy}'s image is not built by compose, so `up` must be told not to try"
        );
    }

    /**
     * The two flags are mutually exclusive and one of them is always sent —
     * leaving both off is what produced the stale-image bug.
     */
    public function test_every_strategy_gets_exactly_one_build_decision(): void
    {
        foreach (Strategies::ALL as $strategy) {
            $skip = DeployCompose::skipBuild($strategy);
            $this->assertIsBool($skip, "{$strategy} has no build decision");
        }
    }

    /**
     * Not building means `up` has nothing to notice: same image, same mount,
     * same environment, so it leaves the previous container running — and
     * with it the previous entrypoint. The code is live because the code is a
     * bind mount; `migrate` and an edited `.panelalpha/entrypoint.sh` are not.
     */
    public function test_php_must_replace_its_container_even_though_nothing_built(): void
    {
        foreach ([Strategies::PHP, Strategies::LARAVEL] as $strategy) {
            $this->assertTrue(
                DeployCompose::forceRecreate($strategy, PlatformManifest::RUNTIME_PHP),
                "{$strategy} must be recreated or its entrypoint never re-runs"
            );
        }
    }

    /**
     * Nitro deletes `.output` and writes a new one on every build. A container
     * that is not recreated keeps a bind mount of the deleted directory: the
     * server already in memory answers `/`, every static file answers 500.
     */
    public function test_nitro_output_must_replace_its_container(): void
    {
        foreach ([Strategies::NUXT, Strategies::TANSTACK] as $strategy) {
            $this->assertTrue(
                DeployCompose::forceRecreate($strategy, PlatformManifest::RUNTIME_NODE),
                "{$strategy} must be recreated or it keeps the deleted .output mounted"
            );
        }
    }

    /**
     * Everything else either builds an image — which recreates the container
     * on its own — or serves prebuilt output that a restart would not change.
     */
    public function test_nothing_else_is_force_recreated(): void
    {
        $runtimes = [
            Strategies::RAILPACK => null,
            Strategies::STATIC => PlatformManifest::RUNTIME_NGINX,
            // Vite empties `dist/` but keeps the directory, so the mount holds.
            Strategies::VITE => PlatformManifest::RUNTIME_NGINX,
        ];
        foreach ($runtimes as $strategy => $runtime) {
            $this->assertFalse(DeployCompose::forceRecreate($strategy, $runtime), $strategy);
        }
    }
}
