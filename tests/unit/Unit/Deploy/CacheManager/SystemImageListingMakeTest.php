<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\SystemImageListing;
use PHPUnit\Framework\TestCase;

/**
 * The listing is built against the accounts' read-only registry without asking it anything.
 */
class SystemImageListingMakeTest extends TestCase
{
    public function test_make_builds_a_listing_without_a_request(): void
    {
        $this->assertInstanceOf(SystemImageListing::class, SystemImageListing::make());
    }
}
