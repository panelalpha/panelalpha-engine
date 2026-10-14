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

    /**
     * An expiry equal to the cadence let the next tick start a second copy
     * beside a run that was still going: two uploads of one telemetry spool,
     * two prunes at once.
     */
    public function test_a_command_that_can_outlast_its_cadence_keeps_its_mutex_past_it(): void
    {
        $long = ['telemetry:ship' => 5, 'system:disk:guard' => 5, 'project:mapping:check' => 5, 'metrics:projects:disk' => 15];
        $seen = [];
        foreach ($this->events() as $event) {
            foreach ($long as $name => $cadence) {
                if (str_ends_with((string) $event->command, " {$name}")) {
                    $seen[] = $name;
                    $this->assertGreaterThanOrEqual($cadence * 4, $event->expiresAt, "{$name} can be started twice");
                }
            }
        }

        $this->assertEqualsCanonicalizing(array_keys($long), $seen);
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
