<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeInclude;
use PHPUnit\Framework\TestCase;

class ComposeIncludeLimitsTest extends TestCase
{
    public function test_blank_include_paths_are_skipped_and_own_services_follow_included_ones(): void
    {
        $raw = "services:\n  inc:\n    image: i\n";
        $compose = ['include' => ['', '   ', ['path' => ['a.yml', '']]], 'services' => ['own' => ['image' => 'o']]];

        $this->assertSame(
            ['compose' => ['services' => ['inc' => ['image' => 'i'], 'own' => ['image' => 'o']]], 'sources' => [$raw]],
            ComposeInclude::flatten($compose, static fn (string $path): ?string => $path === 'a.yml' ? $raw : null)
        );
    }

    public function test_a_file_including_itself_stops_at_three_levels(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The compose file nests include: deeper than 3 levels.');

        ComposeInclude::flatten(['include' => ['a.yml']], static fn (string $path): ?string => "include:\n  - a.yml\n");
    }
}
