<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployLogReader;
use PHPUnit\Framework\TestCase;
use Tests\Support\DeployLogReaderFixtures;

/**
 * Parsed views over one deploy's JSON-lines log.
 *
 * Three callers want three different windows: the panel pages forward from an
 * offset it remembers, telemetry wants the other end and does not know the
 * total, and the build breakdown needs the whole file - a `#N [x/y] <cmd>`
 * and its `#N DONE <s>` can be thousands of lines apart, and a window that
 * split the two would report the step as costing nothing.
 */
class DeployLogReaderTest extends TestCase
{
    use DeployLogReaderFixtures;

    public function test_a_log_that_is_not_there_reads_as_empty(): void
    {
        $reader = new DeployLogReader($this->path);

        $this->assertSame([], $reader->entries());
        $this->assertSame([], $reader->tail(10));
        $this->assertSame(['lines' => [], 'next_offset' => 0, 'more' => false], $reader->page());
    }
}
