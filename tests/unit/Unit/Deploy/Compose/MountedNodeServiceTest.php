<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\FrameworkService;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\Runtime\HostRunProject;
use App\Lib\Deploy\Platform\Strategies;
use PHPUnit\Framework\TestCase;

/**
 * A Node project served from our stock image with the account's directory
 * mounted — the same shape PHP has had, and the one that removes the
 * per-project image build along with the 180.7s export that came with it.
 */
class MountedNodeServiceTest extends TestCase
{
    /** @return array<string, mixed> */
    private function service(array $overrides = []): array
    {
        return FrameworkService::for([
            'strategy' => Strategies::NEXTJS,
            'runtime' => PlatformManifest::RUNTIME_NODE,
            'image' => 'node:20-bookworm-slim',
            'start_command' => 'yarn start',
        ] + $overrides, 3000);
    }

    public function test_it_runs_a_stock_image_with_the_project_mounted_and_builds_nothing(): void
    {
        $service = $this->service();

        $this->assertSame('node:20-bookworm-slim', $service['image']);
        $this->assertArrayNotHasKey('build', $service, 'a mounted project has no build context');
        $this->assertContains('./:/app', $service['volumes']);
        // Through a shell, so a start command with a glob or an `&&` works;
        // `exec` so the app keeps PID 1 and its signals.
        $this->assertSame(['sh', '-c', 'exec yarn start'], $service['command']);
        $this->assertSame('/app', $service['working_dir']);
    }

    /**
     * Next.js writes .next/cache at runtime for ISR and image optimisation.
     * The Nitro path can mount read-only because a bundled server writes
     * nothing; this one cannot, and a `:ro` here would surface as 500s at
     * request time rather than as a failed deploy.
     */
    public function test_the_mount_is_writable(): void
    {
        foreach ($this->service()['volumes'] as $volume) {
            $this->assertStringEndsNotWith(':ro', $volume);
        }
    }

    public function test_it_runs_as_the_account_when_one_is_given(): void
    {
        $this->assertArrayNotHasKey('user', $this->service());
        $this->assertSame('1001:1001', $this->service(['user' => '1001:1001'])['user']);
    }

    public function test_compose_is_told_not_to_build_and_to_recreate(): void
    {
        // No build context, so `up --build` would fail rather than no-op.
        $this->assertTrue(DeployCompose::skipBuild(Strategies::NEXTJS, PlatformManifest::RUNTIME_NODE));
        // The definition never changes between deploys, so without this the
        // old process keeps serving the previous build from the same mount.
        $this->assertTrue(DeployCompose::forceRecreate(Strategies::NEXTJS, PlatformManifest::RUNTIME_NODE));
    }

    public function test_only_the_named_strategies_take_this_path(): void
    {
        foreach ([
            Strategies::NEXTJS,
            Strategies::NESTJS,
            Strategies::EXPRESS,
            Strategies::FASTIFY,
            Strategies::REMIX,
            Strategies::SVELTEKIT,
            Strategies::ASTRO,
        ] as $node) {
            $this->assertTrue(HostRunProject::isStrategy($node), $node);
        }

        // Nitro output is served from `.output` by StandaloneNodeServe, a
        // narrower mount that already builds nothing.
        $this->assertFalse(HostRunProject::isStrategy(Strategies::NUXT));
        $this->assertFalse(HostRunProject::isStrategy(Strategies::TANSTACK));
        foreach ([Strategies::DJANGO, Strategies::PYTHON] as $command) {
            $this->assertTrue(HostRunProject::isStrategy($command), $command);
        }
        $this->assertTrue(HostRunProject::isStrategy(Strategies::GO));
        $this->assertTrue(HostRunProject::isStrategy(Strategies::JAVA));
        $this->assertTrue(HostRunProject::isStrategy(Strategies::RUST));
        // Ruby keeps its own strategy class, and its own build.
        $this->assertFalse(HostRunProject::isStrategy(Strategies::RAILS));
        $this->assertFalse(HostRunProject::isStrategy(Strategies::DOCKERFILE));
        $this->assertFalse(HostRunProject::isStrategy(null));

        // A repository that ships its own Dockerfile or compose file keeps
        // building: that is the customer describing their own image.
        $this->assertFalse(DeployCompose::isHostCompiled(['strategy' => Strategies::DOCKERFILE]));
        $this->assertFalse(DeployCompose::isHostCompiled(['strategy' => Strategies::COMPOSE]));
    }

    public function test_a_recipe_with_no_start_command_omits_it_rather_than_inventing_one(): void
    {
        $service = FrameworkService::for([
            'strategy' => Strategies::NEXTJS,
            'runtime' => PlatformManifest::RUNTIME_NODE,
            'image' => 'node:20-bookworm-slim',
        ], 3000);

        $this->assertArrayNotHasKey('command', $service);
    }

    /**
     * A start command that is a small script keeps its own control flow.
     *
     * Java's picks the runnable jar out of several and ends with its own
     * `exec`. Prefixing another one turned it into `exec jar="$(ls …)"` and
     * the container restart-looped on `sh: exec: jar=: not found`; and its
     * `$jar` was eaten by compose interpolation before the shell saw it.
     */
    public function test_a_compound_start_command_is_not_given_a_second_exec(): void
    {
        $script = 'jar="$(ls -S target/*.jar | head -1)"; [ -n "$jar" ] || exit 1; exec java -jar "$jar"';
        $service = FrameworkService::for([
            'strategy' => Strategies::GO,
            'runtime' => PlatformManifest::RUNTIME_COMMAND,
            'image' => 'golang:1.27-alpine',
            'start_command' => $script,
        ], 8080);

        [$sh, $flag, $rendered] = $service['command'];
        $this->assertSame(['sh', '-c'], [$sh, $flag]);
        $this->assertStringStartsNotWith('exec jar=', $rendered);
        // Doubled so compose hands the shell a literal $jar.
        $this->assertStringContainsString('$$jar', $rendered);
        $this->assertStringNotContainsString('$jar"', str_replace('$$jar', '', $rendered));
    }

    public function test_a_simple_start_command_still_gets_exec(): void
    {
        $service = FrameworkService::for([
            'strategy' => Strategies::NEXTJS,
            'runtime' => PlatformManifest::RUNTIME_NODE,
            'image' => 'node:20-bookworm-slim',
            'start_command' => 'yarn start',
        ], 3000);

        $this->assertSame(['sh', '-c', 'exec yarn start'], $service['command']);
    }
}
