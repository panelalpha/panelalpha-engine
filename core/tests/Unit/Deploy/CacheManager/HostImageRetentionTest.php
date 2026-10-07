<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\CacheManager\HostImageRetention;
use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\ImageCatalog;
use PHPUnit\Framework\TestCase;

/**
 * What the daily host prune may take. Refs come from the shipped
 * catalogue rather than being spelled out, so an edited images.yaml does not
 * turn these into tests of the config.
 */
class HostImageRetentionTest extends TestCase
{
    private const NOW = 1790000000;

    private const DAY = 86400;

    public function test_an_old_unlisted_php_variant_is_a_candidate(): void
    {
        $variant = $this->plainPhpBase() . '-x0123abcd';
        $this->assertTrue(BuiltImage::isVariant($variant));

        $candidates = $this->candidates([$this->row($variant, 5 * self::DAY)]);

        $this->assertSame([$variant], array_keys($candidates));
        $this->assertSame(self::NOW - 5 * self::DAY, $candidates[$variant]['since']);
    }

    public function test_the_prewarm_catalogue_is_never_a_candidate_however_old(): void
    {
        $rows = array_map(
            fn (string $ref): array => $this->row($ref, 400 * self::DAY),
            $this->prewarmed()
        );
        $this->assertNotSame([], $rows);

        $this->assertSame([], $this->candidates($rows));
    }

    public function test_an_old_runtime_image_outside_the_catalogue_is_a_candidate(): void
    {
        $runtime = $this->prewarmedOfRepository('golang');
        $unlisted = HostImageRetention::repository($runtime) . ':1.99-alpine';

        $this->assertSame([$unlisted], array_keys($this->candidates([$this->row($unlisted, 4 * self::DAY)])));
    }

    public function test_an_image_pulled_or_built_inside_the_window_is_kept(): void
    {
        $variant = $this->plainPhpBase() . '-x0123abcd';

        $this->assertSame([], $this->candidates([$this->row($variant, 2 * self::DAY)]));
    }

    public function test_an_image_of_unknown_age_is_kept(): void
    {
        $variant = $this->plainPhpBase() . '-x0123abcd';

        $this->assertSame([], $this->candidates([['tag' => $variant, 'bytes' => 1, 'since' => null]]));
    }

    public function test_an_image_a_host_container_uses_is_kept(): void
    {
        $unlisted = HostImageRetention::repository($this->prewarmedOfRepository('golang')) . ':1.99-alpine';

        $this->assertSame([], $this->candidates([$this->row($unlisted, 9 * self::DAY)], [$unlisted]));
    }

    public function test_images_the_deploy_path_never_pulls_are_out_of_scope(): void
    {
        $rows = [
            $this->row('ghcr.io/panelalpha/engine-core:20260908', 30 * self::DAY),
            $this->row('panelalpha/engine-composer:local', 30 * self::DAY),
            $this->row('registry:2', 30 * self::DAY),
            $this->row('quay.io/rtraceio/flink:latest', 30 * self::DAY),
        ];

        $this->assertSame([], $this->candidates($rows));
    }

    /**
     * A Railpack deploy pulls the builder and runtime its plan names onto the
     * host. The catalogue cannot list those tags, so the prune has to know the
     * repositories itself or they stay on the host for good.
     */
    public function test_a_railpack_image_a_deploy_fetched_is_in_scope_without_a_catalogue_entry(): void
    {
        $builder = 'ghcr.io/railwayapp/railpack-builder:mise-2026.9.15';
        $this->assertNotContains($builder, ImageCatalog::all());

        $this->assertSame([$builder], array_keys($this->candidates([$this->row($builder, 5 * self::DAY)])));
        $this->assertSame([], $this->candidates([$this->row($builder, 1 * self::DAY)]), 'used inside the window');
    }

    public function test_the_loopback_registry_alias_goes_with_its_image(): void
    {
        $variant = $this->plainPhpBase() . '-x0123abcd';
        $alias = '127.0.0.1:5000/' . $variant;

        $candidates = $this->candidates([
            $this->row($variant, 5 * self::DAY),
            $this->row($alias, 5 * self::DAY),
        ]);

        $this->assertSame([$variant], array_keys($candidates));
        $this->assertSame([$variant, $alias], $candidates[$variant]['tags']);
    }

