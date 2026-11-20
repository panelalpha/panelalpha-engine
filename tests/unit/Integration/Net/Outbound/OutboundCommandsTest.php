<?php

namespace Tests\Integration\Net\Outbound;

use App\Lib\Net\Outbound\OutboundLimits;
use App\Lib\Net\Outbound\OutboundStatus;
use App\Models\OutboundEvent;
use App\Rules\EgressOwner;
use Illuminate\Support\Carbon;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** `pae system:egress:*` and `/system/egress`: what they print and answer, the broker faked. */
class OutboundCommandsTest extends TestCase
{
    use InMemoryDatabase;

    /** @var list<string> */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware();
        Carbon::setTestNow(Carbon::createFromTimestampUTC(1800000060));
        $this->broker('{"active": true, "settings": {"EGRESS_BLOCK_SMTP": 1, "EGRESS_NEW_CONNECTIONS_PER_SECOND": 100,'
            .' "EGRESS_NEW_DESTINATIONS_PER_MINUTE": 20, "EGRESS_NEW_DESTINATIONS_BURST": 60,'
            .' "EGRESS_WEB_NEW_DESTINATIONS_PER_MINUTE": 200, "EGRESS_WEB_NEW_DESTINATIONS_BURST": 600, "EGRESS_SCAN_BLOCK_MINUTES": 30},'
            .' "blocks": [{"owner": "shop", "network": "tenants", "address": "'.TestNetwork::TENANT_ACCOUNT.'", "since": 1800000000, "until": 1800001800}], "marks": []}');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function broker(string $report, string $unblocked = '{"owner": "shop", "addresses": ["'.TestNetwork::TENANT_ACCOUNT.'"]}'): void
    {
        $this->app->instance(OutboundStatus::class, new OutboundStatus(new OutboundLimits(function (array $argv) use ($report, $unblocked): string {
            $this->asked[] = implode(' ', $argv);

            return str_contains(end($this->asked), 'egressUnblock') ? $unblocked : $report;
        })));
    }

    public function test_list_prints_the_settings_and_the_block_in_force(): void
    {
        $this->artisan('system:egress:list')
            ->expectsOutputToContain('The outbound limits are in place: SMTP to other servers blocked, 100 new connections a second, 60 new addresses at once, then 20 a minute, on ports 80 and 443 600 new addresses at once, then 200 a minute, then blocked for 30 minutes.')
            ->expectsTable(
                ['Project or build', 'Network', 'What', 'Address', 'Since (UTC)', 'Last seen / blocked until (UTC)', 'State'],
                [['shop', 'tenants', 'scan, blocked', TestNetwork::TENANT_ACCOUNT, '2027-01-15 08:00', '2027-01-15 08:30', 'in force']],
            )
            ->expectsOutputToContain('Lift a block early: pae system:egress:unblock <project>')
            ->assertExitCode(0);
    }

    public function test_list_names_a_scan_limit_turned_off(): void
    {
        $this->broker('{"active": true, "settings": {"EGRESS_NEW_DESTINATIONS_PER_MINUTE": 0, "EGRESS_SCAN_BLOCK_MINUTES": 0},'
            .' "blocks": [], "marks": []}');

        $this->artisan('system:egress:list')
            ->expectsOutputToContain('any number of new addresses, on ports 80 and 443 600 new addresses at once, then 200 a minute, the rest dropped.')
            ->assertExitCode(0);
    }

    public function test_list_says_when_the_limits_are_not_in_place(): void
    {
        $this->broker('{"active": false, "settings": {}, "blocks": [], "marks": []}');

        $this->artisan('system:egress:list')
            ->expectsOutputToContain('The outbound limits are not in place on this server')
            ->expectsOutput('Nothing is blocked or limited now.')
            ->assertExitCode(0);
    }

    public function test_list_refuses_a_bad_number_of_days(): void
    {
        $this->artisan('system:egress:list --days=x')->expectsOutput('--days takes a whole number of days, 1 or more.')->assertExitCode(1);
    }

    public function test_unblock_lifts_the_block_and_says_where(): void
    {
        $this->artisan('system:egress:sync')->assertExitCode(0);

        $this->artisan('system:egress:unblock shop')
            ->expectsOutput('Lifted the outbound block of shop ('.TestNetwork::TENANT_ACCOUNT.'); its count of new addresses starts again.')
            ->assertExitCode(0);
        $this->assertSame('pae', OutboundEvent::query()->sole()->lifted_by);
    }

    public function test_unblock_of_a_project_without_a_block(): void
    {
        $this->broker('{"active": true, "settings": {}, "blocks": [], "marks": []}', '{"owner": "blog", "addresses": []}');

        $this->artisan('system:egress:unblock blog')->expectsOutput('blog has no outbound block.')->assertExitCode(0);
    }

    public function test_unblock_refuses_what_is_no_name(): void
    {
        $this->artisan('system:egress:unblock', ['owner' => '-rf'])->expectsOutput("'-rf' is not a project or build name.")->assertExitCode(1);
        $this->assertSame([], $this->asked);
    }

    public function test_get_answers_the_limits_and_the_events_in_force(): void
    {
        $response = $this->getJson('/api/system/egress');

        $response->assertStatus(200);
        $this->assertTrue($response->json('active'));
        $this->assertSame(30, $response->json('settings.EGRESS_SCAN_BLOCK_MINUTES'));
        $this->assertSame(['shop', 'scan', '2027-01-15T08:30:00+00:00', true], [
            $response->json('data.0.owner'), $response->json('data.0.kind'), $response->json('data.0.blocked_until'), $response->json('data.0.active'),
        ]);
    }

    public function test_get_refuses_a_bad_number_of_days(): void
    {
        $this->getJson('/api/system/egress?days=0')->assertStatus(422);
    }

    public function test_delete_lifts_the_block(): void
    {
        $this->getJson('/api/system/egress')->assertStatus(200);

        $this->deleteJson('/api/system/egress/shop')->assertStatus(200)->assertExactJson(['lifted' => true, 'addresses' => [TestNetwork::TENANT_ACCOUNT]]);
        $this->assertSame('api', OutboundEvent::query()->sole()->lifted_by);
    }

    public function test_delete_refuses_what_is_no_name_before_asking_the_broker(): void
    {
        foreach (['-rf', 'has space', str_repeat('a', 129)] as $owner) {
            $this->deleteJson('/api/system/egress/'.rawurlencode($owner))
                ->assertStatus(422)
                ->assertJsonPath('errors.owner.0', EgressOwner::MESSAGE);
        }
        $this->assertSame([], $this->asked);
    }

    public function test_the_api_and_the_cli_take_the_same_names(): void
    {
        foreach (['shop', 'eager_turing', 'a.b-c', str_repeat('a', 128)] as $owner) {
            $this->assertTrue(EgressOwner::accepts($owner), $owner);
        }
        foreach (['', '-rf', '.hidden', 'has space', 'a/b', str_repeat('a', 129)] as $owner) {
            $this->assertFalse(EgressOwner::accepts($owner), $owner);
        }
    }

    public function test_a_broker_that_does_not_answer_is_a_503(): void
    {
        $this->broker('pa-root: the root broker is not running');

        $this->getJson('/api/system/egress')->assertStatus(503);
    }
}
