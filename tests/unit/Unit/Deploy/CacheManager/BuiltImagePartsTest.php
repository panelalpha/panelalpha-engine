<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\CacheManager\PhpBaseImage;
use PHPUnit\Framework\TestCase;

class BuiltImagePartsTest extends TestCase
{
    public function test_a_tag_of_our_repository_without_a_readable_fingerprint_has_no_parts(): void
    {
        $repository = (string) PhpBaseImage::repository();

        $this->assertNull(BuiltImage::parts($repository.':'));
        $this->assertNull(BuiltImage::parts($repository.':8.3-apache-bookworm-pazz12'));
        $this->assertNull(BuiltImage::parts($repository.':-pa1234'));
        $this->assertNull(BuiltImage::parts($repository.':8.3-apache-bookworm'));
    }

    public function test_the_last_pa_and_the_variant_suffix_split_the_tag(): void
    {
        $repository = (string) PhpBaseImage::repository();

        $this->assertSame([
            'runtime' => 'php',
            'repository' => $repository,
            'upstreamTag' => '8.3-pale-moon',
            'fingerprint' => '20261007',
            'variant' => 'dead',
        ], BuiltImage::parts(' '.$repository.':8.3-pale-moon-pa20261007-xdead '));
    }
}
