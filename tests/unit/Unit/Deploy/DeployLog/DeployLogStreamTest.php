<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployLogStream;
use PHPUnit\Framework\TestCase;

/**
 * The NDJSON tee that feeds a streamed deploy response.
 *
 * The property that matters is that it is best-effort: the log files are the
 * source of truth, so a client that hangs up mid-deploy must not take the
 * deploy down with it.
 */
class DeployLogStreamTest extends TestCase
{
    protected function tearDown(): void
    {
        DeployLogStream::stop();
        parent::tearDown();
    }

    public function test_frames_reach_the_emitter(): void
    {
        $seen = [];
        DeployLogStream::to(function (array $frame) use (&$seen): void {
            $seen[] = $frame;
        });

        DeployLogStream::emit(['type' => 'line', 'message' => 'building']);

        $this->assertSame([['type' => 'line', 'message' => 'building']], $seen);
    }

    public function test_nothing_is_emitted_when_no_one_is_listening(): void
    {
        DeployLogStream::emit(['type' => 'line', 'message' => 'building']);

        $this->addToAssertionCount(1);
    }

    public function test_stopping_detaches_the_emitter(): void
    {
        $count = 0;
        DeployLogStream::to(function () use (&$count): void {
            $count++;
        });
        DeployLogStream::emit(['type' => 'line']);
        DeployLogStream::stop();
        DeployLogStream::emit(['type' => 'line']);

        $this->assertSame(1, $count);
    }

    public function test_a_broken_stream_never_breaks_the_deploy(): void
    {
        // A client that disconnected mid-deploy: the write throws, and the
        // deploy has to carry on writing its log files regardless.
        DeployLogStream::to(static function (): void {
            throw new \RuntimeException('client gone');
        });

        DeployLogStream::emit(['type' => 'line', 'message' => 'building']);

        $this->addToAssertionCount(1);
    }

    public function test_a_later_emitter_replaces_the_earlier_one(): void
    {
        $first = $second = 0;
        DeployLogStream::to(function () use (&$first): void {
            $first++;
        });
        DeployLogStream::to(function () use (&$second): void {
            $second++;
        });
        DeployLogStream::emit(['type' => 'line']);

        $this->assertSame(0, $first);
        $this->assertSame(1, $second);
    }
}
