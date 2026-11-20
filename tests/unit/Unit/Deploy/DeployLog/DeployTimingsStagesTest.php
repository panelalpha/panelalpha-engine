<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployTimings;
use PHPUnit\Framework\TestCase;

/** A stage entry latest.json cannot name is left out of the timings. */
class DeployTimingsStagesTest extends TestCase
{
    public function test_entries_without_a_name_are_skipped(): void
    {
        $stages = DeployTimings::stages(['stages' => [
            'garbage',
            ['started_at' => 1],
            ['name' => 7, 'started_at' => 1],
            ['name' => 'cloning', 'started_at' => 10, 'finished_at' => 14],
        ]]);

        $this->assertSame([['name' => 'cloning', 'started_at' => 10, 'finished_at' => 14, 'seconds' => 4]], $stages);
    }
}
