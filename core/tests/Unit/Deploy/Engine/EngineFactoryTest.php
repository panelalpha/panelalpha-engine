<?php

namespace Tests\Unit\Deploy\Engine;

use App\Lib\Deploy\Dind\DindEngine;
use App\Lib\Deploy\Engine\AccountStorage;
use App\Lib\Deploy\Engine\BuildMemory;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Engine\EngineFactory;
use App\Lib\Deploy\Engine\HostBuilder;
use App\Lib\Deploy\Engine\ImageStore;
use App\Lib\Deploy\Engine\UnknownEngineException;
use PHPUnit\Framework\TestCase;

/**
 * The factory is the seam a second engine arrives through, so what is pinned
 * here is that arriving takes a registration and nothing else — no deploy
 * code, no edits to the port.
 */
class EngineFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        EngineFactory::reset();
        parent::tearDown();
    }

    public function test_dind_is_available_without_any_registration(): void
    {
        EngineFactory::reset();

        $engine = EngineFactory::make(EngineFactory::DIND);

        $this->assertInstanceOf(DindEngine::class, $engine);
        $this->assertSame('dind', $engine->name());
        $this->assertContains('dind', EngineFactory::names());
    }

    public function test_default_is_dind_when_nothing_is_configured(): void
    {
        $this->assertSame('dind', EngineFactory::default()->name());
    }

    public function test_an_engine_is_resolved_once_and_reused(): void
    {
        $this->assertSame(EngineFactory::make('dind'), EngineFactory::make('dind'));
    }

    public function test_names_are_matched_case_and_space_insensitively(): void
    {
        $this->assertSame(EngineFactory::make('dind'), EngineFactory::make('  DinD '));
    }

    public function test_an_unregistered_engine_fails_loudly(): void
    {
        $this->expectException(UnknownEngineException::class);
        $this->expectExceptionMessageMatches('/podman/');

        EngineFactory::make('podman');
    }

    /**
     * The point of the whole exercise: a second engine plugs in through
     * registration, and every deploy caller keeps talking to the port.
     */
    public function test_a_new_engine_can_be_registered_and_selected(): void
    {
        EngineFactory::register('podman', fn (): ContainerEngine => new FakeEngine());

        $engine = EngineFactory::make('podman');

        $this->assertTrue(EngineFactory::has('podman'));
        $this->assertSame('podman', $engine->name());
        $this->assertSame(
            ['podman', 'image', 'inspect', 'nginx:alpine'],
            $engine->images()->imageIdArgv('nginx:alpine')
        );
    }

    public function test_registering_over_an_existing_name_replaces_it(): void
    {
        $first = EngineFactory::make('dind');
        EngineFactory::register('dind', fn (): ContainerEngine => new FakeEngine());

        $this->assertNotSame($first, EngineFactory::make('dind'));
    }
}

/**
 * A stand-in for the engine that does not exist yet. It only has to satisfy
 * the port — which is the assertion.
 */
final class FakeEngine implements ContainerEngine
{
    public function name(): string
    {
        return 'podman';
    }

    public function images(): ImageStore
    {
        return new class implements ImageStore {
            public function hostBuildCommand(string $tag, string $dockerfile, bool $rebuild = false): string
            {
                return 'buildah bud';
            }

            public function hostPublishCommand(string $image): string
            {
                return 'skopeo copy';
            }

            public function loadFromHostCommand(EngineAccount $account, string $image): string
            {
                return 'skopeo copy';
            }

            public function seedCommand(EngineAccount $account, string $image, bool $ours): string
            {
                return 'skopeo copy';
            }

            public function parallelImportCommand(EngineAccount $account, array $images, int $concurrency): string
            {
                return 'true';
            }

            public function listImagesArgv(): array
            {
                return ['podman', 'images'];
            }

            public function registryConfigArgv(): array
            {
                return ['true'];
            }

            public function imageIdArgv(string $image): array
            {
                return ['podman', 'image', 'inspect', $image];
            }

            public function imageExposedPortsArgv(string $image): array
            {
                return ['podman', 'image', 'inspect', $image];
            }

            public function hostImageInspectArgv(string $image): array
            {
                return ['podman', 'image', 'inspect', $image];
            }

            public function hostImageExposedPortsArgv(string $image): array
            {
                return ['podman', 'image', 'inspect', $image];
            }
        };
    }

    public function hostBuilder(?int $projectMemoryMb = null): HostBuilder
    {
        return new class implements HostBuilder {
            public function nodeBuildArgv(
                EngineAccount $account,
                string $image,
                string $install,
                string $build,
                array $env = [],
                bool $isolateNodeModules = true,
                bool $isNode = true,
                string $appRoot = ''
            ): array {
                return ['podman', 'run'];
            }

            public function phpBuildArgv(
                EngineAccount $account,
                string $image,
                string $script,
                string $appRoot = '',
                bool $withCache = true,
                ?string $manifest = null
            ): array {
                return ['podman', 'run'];
            }

            public function composerInstallArgv(
                EngineAccount $account,
                ?string $phpVersion = null,
                ?string $manifest = null,
                string $appRoot = ''
            ): array {
                return ['podman', 'run'];
            }

            public function prepareCacheArgv(EngineAccount $account): array
            {
                return ['mkdir'];
            }

            public function memoryLimitMb(): int
            {
                return 2048;
            }

            public function memoryOrigin(): BuildMemory
            {
                return new BuildMemory('2g');
            }
        };
    }

    public function storage(): AccountStorage
    {
        return new class implements AccountStorage {
            public function dataRoot(): string
            {
                return '$HOME/.local/share/containers';
            }

            public function freeSpaceProbeArgv(): array
            {
                return ['df'];
            }

            public function parseFreeBytes(string $output): ?int
            {
                return null;
            }

            public function buildCacheProbeArgv(): array
            {
                return ['podman', 'system', 'df'];
            }

            public function parseBuildCacheBytes(string $output): ?int
            {
                return null;
            }

            public function containersProbeArgv(): array
            {
                return ['podman', 'ps', '-aq'];
            }

            public function reclaimArgvs(): array
            {
                return [['podman', 'system', 'prune', '-af']];
            }

            public function fullWipeScript(): string
            {
                return 'true';
            }

            public function pruneAllArgv(): array
            {
                return ['podman', 'system', 'prune'];
            }

            public function pruneBuildCacheArgv(): array
            {
                return [['podman', 'system', 'prune']];
            }

            public function stopEngineArgv(): array
            {
                return ['true'];
            }

            public function hostCleanupArgv(EngineAccount $account, array $sidecarRefs = []): array
            {
                return ['true'];
            }
        };
    }
}
