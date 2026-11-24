<?php

namespace Tests\Integration\Hub;

use App\Console\Commands\Hub\HubReportCommand;
use App\Jobs\SendHubBackfill;
use App\Lib\Hub\HubReporter;
use App\Lib\Hub\HubSigner;
use App\Lib\Hub\HubSsoRoutes;
use App\Models\HubConnection;
use App\Models\HubExchange;
use App\System;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

/** What the engine does with each answer to its minute report, and when `hub:report` sends one. */
class HubReporterTest extends HubTestCase
{
    private const REPORT = self::HUB.'/api/engine/v1/report';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_report_is_signed_over_the_exact_bytes_sent(): void
    {
        $connection = $this->pushConnection();
        Http::fake([self::REPORT => Http::response(['data' => []])]);

        (new HubReporter)->send($connection);

        Http::assertSent(function (Request $request): bool {
            $expected = HubSigner::headers('hc_01J8Z6Q4X9V3K2M7N5P1R0S8T6', self::SECRET, 'POST', 'api/engine/v1/report', $request->body(), (int) $request->header('X-Timestamp')[0]);

            return $request->header('X-Signature') === [$expected['X-Signature']];
        });
    }

    public function test_an_accepted_report_takes_the_interval_wants_backfill_and_rotation_from_the_answer(): void
    {
        $connection = $this->pushConnection();
        $window = ['from' => '2026-11-22T10:00:00Z', 'to' => '2026-11-24T10:00:00Z', 'step' => 60];
        Http::fake([self::REPORT => Http::response(['data' => [
            'next_report_seconds' => 900, 'want' => ['backups'], 'backfill' => $window, 'rotate_secret' => true, 'restart' => 'all',
        ]])]);

        (new HubReporter)->send($connection);

        $connection->refresh();
        $this->assertSame(300, $connection->report_interval_seconds, 'held to 60-300');
        $this->assertSame(['backups'], $connection->report_want);
        $this->assertTrue($connection->rotate_secret);
        Queue::assertPushed(SendHubBackfill::class, fn (SendHubBackfill $job): bool => $job->window === $window);
        $this->assertSame([HubExchange::REPORT, 200], [HubExchange::query()->sole()->kind, HubExchange::query()->sole()->status]);
    }

    public function test_the_hubs_own_403_forgets_the_connection_and_a_firewall_page_does_not(): void
    {
        $connection = $this->pushConnection();
        Http::fake([self::REPORT => Http::sequence()
            ->push('<html>blocked</html>', 403, ['Content-Type' => 'text/html'])
            ->push(['message' => ''], 403)]);

        (new HubReporter)->send($connection);
        $this->assertNotNull(HubConnection::active());

        (new HubReporter)->send($connection);
        $this->assertNull(HubConnection::active());
    }

    public function test_upgrade_required_upgrades_and_a_replay_is_signed_again_once(): void
    {
        $connection = $this->pushConnection();
        Http::fake([
            self::REPORT => Http::sequence()->push(['message' => 'replay'], 409)->push(['message' => 'pull', 'code' => 'upgrade_required'], 409),
            self::HUB.'/api/engine/v1/connection/upgrade' => Http::response(null, 204),
        ]);

        (new HubReporter)->send($connection);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/connection/upgrade') && $r->data() === ['protocol' => 'push']);
    }

    public function test_a_refused_report_keeps_the_fields_the_hub_named_and_429_waits(): void
    {
        $connection = $this->pushConnection();
        Http::fake([self::REPORT => Http::sequence()
            ->push(['message' => 'The given data was invalid.', 'errors' => ['host.cpu_cores' => ['Too many.']]], 422)
            ->push(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '120'])]);

        (new HubReporter)->send($connection);
        (new HubReporter)->send($connection);

