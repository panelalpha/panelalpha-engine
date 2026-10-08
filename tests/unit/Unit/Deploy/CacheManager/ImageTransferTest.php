<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\ImageTransfer;
use PHPUnit\Framework\TestCase;

class ImageTransferTest extends TestCase
{
    public function test_rejects_unsafe_image_refs(): void
    {
        $this->assertTrue(ImageTransfer::isSafeImageRef('node:20-bookworm-slim'));
        $this->assertFalse(ImageTransfer::isSafeImageRef('node:20; rm -rf /'));
        $this->assertFalse(ImageTransfer::isSafeImageRef(''));
    }
}
