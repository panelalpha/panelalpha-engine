<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\CacheManager\HostImageRetention;
use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\ImageCatalog;
use App\Lib\Deploy\CacheManager\NodeBuildImage;
use App\Lib\Deploy\CacheManager\PythonBaseImage;
use App\Lib\Deploy\CacheManager\RustBuildToolsImage;
use App\Lib\Deploy\Dind\DindImageStore;
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

        $candidates = $this->candidates([
            $this->row($variant, 5 * self::DAY),
            $this->row($this->plainPhpBase() . '-x89abcdef', 4 * self::DAY),
        ]);

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
        $alias = DindImageStore::HOST_CACHE_REGISTRY . '/' . $variant;

        $candidates = $this->candidates([
            $this->row($variant, 5 * self::DAY),
            $this->row($alias, 5 * self::DAY),
            $this->row($this->plainPhpBase() . '-x89abcdef', 4 * self::DAY),
        ]);

        $this->assertSame([$variant], array_keys($candidates));
        $this->assertSame([$variant, $alias], $candidates[$variant]['tags']);
    }

    public function test_an_alias_of_a_prewarmed_image_is_protected_with_it(): void
    {
        $alias = DindImageStore::HOST_CACHE_REGISTRY . '/' . $this->plainPhpBase();

        $this->assertSame([], $this->candidates([$this->row($alias, 30 * self::DAY)]));
    }

    public function test_a_recent_alias_tag_keeps_the_whole_image(): void
    {
        $variant = $this->plainPhpBase() . '-x0123abcd';

        $this->assertSame([], $this->candidates([
            $this->row($variant, 30 * self::DAY),
            $this->row(DindImageStore::HOST_CACHE_REGISTRY . '/' . $variant, 1 * self::DAY),
        ]));
    }

    /**
     * The next deploy of a runtime still finds most of its layers; a lone PHP variant is the newest of its kind.
     */
    public function test_the_newest_of_each_family_stays_however_old(): void
    {
        $python = $this->pythonBase();
        $older = (string) preg_replace('/-pa[0-9a-f]+$/', '-pa00000001', $python);
        $newer = (string) preg_replace('/-pa[0-9a-f]+$/', '-pa00000002', $python);
        $plain = $this->plainPhpBase();

        $candidates = $this->candidates([
            $this->row($older, 30 * self::DAY),
            $this->row($newer, 20 * self::DAY),
            $this->row($plain . '-x0123abcd', 10 * self::DAY),
        ], prewarmed: []);

        $this->assertSame([$older], array_keys($candidates));
    }

    public function test_a_tie_keeps_one_image_of_the_family(): void
    {
        $plain = $this->plainPhpBase();

        $candidates = $this->candidates([
            $this->row($plain . '-x0123abcd', 10 * self::DAY),
            $this->row($plain . '-x89abcdef', 10 * self::DAY),
        ]);

        $this->assertSame([$plain . '-x0123abcd'], array_keys($candidates));
    }

    /** No deploy asks for an old recipe again, so it goes once nothing holds it. */
    public function test_a_superseded_php_recipe_goes_inside_the_window_and_is_never_the_newest(): void
    {
        $plain = $this->plainPhpBase();
        $old = (string) preg_replace('/-pa[0-9a-f]+$/', '-pa20200101', $plain);
        $this->assertTrue(HostImageRetention::isSuperseded($old));
        $this->assertTrue(HostImageRetention::isSuperseded($old . '-x0123abcd'));
        $this->assertFalse(HostImageRetention::isSuperseded($plain));
        $this->assertFalse(HostImageRetention::isSuperseded($this->pythonBase()));

        $candidates = $this->candidates([
            $this->row($old, 1 * self::DAY),
            $this->row($old . '-x0123abcd', 3600),
        ], prewarmed: []);

        $this->assertSame([$old, $old . '-x0123abcd'], array_keys($candidates));
        $this->assertSame([], $this->candidates([$this->row($old, 1 * self::DAY)], [$old], prewarmed: []), 'a container still holds it');
    }

    /** The JVM-with-Node and Rust-with-tools images a host build makes for itself. */
    public function test_host_build_images_are_in_scope_and_keep_their_newest(): void
    {
        $rustOld = (string) RustBuildToolsImage::tag('rust:1-bookworm', ['protobuf-compiler']);
        $rustNew = (string) RustBuildToolsImage::tag('rust:1-bookworm', ['cmake', 'protobuf-compiler']);
        $nodeOld = (string) NodeBuildImage::tag('gradle:8-jdk21', 'node:20-bookworm-slim');
        $nodeNew = (string) NodeBuildImage::tag('gradle:8-jdk21', 'node:22-bookworm-slim');
        $this->assertTrue(HostImageRetention::isBuildImage($rustOld));
        $this->assertFalse(HostImageRetention::isBuildImage(RustBuildToolsImage::REPOSITORY . ':local'));

        $candidates = $this->candidates([
            $this->row($rustOld, 5 * self::DAY),
            $this->row($rustNew, 4 * self::DAY),
            $this->row($nodeOld, 5 * self::DAY),
            $this->row($nodeNew, 4 * self::DAY),
            $this->row(DindImageStore::HOST_CACHE_REGISTRY . '/' . $nodeOld, 5 * self::DAY),
        ]);

        $this->assertSame([$rustOld, $nodeOld], array_keys($candidates));
        $this->assertSame([$nodeOld, DindImageStore::HOST_CACHE_REGISTRY . '/' . $nodeOld], $candidates[$nodeOld]['tags']);
    }

    public function test_family_names(): void
    {
        $plain = $this->plainPhpBase();
        $parts = BuiltImage::parts($plain);
        $this->assertNotNull($parts);
        $base = $parts['repository'] . ':' . $parts['upstreamTag'];

        $this->assertSame($base, HostImageRetention::family($plain));
        $this->assertSame($base . '-x', HostImageRetention::family($plain . '-x0123abcd'));
        $this->assertSame(
            RustBuildToolsImage::REPOSITORY . ':rust-1-bookworm',
            HostImageRetention::family((string) RustBuildToolsImage::tag('rust:1-bookworm', ['cmake']))
        );
        $this->assertNull(HostImageRetention::family('golang:1.22-alpine'));
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
     * @param list<string>|null $prewarmed the shipped catalogue when null
     * @return array<string, array{tags: list<string>, bytes: ?int, since: int}>
     */
    private function candidates(array $rows, array $containers = [], ?array $prewarmed = null): array
    {
        return HostImageRetention::candidates(
            $rows,
            $prewarmed ?? $this->prewarmed(),
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

    private function pythonBase(): string
    {
        $tag = PythonBaseImage::tag('python:3.12-slim', ['libpq-dev']);
        if ($tag === null) {
            $this->markTestSkipped('the catalogue builds no Python base');
        }

        return $tag;
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
