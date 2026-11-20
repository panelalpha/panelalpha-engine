<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Dind\DindBuildStorage;
use App\Lib\Deploy\Dind\RegistryAuth;
use PHPUnit\Framework\TestCase;

/**
 * Lines the DinD helpers read past: a wrapped `df` line, and a registry host written with its API path.
 */
class DindParsingEdgesTest extends TestCase
{
    public function test_a_df_line_too_short_to_hold_the_columns_is_skipped(): void
    {
        $df = "Filesystem 1K-blocks Used Available Use% Mounted on\n/dev/mapper/vg-root\n 100 40 60 40% /home\n/dev/sda1 1000 400 600 40% /home\n";

        $this->assertSame(600 * 1024, DindBuildStorage::parseDfAvailableBytes($df));
    }

    public function test_a_registry_host_written_with_its_api_path_is_the_host(): void
    {
        $auth = RegistryAuth::parse("https://GHCR.io/v2/ bot token1\nregistry.example.test:5000/v1 bot token2\n");

        $this->assertSame(['ghcr.io', 'registry.example.test:5000'], $auth->hosts());
    }
}
