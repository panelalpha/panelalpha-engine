<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\ImageCatalog;
use App\Lib\Deploy\CacheManager\RailpackCache;
use PHPUnit\Framework\TestCase;

/**
 * `config/core/images.yaml` is meant to be edited on a live host, so what
 * matters is that a bad edit costs the operator the line they got wrong and
 * never the whole plan.
 */
class ImageCatalogTest extends TestCase
{
    protected function tearDown(): void
    {
        ImageCatalog::useConfig(null);
        parent::tearDown();
    }

    public function test_the_shipped_file_parses(): void
    {
        $entries = ImageCatalog::entries();

        $this->assertNotEmpty($entries, 'config/core/images.yaml should describe a plan');
        foreach ($entries as $entry) {
            $this->assertNotSame('', $entry['ref']);
            $this->assertContains($entry['kind'], [HostPrewarmPlan::KIND_BUILD, HostPrewarmPlan::KIND_PULL]);
            $this->assertNotSame('', $entry['runtime']);
            $this->assertNotSame('', $entry['why'], "{$entry['ref']} should say why it is here");
        }
    }

    /**
     * The id is what `DEPLOY_PREWARM_IMAGES` names, so it must not move when a
     * recipe date or an upstream tag does, or a selection silently lapses.
     */
    public function test_ids_in_the_shipped_file_are_unique_and_carry_no_tag(): void
    {
        $ids = array_column(ImageCatalog::entries(), 'id');

        $this->assertSame(count($ids), count(array_unique($ids)));
        $this->assertContains('php:8.3', $ids);
        $this->assertContains('php:8.3+mongodb', $ids);
        $this->assertContains('composer', $ids);
        foreach ($ids as $id) {
            $this->assertDoesNotMatchRegularExpression('/-pa\d|-x[0-9a-f]/', $id, "{$id} carries a recipe tag");
        }
    }

    /**
     * The builder and runtime tags change with every Railpack release and are
     * read from the plan at deploy time; a dated one here only ever named an
     * image no deploy asked for. The frontend is the tag the engine passes.
     */
    public function test_the_only_railpack_image_named_is_the_frontend_the_engine_passes(): void
    {
        $railpack = array_values(array_filter(ImageCatalog::all(), RailpackCache::isRailpackImage(...)));

        $this->assertSame([RailpackCache::FRONTEND_IMAGE], $railpack);
    }

    public function test_an_extra_is_known_by_its_repository(): void
    {
        $this->withConfig(<<<'YAML'
        extras:
            - image: "localhost:5000/team/tool:1.2"
              runtime: static
              prewarm: 10
              why: "registry with a port"
            - image: "busybox"
              runtime: static
              prewarm: 20
              why: "no tag at all"
        YAML);

        $this->assertSame(['localhost:5000/team/tool', 'busybox'], array_column(ImageCatalog::entries(), 'id'));
    }

    public function test_prewarm_priorities_in_the_shipped_file_are_unique(): void
    {
        // Not a correctness requirement — ties break on ref — but two lines
        // sharing a number means someone edited one and missed the other.
        $priorities = array_map(
            static fn (array $e): int => (int) $e['prewarm'],
            ImageCatalog::prewarmed()
        );

        $this->assertSame(count($priorities), count(array_unique($priorities)));
    }

    /**
     * An entry with no `prewarm` is still named here, and being named is what
     * stops account teardown reclaiming it as one project's leftovers.
     */
    public function test_an_entry_can_be_listed_without_being_warmed(): void
    {
        $this->withConfig(<<<'YAML'
        runtimes:
          rust:
            versions:
              - version: "1"
                why: "held on the host, warmed nowhere"
        YAML);

        $this->assertSame([], ImageCatalog::prewarmed());
        $this->assertSame(['rust:1-slim-bookworm'], ImageCatalog::all());
    }

    public function test_a_higher_prewarm_priority_is_warmed_first(): void
    {
        $this->withConfig(<<<'YAML'
        extras:
            - image: "nginx:alpine"
              runtime: static
              prewarm: 10
              why: "last"
            - image: "composer:2"
              runtime: php
              prewarm: 900
              why: "first"
        YAML);

        $this->assertSame(
            ['composer:2', 'nginx:alpine'],
            array_map(static fn (array $e): string => $e['ref'], ImageCatalog::prewarmed())
        );
    }

    public function test_an_entry_with_no_runtime_is_dropped(): void
    {
        // Every entry declares one, literal images included: it is how
        // --runtimes=php selects composer:2 along with the PHP bases.
        $this->withConfig(<<<'YAML'
        extras:
            - image: "composer:2"
              prewarm: 10
              why: "no runtime"
        YAML);

        $this->assertSame([], ImageCatalog::entries());
    }

    public function test_an_entry_naming_something_unresolvable_is_dropped(): void
    {
        $this->withConfig(<<<'YAML'
        runtimes:
          cobol:
            versions:
              - version: "85"
                prewarm: 100
                why: "not a runtime"
          php:
            versions:
              - version: "9.9"
                prewarm: 90
                why: "a minor the runtimes block does not declare"
        extras:
          - image: "-rf"
            runtime: static
            prewarm: 80
            why: "docker would read this as a flag"
          - image: "nginx:alpine"
            runtime: static
            prewarm: 70
            why: "the one good line"
        YAML);

        $this->assertSame(['nginx:alpine'], ImageCatalog::all());
    }

    public function test_the_same_image_listed_twice_is_kept_once(): void
    {
        $this->withConfig(<<<'YAML'
        extras:
            - image: "nginx:alpine"
              runtime: static
              prewarm: 100
              why: "kept"
            - image: "nginx:alpine"
              runtime: railpack
              prewarm: 50
              why: "dropped"
        YAML);

        $entries = ImageCatalog::entries();

        $this->assertCount(1, $entries);
        $this->assertSame('kept', $entries[0]['why']);
    }

    public function test_malformed_yaml_yields_nothing_rather_than_half_a_plan(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'images') . '.yaml';
        file_put_contents($path, "images:\n  entries:\n    - image: \"nginx:alpine\"\n   bad indent\n");
        ImageCatalog::useConfig($path);

        $this->assertSame([], ImageCatalog::entries());
        $this->assertNull(ImageCatalog::reserve());

        @unlink($path);
    }

    private function withConfig(string $yaml): void
    {
        $path = tempnam(sys_get_temp_dir(), 'images') . '.yaml';
        file_put_contents($path, $yaml);
        ImageCatalog::useConfig($path);
    }
}
