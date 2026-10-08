<?php

namespace Tests\Unit\DeployHook;

use App\Jobs\RunHookDelivery;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\DeployHook\Coalescing;
use App\Lib\DeployHook\HookReceiver;
use App\Models\DeployHook;
use App\Models\HookDelivery;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

/**
 * A push that arrives while its project already has a deploy running: it is
 * recorded `coalesced` and the hook is marked pending instead of racing that
 * deploy. DeployLogger::finish() -- the one place a manual rebuild, the
 * deploy at account creation, a Checkout Rebuild and a push-triggered deploy
 * all pass through when they end -- is what lets the wait end, exactly once,
 * whatever that deploy's own outcome was.
 *
 * HookReceiverTest covers the rest of what a delivery is answered and
 * recorded; this file is only about what happens when one arrives mid-deploy.
 */
class CoalescingTest extends DeployHookTestCase
{
    private const SECRET = 'a-shared-secret-of-some-length';

    /** @var list<string> */
    private array $usernames = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    protected function tearDown(): void
    {
        DeployLogger::stopStreaming();
        foreach ($this->usernames as $username) {
            DeployLogger::deleteUserLogs($username);
        }
        parent::tearDown();
    }

    private function username(): string
    {
        $username = 'coalesce-' . bin2hex(random_bytes(6));
        $this->usernames[] = $username;

        return $username;
    }

    private function hookFor(string $username, string $branch = 'main'): DeployHook
    {
        return $this->hook($this->user($branch, [], $username), self::SECRET);
    }

    /** A deploy is already running for this account -- of no particular kind. */
    private function startRunningDeploy(string $username): DeployLogger
    {
        return DeployLogger::start($username);
    }

