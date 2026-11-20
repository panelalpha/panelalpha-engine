<?php

namespace Tests\Integration\Net\Outbound;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Net\Outbound\OutboundEvents;
use App\Lib\Net\Outbound\OutboundLimits;
use App\Lib\Net\Outbound\OutboundNotice;
use App\Lib\Net\Outbound\OutboundReport;
use App\Lib\Net\Outbound\OutboundStatus;
use App\Models\OutboundEvent;
use Illuminate\Support\Carbon;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** The broker's report kept as events, each new one told in the project's deploy log, and a block lifted. */
class OutboundEventsTest extends TestCase
{
    use InMemoryDatabase;

    private const PROJECT = 'egshop';

    private const SINCE = 1800000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->beforeApplicationDestroyed(fn () => DeployLogger::deleteUserLogs(self::PROJECT));
    }

    private static function report(array $blocks = [], array $marks = []): OutboundReport
    {
        return new OutboundReport(true, ['EGRESS_NEW_CONNECTIONS_PER_SECOND' => 50], $blocks, $marks);
    }

    private static function block(int $until = self::SINCE + 1800): array
    {
        return ['owner' => self::PROJECT, 'network' => 'tenants', 'address' => TestNetwork::TENANT_ACCOUNT, 'since' => self::SINCE, 'until' => $until];
    }

    private static function now(int $offset = 60): Carbon
    {
        return Carbon::createFromTimestampUTC(self::SINCE + $offset);
    }

    public function test_a_block_becomes_one_event_however_often_it_is_reported(): void
    {
        $events = new OutboundEvents;

        $begun = $events->sync(self::report([self::block()]), self::now());
        $again = $events->sync(self::report([self::block(self::SINCE + 1900)]), self::now(120));

        $this->assertCount(1, $begun);
        $this->assertSame([], $again);
        $event = OutboundEvent::query()->sole();
        $this->assertSame([self::PROJECT, 'scan', TestNetwork::TENANT_ACCOUNT], [$event->owner, $event->kind, $event->address]);
        $this->assertSame(self::SINCE + 1900, $event->until_at->getTimestamp(), 'a longer block is followed');
        $this->assertTrue($event->isActive(self::now(120)));
        $this->assertFalse($event->isActive(self::now(1901)));
    }

    public function test_a_mark_is_an_episode_until_it_goes_quiet(): void
    {
        $mark = ['owner' => self::PROJECT, 'network' => 'build', 'kind' => 'smtp', 'address' => TestNetwork::container2(2), 'since' => self::SINCE, 'last' => self::SINCE + 30];
        (new OutboundEvents)->sync(self::report([], [$mark]), self::now());

        $event = OutboundEvent::query()->sole();
        $this->assertNull($event->until_at);
        $this->assertTrue($event->isActive(self::now(30 + OutboundEvent::QUIET_SECONDS - 1)));
        $this->assertFalse($event->isActive(self::now(30 + OutboundEvent::QUIET_SECONDS + 1)));
    }

    public function test_a_new_event_is_told_in_the_projects_deploy_log(): void
    {
        $this->makeUser(self::PROJECT);
        $logger = DeployLogger::start(self::PROJECT);
        $events = new OutboundEvents;

        foreach ($events->sync(self::report([self::block()]), self::now()) as $begun) {
            $events->announce($begun, []);
        }

        $written = (string) file_get_contents($logger->getLogPath());
        $this->assertStringContainsString('looks like a network scan, so its new outbound connections are blocked until 2027-01-15 08:30 UTC', $written);
        $this->assertStringContainsString('The server\'s operator can lift it early.', $written);
    }

    public function test_an_owner_that_is_no_project_is_only_logged(): void
    {
        $events = new OutboundEvents;
        $mark = ['owner' => 'eager_turing', 'network' => 'build', 'kind' => 'flood', 'address' => TestNetwork::container2(9), 'since' => self::SINCE, 'last' => self::SINCE];

        foreach ($events->sync(self::report([], [$mark]), self::now()) as $begun) {
            $events->announce($begun, []);
        }

        $this->assertSame(1, OutboundEvent::query()->count());
        $this->assertDirectoryDoesNotExist(DeployLogger::userDirFor('eager_turing'));
    }

    public function test_each_kind_names_its_setting_and_what_to_do(): void
    {
        $smtp = new OutboundEvent(['kind' => 'smtp', 'network' => 'tenants', 'owner' => 'x']);
        $flood = new OutboundEvent(['kind' => 'flood', 'network' => 'build', 'owner' => 'x']);
        $dropped = new OutboundEvent(['kind' => 'scan', 'network' => 'tenants', 'owner' => 'x']);

        $this->assertStringContainsString('port 587 or 465', OutboundNotice::line($smtp, []));
        $this->assertStringStartsWith('A build of this project opened more than 50 new outbound connections a second', OutboundNotice::line($flood, ['EGRESS_NEW_CONNECTIONS_PER_SECOND' => 50]));
        $this->assertStringContainsString('the connections over the limit were dropped', OutboundNotice::line($dropped, []));
    }

    public function test_lifting_a_block_marks_its_event_lifted_and_leaves_the_limits_that_still_apply(): void
    {
        $smtp = ['owner' => self::PROJECT, 'network' => 'tenants', 'kind' => 'smtp', 'address' => TestNetwork::TENANT_ACCOUNT, 'since' => self::SINCE, 'last' => self::SINCE + 30];
        (new OutboundEvents)->sync(self::report([self::block()], [$smtp]), self::now());
        $status = new OutboundStatus(new OutboundLimits(static fn (): string => '{"owner": "egshop", "addresses": ["'.TestNetwork::TENANT_ACCOUNT.'"]}'));

        $this->assertSame([TestNetwork::TENANT_ACCOUNT], $status->unblock(self::PROJECT, OutboundStatus::LIFTED_BY_CLI, self::now(120)));

        $this->assertNull(OutboundEvent::query()->where('kind', OutboundEvent::KIND_SMTP)->sole()->lifted_at, 'SMTP stays blocked, so its event is not lifted');
        $event = OutboundEvent::query()->where('kind', OutboundEvent::KIND_SCAN)->sole();
        $this->assertSame('pae', $event->lifted_by);
        $this->assertFalse($event->isActive(self::now(121)));
        (new OutboundEvents)->sync(self::report([self::block()]), self::now(180));
        $this->assertSame('pae', $event->fresh()->lifted_by, 'a report that still names it does not undo the lift');
    }
}
