<?php

namespace Tests\Unit\Console;

use App\Console\Kernel;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Every scheduled command that overlaps-guards itself must give the mutex a
 * bounded expiry. Laravel's default is 1440 minutes and the mutex survives a
 * core restart, so a detached `schedule:run` that s6 SIGKILLs mid-command
 * (an update, a reboot, an OOM kill) would block that command for a day
 * (engine#841).
 */
class ScheduleMutexTest extends TestCase
{
    private function events(): array
    {
        /** @var Kernel $kernel */
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $schedule = $kernel->resolveConsoleSchedule();

        return $schedule->events();
    }

    public function test_every_overlapping_command_has_a_short_expiry(): void
    {
        $events = $this->events();

        $this->assertNotEmpty($events, 'the schedule resolved to no events');

        foreach ($events as $event) {
            if (!$event->withoutOverlapping) {
                continue;
            }

            $this->assertLessThan(
                1440,
                $event->expiresAt,
                "{$event->command} keeps Laravel's 1440-minute mutex default",
            );
        }
    }

    public function test_the_minute_commands_expire_within_minutes(): void
    {
        $minute = array_values(array_filter(
            $this->events(),
            static fn ($event) => $event->expression === '* * * * *',
        ));

        $this->assertNotEmpty($minute, 'no every-minute command found');

        foreach ($minute as $event) {
            $this->assertTrue($event->withoutOverlapping, "{$event->command} is not guarded");
            $this->assertLessThanOrEqual(
                15,
                $event->expiresAt,
                "{$event->command} outlives its minute cadence for up to a day",
            );
        }
    }

    public function test_the_entrypoint_clears_the_mutexes_on_start(): void
    {
        $path = __DIR__ . '/../../../../dockerfiles/entrypoint-core.sh';
        if (!is_file($path)) {
            $this->markTestSkipped('dockerfiles/entrypoint-core.sh is not beside core/ here');
        }

        $entry = (string) file_get_contents($path);

        $this->assertStringContainsString('schedule:clear-cache', $entry);
    }
}
