<?php

namespace Tests\Integration\Deploy\Dind;

use App\Lib\Deploy\Dind\HostBuildSlot;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A build that finds the slot held waits for it, and one with nowhere to keep the slot builds without it.
 */
class HostBuildSlotWaitTest extends TestCase
{
    private ?string $storage = null;

    protected function tearDown(): void
    {
        if ($this->storage !== null) {
            exec('rm -rf '.escapeshellarg($this->storage));
        }
        parent::tearDown();
    }

    public function test_a_held_slot_is_waited_for_and_the_caller_told_once(): void
    {
        $holder = fopen(storage_path('host-build.lock'), 'c');
        $this->assertNotFalse($holder);
        $this->assertTrue(flock($holder, LOCK_EX | LOCK_NB));
        $told = 0;

        $result = HostBuildSlot::run(static fn (): string => 'built', static function () use (&$told, $holder): void {
            $told++;
            flock($holder, LOCK_UN);
        });
        fclose($holder);

        $this->assertSame('built', $result);
        $this->assertSame(1, $told);
    }

    public function test_a_lock_file_that_cannot_be_opened_builds_without_the_slot(): void
    {
        $this->storage = sys_get_temp_dir().'/pa-slot-'.bin2hex(random_bytes(4));
        mkdir($this->storage.'/host-build.lock', 0777, true);
        $this->app->useStoragePath($this->storage);
        Log::spy();

        $this->assertSame('built', HostBuildSlot::run(static fn (): string => 'built'));

        Log::shouldHaveReceived('warning')->once()->with('Host build slot unavailable; building without it.');
    }
}