        $this->assertStringContainsString('host.cpu_cores: Too many.', (string) HubExchange::query()->orderBy('id')->first()?->detail);
        $this->assertEqualsWithDelta(now()->addSeconds(120)->timestamp, $connection->refresh()->report_not_before?->timestamp, 2);
        $this->assertFalse(HubReportCommand::isDue($connection));
    }

    public function test_an_unreachable_hub_is_recorded_and_never_queued(): void
    {
        $connection = $this->pushConnection();
        Http::fake([self::REPORT => fn () => throw new ConnectionException('timed out')]);

        $this->assertNull((new HubReporter)->send($connection));

        $this->assertSame(0, HubExchange::query()->sole()->status, 'one attempt, recorded, nothing kept for later');
    }

    public function test_the_command_waits_for_its_second_and_reports_only_when_due(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'UTC'));
        $connection = $this->pushConnection();
        $connection->forceFill(['host_facts_at' => now(), 'host_facts' => []])->save();
        Http::fake([self::REPORT => Http::response(['data' => ['next_report_seconds' => 120]])]);

        $this->artisan('hub:report')->assertExitCode(0);
        $this->artisan('hub:report')->assertExitCode(0);

        Http::assertSentCount(1);
        if (HubReportCommand::offsetSecond() > 0) {
            Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalSeconds === HubReportCommand::offsetSecond(), 2);
        }

        $this->artisan('hub:report', ['--now' => true])->assertExitCode(0);
        Http::assertSentCount(2);
    }

    public function test_a_pull_connection_upgrades_itself_then_reports(): void
    {
        $token = $this->hubToken();
        $connection = $this->connection($token);
        $connection->forceFill(['host_facts_at' => now(), 'host_facts' => []])->save();
        Http::fake([
            self::HUB.'/api/engine/v1/connection/upgrade' => Http::response(null, 204),
            self::REPORT => Http::response(['data' => []]),
        ]);

        $this->artisan('hub:report', ['--now' => true])->assertExitCode(0);

        $connection->refresh();
        $this->assertTrue($connection->isPush());
        $this->assertSame('hc_TEST', $connection->connection_id, 'same connection');
        $this->assertNotNull($token->accessToken->fresh()->revoked_at, 'the Hub token stops working');
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/api/engine/v1/report'));
    }

    public function test_a_refused_upgrade_keeps_pull_and_is_tried_again_a_day_later(): void
    {
        $token = $this->hubToken();
        $connection = $this->connection($token);
        Http::fake([self::HUB.'/api/engine/v1/connection/upgrade' => Http::response(['message' => 'nope'], 500)]);

        $this->artisan('hub:report')->assertExitCode(0);
        $this->artisan('hub:report')->assertExitCode(0);
        Http::assertSentCount(1);

        $this->travel(25)->hours();
        $this->artisan('hub:report')->assertExitCode(0);

        Http::assertSentCount(2);
        $this->assertFalse($connection->refresh()->isPush());
        $this->assertNull($token->accessToken->fresh()->revoked_at);
    }

    public function test_a_rotation_signs_everything_after_with_the_new_secret(): void
    {
        $connection = $this->pushConnection();
        $next = str_repeat('b', 64);
        Http::fake([
            self::REPORT => Http::response(['data' => ['rotate_secret' => true]]),
            self::HUB.'/api/engine/v1/connection/secret' => Http::sequence()
                ->push(null, 500)
                ->push(['data' => ['hub_secret' => $next]]),
        ]);

        $this->artisan('hub:report', ['--now' => true])->assertExitCode(0);
        $this->assertSame(self::SECRET, $connection->refresh()->hub_secret, 'a lost answer keeps the old secret');

        $this->artisan('hub:report', ['--now' => true])->assertExitCode(0);
        $connection->refresh();
        $this->assertSame($next, $connection->hub_secret);
        $this->assertSame(self::SECRET, $connection->hub_secret_previous);
        $this->assertSame([$next, self::SECRET], $connection->ticketSecrets());

        $this->travel(11)->minutes();
        $this->assertSame([$next], $connection->refresh()->ticketSecrets());
    }

    public function test_once_connected_every_vhost_is_rendered_with_the_login_route_once(): void
    {
        $this->mock(System::class, fn ($mock) => $mock->shouldReceive('rebuildDomains')->once()->andReturn(true));

        $this->artisan('hub:sso-routes')->expectsOutput('Not connected to PanelAlpha Connect; nothing to render.')->assertExitCode(0);

        $this->pushConnection();
        $this->artisan('hub:sso-routes')->expectsOutput('Rendered every vhost with the route.')->assertExitCode(0);
        $this->artisan('hub:sso-routes')->expectsOutput('Every vhost already has the route.')->assertExitCode(0);
        $this->assertTrue(HubSsoRoutes::areRendered());
    }
}
