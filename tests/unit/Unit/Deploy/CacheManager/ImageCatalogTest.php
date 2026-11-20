<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\ImageCatalog;
use App\Lib\Deploy\CacheManager\RailpackCache;
use PHPUnit\Framework\TestCase;
use Tests\Support\ImageCatalogFixtures;

/**
 * `config/core/images.yaml` is meant to be edited on a live host, so what
 * matters is that a bad edit costs the operator the line they got wrong and
 * never the whole plan.
 */
class ImageCatalogTest extends TestCase
{
    use ImageCatalogFixtures;

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
}
