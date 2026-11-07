<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\ImageTransfer;
use PHPUnit\Framework\TestCase;

class ImageTransferManifestSizeTest extends TestCase
{
    public function test_unparseable_or_scalar_output_is_unknown(): void
    {
        $this->assertNull(ImageTransfer::parseManifestSize(''));
        $this->assertNull(ImageTransfer::parseManifestSize('not json'));
        $this->assertNull(ImageTransfer::parseManifestSize('5'));
        $this->assertNull(ImageTransfer::parseManifestSize('[]'));
    }

    public function test_a_single_platform_image_sums_its_own_layers(): void
    {
        $json = json_encode(['layers' => [['size' => 100], ['size' => 23]]]);

        $this->assertSame(123, ImageTransfer::parseManifestSize("  $json\n"));
    }

    public function test_a_single_descriptor_without_a_platform_counts_as_this_host(): void
    {
        $json = json_encode([
            'Descriptor' => ['mediaType' => 'x'],
            'SchemaV2Manifest' => ['layers' => [['size' => 7], ['size' => 8]]],
        ]);

        $this->assertSame(15, ImageTransfer::parseManifestSize($json));
    }

    public function test_a_single_descriptor_for_another_platform_is_unknown(): void
    {
        $json = json_encode([
            'Descriptor' => ['platform' => ['architecture' => 'arm64', 'os' => 'linux']],
            'OCIManifest' => ['layers' => [['size' => 50]]],
        ]);

        $this->assertNull(ImageTransfer::parseManifestSize($json));
    }

    public function test_a_manifest_list_counts_only_linux_amd64(): void
    {
        $json = json_encode([
            [
                'Descriptor' => ['platform' => ['architecture' => 'arm64', 'os' => 'linux']],
                'OCIManifest' => ['layers' => [['size' => 1000]]],
            ],
            [
                'Descriptor' => ['platform' => ['architecture' => 'amd64', 'os' => 'windows']],
                'OCIManifest' => ['layers' => [['size' => 2000]]],
            ],
            [
                'Descriptor' => ['platform' => ['architecture' => 'amd64', 'os' => 'linux']],
                'OCIManifest' => ['layers' => [['size' => 30], ['size' => 12]]],
                'SchemaV2Manifest' => ['layers' => [['size' => 99999]]],
            ],
        ]);

        $this->assertSame(42, ImageTransfer::parseManifestSize($json));
    }

    public function test_entries_without_usable_layers_are_skipped(): void
    {
        $amd64 = ['architecture' => 'amd64', 'os' => 'linux'];
        $json = json_encode([
            'junk',
            ['Descriptor' => ['platform' => $amd64]],
            ['Descriptor' => ['platform' => $amd64], 'OCIManifest' => ['layers' => 'nope']],
            ['Descriptor' => ['platform' => $amd64], 'OCIManifest' => ['layers' => [
                ['size' => 0], ['size' => -5], ['size' => '10'], 'x', ['size' => 1.5], ['digest' => 'd'],
            ]]],
            ['Descriptor' => ['platform' => $amd64], 'SchemaV2Manifest' => ['layers' => [['size' => 4], ['size' => 0]]]],
            ['Descriptor' => ['platform' => $amd64], 'layers' => [['size' => 900]]],
        ]);

        $this->assertSame(4, ImageTransfer::parseManifestSize($json));
    }

    public function test_entries_whose_layers_sum_to_nothing_are_unknown(): void
    {
        $json = json_encode([
            ['layers' => [['size' => 0]]],
            ['layers' => []],
        ]);

        $this->assertNull(ImageTransfer::parseManifestSize($json));
    }
}
