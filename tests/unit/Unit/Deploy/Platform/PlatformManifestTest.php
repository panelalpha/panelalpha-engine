<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Manifest validation.
 *
 * A manifest ships with the engine, so a malformed one is a packaging bug.
 * These tests pin the errors that must surface at load time rather than as
 * odd behaviour on the one deploy that reaches the platform.
 */
class PlatformManifestTest extends TestCase
{
    private function make(array $overrides = []): PlatformManifest
    {
        return PlatformManifest::fromArray(array_merge([
            'id' => 'demo',
            'label' => 'Demo',
            'priority' => 100,
            'runtime' => 'command',
            'detect' => ['file' => 'demo.json'],
            'commands' => [],
        ], $overrides));
    }

    public function test_strategy_defaults_to_the_id_but_can_be_shared(): void
    {
        $this->assertSame('demo', $this->make()->strategy);
        $this->assertSame('astro', $this->make(['id' => 'astro-ssr', 'strategy' => 'astro'])->strategy);
    }

    public function test_a_stage_may_be_a_name_or_a_list(): void
    {
        $manifest = $this->make(['commands' => [
            ['id' => 'one', 'stage' => 'build', 'run' => 'a'],
            ['id' => 'two', 'stage' => ['install', 'upgrade'], 'run' => 'b'],
        ]]);

        $this->assertSame(['build'], $manifest->commands[0]->stages);
        $this->assertSame(['install', 'upgrade'], $manifest->commands[1]->stages);
    }

    public function test_an_unknown_stage_is_rejected(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/unknown stage .deploy./');
        $this->make(['commands' => [['stage' => 'deploy', 'run' => 'a']]]);
    }

    public function test_a_command_without_a_stage_is_rejected(): void
    {
        $this->expectException(ManifestException::class);
        $this->make(['commands' => [['run' => 'a']]]);
    }

    /**
     * Two serve commands means two processes claiming PID 1; the second would
     * never run and the failure would look like a silently ignored config.
     */
    public function test_only_one_command_may_serve(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/only one command may be marked/');
        $this->make(['commands' => [
            ['id' => 'a', 'stage' => 'start', 'serve' => true, 'run' => 'a'],
            ['id' => 'b', 'stage' => 'start', 'serve' => true, 'run' => 'b'],
        ]]);
    }

    public function test_a_serve_command_must_belong_to_the_start_stage(): void
    {
        $this->expectException(ManifestException::class);
        $this->make(['commands' => [['stage' => 'upgrade', 'serve' => true, 'run' => 'a']]]);
    }

    public function test_a_build_layer_role_is_meaningless_on_a_runtime_command(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/only applies to build-stage/');
        $this->make(['commands' => [['stage' => 'start', 'role' => 'dependencies', 'run' => 'a']]]);
    }

    public function test_duplicate_command_ids_are_rejected(): void
    {
        $this->expectException(ManifestException::class);
        $this->make(['commands' => [
            ['id' => 'same', 'stage' => 'build', 'run' => 'a'],
            ['id' => 'same', 'stage' => 'start', 'run' => 'b'],
        ]]);
    }

    public function test_an_id_is_derived_when_a_command_does_not_declare_one(): void
    {
        $manifest = $this->make(['commands' => [
            ['stage' => 'upgrade', 'run' => 'php artisan migrate --force'],
        ]]);

        $this->assertSame('php-artisan-migrate', $manifest->commands[0]->id);
    }

    public function test_an_empty_detect_block_is_rejected(): void
    {
        $this->expectException(ManifestException::class);
        $this->make(['detect' => []]);
    }

    public function test_an_invalid_runtime_is_rejected(): void
    {
        $this->expectException(ManifestException::class);
        $this->make(['runtime' => 'wasm']);
    }

    /**
     * `image_form` for `image_from` would otherwise leave the platform on its
     * default image and fail somewhere else entirely, with nothing anywhere
     * saying the manifest was wrong.
     */
    public function test_a_misspelled_key_is_rejected_rather_than_ignored(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/unknown key\(s\) image_form/');
        $this->make(['image_form' => 'go']);
    }

    public function test_the_serve_command_sorts_last_within_the_start_stage(): void
    {
        $manifest = $this->make(['commands' => [
            ['id' => 'serve', 'stage' => 'start', 'serve' => true, 'run' => 'serve'],
            ['id' => 'optimize', 'stage' => 'start', 'run' => 'optimize'],
        ]]);

        $ids = array_map(static fn ($c) => $c->id, $manifest->stage('start'));
        $this->assertSame(['optimize', 'serve'], $ids);
    }

    /**
     * Detection records the platform it matched as an id, because the decision
     * is persisted and an id survives that. Both the entrypoint writer and the
     * prepare stage need the manifest back; they used to each keep their own
     * copy of this lookup, differing on the empty-string case.
     */
    public function test_a_decision_names_the_manifest_it_came_from(): void
    {
        $manifest = PlatformRegistry::forDecision(['platform' => 'laravel']);

        $this->assertNotNull($manifest);
        $this->assertSame('laravel', $manifest->id);
    }

    public function test_a_decision_no_manifest_produced_resolves_to_none(): void
    {
        // Railpack and the fallback reach the generators with no platform at all.
        $this->assertNull(PlatformRegistry::forDecision([]));
        $this->assertNull(PlatformRegistry::forDecision(['platform' => null]));
        $this->assertNull(PlatformRegistry::forDecision(['platform' => '']));
    }

    /**
     * A decision frozen by an older deploy can name a platform that has since
     * been removed. That is a manifest-less deploy, not a crash.
     */
    public function test_a_platform_that_no_longer_exists_resolves_to_none(): void
    {
        $this->assertNull(PlatformRegistry::forDecision(['platform' => 'no-such-platform']));
    }

    public function test_a_platform_that_is_not_a_string_resolves_to_none(): void
    {
        $this->assertNull(PlatformRegistry::forDecision(['platform' => ['laravel']]));
        $this->assertNull(PlatformRegistry::forDecision(['platform' => 42]));
    }
}