    public function test_an_alias_of_a_prewarmed_image_is_protected_with_it(): void
    {
        $alias = '127.0.0.1:5000/' . $this->plainPhpBase();

        $this->assertSame([], $this->candidates([$this->row($alias, 30 * self::DAY)]));
    }

    public function test_a_recent_alias_tag_keeps_the_whole_image(): void
    {
        $variant = $this->plainPhpBase() . '-x0123abcd';

        $this->assertSame([], $this->candidates([
            $this->row($variant, 30 * self::DAY),
            $this->row('127.0.0.1:5000/' . $variant, 1 * self::DAY),
        ]));
    }

    public function test_a_deploy_log_names_an_image_through_escaped_slashes(): void
    {
        $variant = $this->plainPhpBase() . '-x0123abcd';
        $log = json_encode(['level' => 'info', 'message' => "Loaded base image {$variant} from host cache"]) . "\n";
        $this->assertStringContainsString('\\/', $log);

        $this->assertSame([$variant], HostImageRetention::mentionedIn($log, [$variant, 'golang:1.99-alpine']));
    }

    public function test_a_variant_does_not_name_its_plain_base(): void
    {
        $plain = $this->plainPhpBase();
        $log = json_encode(['message' => "Preparing shared PHP base image {$plain}-x0123abcd"]);

        $this->assertSame([], HostImageRetention::mentionedIn((string) $log, [$plain]));
        $this->assertSame([], HostImageRetention::mentionedIn('FROM golang:1.211-alpine', ['golang:1.21']));
        $this->assertSame(['golang:1.21-alpine'], HostImageRetention::mentionedIn(
            '#3 [1/6] FROM docker.io/library/golang:1.21-alpine@sha256:ab',
            ['golang:1.21-alpine']
        ));
    }

    public function test_image_list_and_tag_time_parsing(): void
    {
        $rows = HostImageRetention::parseImageList(
            "golang:1.21-alpine\t337MB\nnode:20-bookworm\t1.59GB\nweird:1\t1e+03MB\n"
        );

        $this->assertSame('golang:1.21-alpine', $rows[0]['tag']);
        $this->assertSame((int) round(337 * 1048576), $rows[0]['bytes']);
        $this->assertNull($rows[2]['bytes']);
        $this->assertSame(
            strtotime('2026-09-23T07:58:31Z'),
            HostImageRetention::parseTagTime('"2026-09-23T07:58:31.112754581Z"')
        );
        $this->assertNull(HostImageRetention::parseTagTime('"0001-01-01T00:00:00Z"'));
        $this->assertNull(HostImageRetention::parseTagTime('null'));
    }

    /**
     * @param list<array{tag: string, bytes: ?int, since: ?int}> $rows
     * @param list<string> $containers
     * @return array<string, array{tags: list<string>, bytes: ?int, since: int}>
     */
    private function candidates(array $rows, array $containers = []): array
    {
        return HostImageRetention::candidates(
            $rows,
            $this->prewarmed(),
            ImageCatalog::all(),
            $containers,
            self::NOW,
            3 * self::DAY
        );
    }

    /**
     * @return array{tag: string, bytes: int, since: int}
     */
    private function row(string $tag, int $age): array
    {
        return ['tag' => $tag, 'bytes' => 1073741824, 'since' => self::NOW - $age];
    }

    /**
     * @return list<string>
     */
    private function prewarmed(): array
    {
        return array_map(static fn (array $i): string => (string) $i['ref'], HostPrewarmPlan::available());
    }

    private function plainPhpBase(): string
    {
        foreach ($this->prewarmed() as $ref) {
            if (BuiltImage::runtimeFor($ref) === 'php' && !BuiltImage::isVariant($ref)) {
                return $ref;
            }
        }
        $this->markTestSkipped('the catalogue warms no plain PHP base');
    }

    private function prewarmedOfRepository(string $repository): string
    {
        foreach ($this->prewarmed() as $ref) {
            if (HostImageRetention::repository($ref) === $repository) {
                return $ref;
            }
        }
        $this->markTestSkipped("the catalogue warms nothing from {$repository}");
    }
}
