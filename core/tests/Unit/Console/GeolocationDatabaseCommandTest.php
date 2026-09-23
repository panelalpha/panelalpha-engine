<?php

namespace Tests\Unit\Console;

use App\Console\Kernel;
use App\Integrations\GeoLocation\AcceptsDatabaseTerms;
use App\Integrations\GeoLocation\DbIp;
use App\Integrations\GeoLocation\GeoLocation;
use App\Integrations\GeoLocation\TermsRequiredException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class GeolocationDatabaseCommandTest extends TestCase
{
    public function test_update_with_accept_terms_records_acceptance_and_updates(): void
    {
        $fake = new FakeGeolocationDriver;
        $this->app->instance(GeoLocation::class, $fake);

        $this->artisan('geolocation:database', [
            'action' => 'update',
            '--accept-terms' => true,
        ])->assertExitCode(0);

        $this->assertTrue($fake->accepted);
        $this->assertSame(1, $fake->updates);
        $this->assertFalse($fake->forced);
    }

    public function test_successful_dbip_update_prints_attribution(): void
    {
        $root = sys_get_temp_dir().'/pa-geolocation-cmd-'.bin2hex(random_bytes(8));
        mkdir($root, 0775, true);
        Carbon::setTestNow(Carbon::parse('2026-09-18 12:00:00', 'UTC'));
        $gz = gzencode('city-mmdb', 9);
        $this->assertNotFalse($gz);
        Http::fake(['*' => Http::response($gz, 200)]);
        $dbip = new DbIp($root);
        $this->app->instance(GeoLocation::class, $dbip);

        try {
            $this->artisan('geolocation:database', [
                'action' => 'update',
                '--accept-terms' => true,
            ])->expectsOutputToContain('IP Geolocation by DB-IP (https://db-ip.com)')
                ->assertExitCode(0);
        } finally {
            Carbon::setTestNow();
            foreach (glob($root.'/geolocation/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($root.'/geolocation')) {
                rmdir($root.'/geolocation');
            }
            rmdir($root);
        }
    }

    public function test_force_is_forwarded_to_the_driver(): void
    {
        $fake = new FakeGeolocationDriver;
        $fake->accepted = true;
        $this->app->instance(GeoLocation::class, $fake);

        $this->artisan('geolocation:database', [
            'action' => 'update',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(1, $fake->updates);
        $this->assertTrue($fake->forced);
    }

    public function test_unknown_action_fails_and_lists_update(): void
    {
        $this->app->instance(GeoLocation::class, new FakeGeolocationDriver);

        $this->artisan('geolocation:database', [
            'action' => 'status',
        ])->expectsOutputToContain('update')
            ->assertExitCode(1);
    }

    public function test_non_interactive_update_without_accept_or_stamp_fails_closed(): void
    {
        $fake = new FakeGeolocationDriver;
        $this->app->instance(GeoLocation::class, $fake);

        $this->artisan('geolocation:database', [
            'action' => 'update',
        ])->assertExitCode(1);

        $this->assertFalse($fake->accepted);
        $this->assertSame(0, $fake->updates);
    }

    public function test_schedule_does_not_include_geolocation_database(): void
    {
        $schedule = new Schedule;
        $method = new ReflectionMethod(Kernel::class, 'schedule');
        $method->invoke(app(Kernel::class), $schedule);

        $events = collect($schedule->events())->filter(
            static fn ($item): bool => str_contains((string) $item->command, 'geolocation:database')
        );

        $this->assertCount(0, $events);
    }
}

final class FakeGeolocationDriver implements AcceptsDatabaseTerms, GeoLocation
{
    public bool $accepted = false;

    public int $updates = 0;

    public bool $forced = false;

    public function updateDatabase(bool $force = false): void
    {
        if (! $this->accepted) {
            throw new TermsRequiredException('CC BY 4.0 db-ip.com');
        }
        $this->forced = $force;
        $this->updates++;
    }

    public function databasePath(): ?string
    {
        return null;
    }

    public function lookup(string $ip): ?array
    {
        return null;
    }

    public function acceptTerms(): void
    {
        $this->accepted = true;
    }
}