    private function push(DeployHook $hook, string $deliveryId, string $commit = 'a8c1f4d9e2b7305c6d4a1e8f9b0c2d3e4f5a6b7c'): \App\Lib\DeployHook\HookResponse
    {
        $body = json_encode([
            'ref' => 'refs/heads/main',
            'after' => $commit,
            'deleted' => false,
            'head_commit' => ['id' => $commit],
        ]);

        $request = Request::create('/hooks/' . $hook->public_id, 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_GITHUB_DELIVERY' => $deliveryId,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', (string) $body, self::SECRET),
        ], (string) $body);

        return (new HookReceiver())->receive($hook, $request);
    }

    private function delivery(string $deliveryId): HookDelivery
    {
        return HookDelivery::where('delivery_id', $deliveryId)->firstOrFail();
    }

    // -- Coalescing itself, in isolation -------------------------------

    public function test_is_running_reflects_the_account_deploy_log(): void
    {
        $username = $this->username();
        $user = $this->user('main', [], $username);

        $this->assertFalse(Coalescing::isRunning($user->fresh()));

        $logger = $this->startRunningDeploy($username);
        $this->assertTrue(Coalescing::isRunning($user->fresh()));

        $logger->finish(DeployLogger::STATUS_SUCCESS);
        $this->assertFalse(Coalescing::isRunning($user->fresh()));
    }

    /**
     * A deploy killed before finish() -- an OOM, a worker timeout -- leaves
     * latest.json saying `running` forever, but the kernel drops its lock.
     * Reading the status alone parked every later push behind a finish()
     * that was never coming.
     */
    public function test_a_status_left_running_by_a_dead_deploy_does_not_count_as_running(): void
    {
        $username = $this->username();
        $hook = $this->hookFor($username);
        $logger = $this->startRunningDeploy($username);
        unset($logger); // the process is gone; its lock with it, finish() never ran

        $this->assertSame(DeployLogger::STATUS_RUNNING, DeployLogger::readLatestFor($username)['status'] ?? null);
        $this->assertFalse(Coalescing::isRunning($hook->user->fresh()));

        $response = $this->push($hook, 'guid-after-crash');

        $this->assertSame('queued', $response->body['outcome']);
        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    /**
     * The deploy a push saw running can finish between that check and the
     * push being marked pending; its finish() then finds nothing to run.
     * defer() looks again once the mark is down and runs it itself.
     */
    public function test_defer_runs_the_delivery_itself_when_the_deploy_already_finished(): void
    {
        $hook = $this->hookFor($this->username());
        $delivery = HookDelivery::create(['deploy_hook_id' => $hook->id, 'provider' => 'github', 'delivery_id' => 'guid-late', 'event' => 'push', 'outcome' => HookDelivery::OUTCOME_COALESCED]);

        Coalescing::defer($delivery, $hook->user);

        Queue::assertPushed(RunHookDelivery::class, fn (RunHookDelivery $job) => $job->deliveryId === $delivery->id);
        Queue::assertPushed(RunHookDelivery::class, 1);
        $this->assertNull($hook->fresh()->pending_delivery_id);
    }

    public function test_defer_waits_while_the_deploy_runs_and_the_follow_up_runs_exactly_once(): void
    {
        $username = $this->username();
        $hook = $this->hookFor($username);
        $logger = $this->startRunningDeploy($username);
        $delivery = HookDelivery::create(['deploy_hook_id' => $hook->id, 'provider' => 'github', 'delivery_id' => 'guid-wait', 'event' => 'push', 'outcome' => HookDelivery::OUTCOME_COALESCED]);

        Coalescing::defer($delivery, $hook->user);
        Queue::assertNothingPushed();

        $logger->finish(DeployLogger::STATUS_SUCCESS);
        Coalescing::runPendingFor($username); // a second finish-like caller finds nothing left to claim

        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_mark_pending_sets_the_hooks_pointer(): void
    {
        $hook = $this->hookFor($this->username());
        $delivery = HookDelivery::create([
            'deploy_hook_id' => $hook->id,
            'provider' => 'github',
            'delivery_id' => 'guid-a',
            'event' => 'push',
            'outcome' => HookDelivery::OUTCOME_COALESCED,
        ]);

        Coalescing::markPending($delivery);

        $this->assertSame($delivery->id, $hook->fresh()->pending_delivery_id);
    }

    public function test_mark_pending_supersedes_whatever_was_pending_before_it(): void
    {
        $hook = $this->hookFor($this->username());
        $first = HookDelivery::create(['deploy_hook_id' => $hook->id, 'provider' => 'github', 'delivery_id' => 'guid-a', 'event' => 'push', 'outcome' => HookDelivery::OUTCOME_COALESCED]);
        $second = HookDelivery::create(['deploy_hook_id' => $hook->id, 'provider' => 'github', 'delivery_id' => 'guid-b', 'event' => 'push', 'outcome' => HookDelivery::OUTCOME_COALESCED]);

        Coalescing::markPending($first);
        Coalescing::markPending($second);

        $this->assertSame(HookDelivery::RESULT_SUPERSEDED, $first->fresh()->result);
        $this->assertNull($second->fresh()->result, 'the survivor has not run yet');
        $this->assertSame($second->id, $hook->fresh()->pending_delivery_id);
    }

    public function test_run_pending_for_does_nothing_when_nothing_is_pending(): void
    {
        $username = $this->username();
        $this->hookFor($username);

        Coalescing::runPendingFor($username);

        Queue::assertNothingPushed();
    }

    public function test_run_pending_for_dispatches_and_clears_the_pointer(): void
    {
        $hook = $this->hookFor($this->username());
        $delivery = HookDelivery::create(['deploy_hook_id' => $hook->id, 'provider' => 'github', 'delivery_id' => 'guid-a', 'event' => 'push', 'outcome' => HookDelivery::OUTCOME_COALESCED]);
        $hook->update(['pending_delivery_id' => $delivery->id]);

        Coalescing::runPendingFor($hook->user->username);

        Queue::assertPushed(RunHookDelivery::class, fn (RunHookDelivery $job) => $job->deliveryId === $delivery->id);
        Queue::assertPushed(RunHookDelivery::class, 1);
        $this->assertNull($hook->fresh()->pending_delivery_id);
    }

    public function test_run_pending_for_an_unknown_account_does_nothing(): void
    {
        Coalescing::runPendingFor('no-such-account');

        Queue::assertNothingPushed();
    }

    // -- Through HookReceiver, end to end --------------------------------

    public function test_a_push_while_a_deploy_is_running_is_202_and_coalesced(): void
    {
        $username = $this->username();
        $hook = $this->hookFor($username);
        $logger = $this->startRunningDeploy($username); // held: a dropped logger releases its lock

        $response = $this->push($hook, 'guid-1');

        $this->assertSame(202, $response->status);
        $this->assertSame('coalesced', $response->body['outcome']);
        $delivery = $this->delivery('guid-1');
        $this->assertSame(HookDelivery::OUTCOME_COALESCED, $delivery->outcome);
        $this->assertNull($delivery->result);
        $this->assertSame($delivery->id, $hook->fresh()->pending_delivery_id);
        Queue::assertNothingPushed();
    }

    public function test_no_follow_up_runs_when_nothing_coalesced(): void
    {
        $username = $this->username();
        $this->hookFor($username);
        $logger = $this->startRunningDeploy($username);

        $logger->finish(DeployLogger::STATUS_SUCCESS);

        Queue::assertNothingPushed();
    }

    public function test_two_pushes_during_one_build_produce_exactly_two_deploys_total(): void
    {
        $username = $this->username();
        $hook = $this->hookFor($username);

        // First push: nothing running yet, so it queues its own deploy --
        // the "~50s build" the second push arrives during.
        $this->push($hook, 'guid-first', 'commit-one');
        Queue::assertPushed(RunHookDelivery::class, 1);
        $first = $this->delivery('guid-first');
        $this->assertSame(HookDelivery::OUTCOME_QUEUED, $first->outcome);

        // The queue is faked, so simulate the job having started the deploy
        // it was dispatched to run.
        $logger = $this->startRunningDeploy($username);

        $second = $this->push($hook, 'guid-second', 'commit-two');
        $this->assertSame('coalesced', $second->body['outcome']);
        Queue::assertPushed(RunHookDelivery::class, 1, 'the second push must not queue its own deploy');

        $logger->finish(DeployLogger::STATUS_SUCCESS);

        // Exactly two deploys in total: the first push's, and one follow-up
        // for the second -- not a third for anything else.
        Queue::assertPushed(RunHookDelivery::class, 2);
        $secondDelivery = $this->delivery('guid-second');
        Queue::assertPushed(RunHookDelivery::class, fn (RunHookDelivery $job) => $job->deliveryId === $secondDelivery->id);
        $this->assertNull($hook->fresh()->pending_delivery_id);
    }

    public function test_three_pushes_during_one_deploy_produce_one_follow_up_and_supersede_the_earlier_two(): void
    {
        $username = $this->username();
        $hook = $this->hookFor($username);
        $logger = $this->startRunningDeploy($username); // a manual rebuild, say

        $this->push($hook, 'guid-1', 'commit-1');
        $this->push($hook, 'guid-2', 'commit-2');
        $this->push($hook, 'guid-3', 'commit-3');

        $first = $this->delivery('guid-1');
        $second = $this->delivery('guid-2');
        $third = $this->delivery('guid-3');
        $this->assertSame([HookDelivery::OUTCOME_COALESCED, HookDelivery::OUTCOME_COALESCED, HookDelivery::OUTCOME_COALESCED], [$first->outcome, $second->outcome, $third->outcome]);
        $this->assertSame(HookDelivery::RESULT_SUPERSEDED, $first->result, 'superseded the moment the second push coalesced');
        $this->assertSame(HookDelivery::RESULT_SUPERSEDED, $second->result, 'superseded the moment the third push coalesced');
        $this->assertNull($third->result, 'still the pending one, waiting for the deploy to finish');
        Queue::assertNothingPushed();

        $logger->finish(DeployLogger::STATUS_SUCCESS);

        Queue::assertPushed(RunHookDelivery::class, 1, 'one follow-up deploy, not one per coalesced push');
        Queue::assertPushed(RunHookDelivery::class, fn (RunHookDelivery $job) => $job->deliveryId === $third->id);
        $this->assertNull($hook->fresh()->pending_delivery_id);
    }

    public function test_a_push_during_a_manual_rebuild_that_fails_still_triggers_the_follow_up(): void
    {
        $username = $this->username();
        $hook = $this->hookFor($username);
        $logger = $this->startRunningDeploy($username);

        $this->push($hook, 'guid-1');

        $logger->finish(DeployLogger::STATUS_FAILED, 'the build broke');

        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_push_during_a_manual_rebuild_that_is_cancelled_still_triggers_the_follow_up(): void
    {
        $username = $this->username();
        $hook = $this->hookFor($username);
        $logger = $this->startRunningDeploy($username);

        $this->push($hook, 'guid-1');

        $logger->finish(DeployLogger::STATUS_CANCELLED, 'cancelled by user');

        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_push_during_a_partial_deploy_still_triggers_the_follow_up(): void
    {
        $username = $this->username();
        $hook = $this->hookFor($username);
        $logger = $this->startRunningDeploy($username);

        $this->push($hook, 'guid-1');

        $logger->finish(DeployLogger::STATUS_PARTIAL, 'served with warnings');

        Queue::assertPushed(RunHookDelivery::class, 1);
    }

    public function test_a_push_to_a_different_hook_of_the_same_account_coalesces_and_follows_up_too(): void
    {
        $username = $this->username();
        $siteHook = $this->hook($this->siteGitUser('main', $username), self::SECRET, 'public_html');
        $logger = $this->startRunningDeploy($username);

        $response = $this->push($siteHook, 'guid-site-1');

        $this->assertSame('coalesced', $response->body['outcome']);
        $this->assertSame($this->delivery('guid-site-1')->id, $siteHook->fresh()->pending_delivery_id);

        $logger->finish(DeployLogger::STATUS_SUCCESS);

        Queue::assertPushed(RunHookDelivery::class, 1);
        $this->assertNull($siteHook->fresh()->pending_delivery_id);
    }
}
