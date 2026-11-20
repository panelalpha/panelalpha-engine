<?php

namespace Tests\Integration\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\CacheRegistry;
use App\Lib\Deploy\CacheManager\RegistryImageConfig;
use App\Lib\Deploy\CacheManager\SystemImageListing;
use App\Models\OperatorImage;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** The images the host keeps for its projects, as `pae system:image:list` and GET /system/images list them. */
class SystemImageListingTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        OperatorImage::query()->create(['ref' => 'zz:1', 'source' => 'pull', 'status' => OperatorImage::STATUS_READY]);
        OperatorImage::query()->create(['ref' => 'aa:1', 'source' => 'local', 'status' => OperatorImage::STATUS_FAILED]);
    }

    public function test_the_operators_images_come_alone_in_ref_order(): void
    {
        $entries = $this->listing()->operators();

        $this->assertSame(['aa:1', 'zz:1'], array_column($entries, 'ref'));
        $this->assertSame(['operator', 'operator'], array_column($entries, 'owner'));
        $this->assertSame([null, null], array_column($entries, 'present'));
    }

    public function test_the_full_listing_puts_the_operators_images_before_the_catalogue(): void
    {
        $entries = $this->listing()->all();

        $this->assertEquals($this->listing()->operators(), array_slice($entries, 0, 2));
        $this->assertNotEmpty(array_slice($entries, 2));
        $this->assertSame(['engine'], array_values(array_unique(array_column(array_slice($entries, 2), 'owner'))));
    }

    private function listing(): SystemImageListing
    {
        return new SystemImageListing(new CacheRegistry(static fn (): ?array => null), new RegistryImageConfig(static fn (): ?string => null));
    }
}
