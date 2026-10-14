<?php

namespace Tests\Unit\DeployHook;

use App\Exceptions\DeployAlreadyRunningException;
use App\Jobs\RunHookDelivery;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\DeployHook\CheckoutSync;
use App\Lib\DeployHook\DeliveryRunner;
use App\Lib\DeployHook\InterruptedDeliveries;
use App\Models\DeployHook;
use App\Models\HookDelivery;
use App\Models\User;
use App\System\Project\Git\Exception as GitException;
use Illuminate\Support\Facades\Queue;

/**
 * What a queued delivery does to its row: `deployed` when the pull and the
 * rebuild both came through, `deploy_failed` when either did not -- and that
 * it never throws, so a failed deploy is a recorded result and not a retry.
 */
class DeliveryRunnerTest extends DeployHookTestCase
{
    private function queued(string $branch = 'main'): HookDelivery
    {
        $hook = $this->hook($this->user($branch));

        return HookDelivery::create([
            'deploy_hook_id' => $hook->id,
            'provider' => 'github',
            'delivery_id' => 'guid-run-1',
            'event' => 'push',
            'branch' => $branch,
            'commit' => 'a8c1f4d9e2b7305c6d4a1e8f9b0c2d3e4f5a6b7c',
            'outcome' => HookDelivery::OUTCOME_QUEUED,
        ]);
    }

    private function syncThatDoes(?\Throwable $failure = null): RecordingCheckoutSync
    {
        return new RecordingCheckoutSync($failure);
    }

    public function test_a_pull_and_rebuild_that_come_through_are_recorded_as_deployed(): void
    {
        $delivery = $this->queued();
        $sync = $this->syncThatDoes();

        (new DeliveryRunner($sync))->run($delivery);

        $fresh = $delivery->fresh();
        $this->assertSame(HookDelivery::RESULT_DEPLOYED, $fresh->result);
        $this->assertNull($fresh->detail);
        // The outcome was fixed on arrival and is not the work's to rewrite.
        $this->assertSame(HookDelivery::OUTCOME_QUEUED, $fresh->outcome);
    }

    public function test_the_checkout_is_synced_for_the_hooks_own_project_and_path_with_the_pushed_commit(): void
    {
        $delivery = $this->queued();
        $sync = $this->syncThatDoes();

        (new DeliveryRunner($sync))->run($delivery);

        $this->assertCount(1, $sync->calls);
        $this->assertSame('alice', $sync->calls[0]['username']);
        $this->assertSame('project', $sync->calls[0]['pathKey']);
        $this->assertSame('a8c1f4d9e2b7305c6d4a1e8f9b0c2d3e4f5a6b7c', $sync->calls[0]['commit']);
    }

    public function test_a_failed_rebuild_is_recorded_as_deploy_failed_with_its_reason(): void
    {
        $delivery = $this->queued();

        (new DeliveryRunner($this->syncThatDoes(new \RuntimeException('npm ci exited with 1'))))->run($delivery);

        $fresh = $delivery->fresh();
        $this->assertSame(HookDelivery::RESULT_DEPLOY_FAILED, $fresh->result);
        $this->assertStringContainsString('npm ci exited with 1', (string) $fresh->detail);
    }

    public function test_a_failed_pull_is_recorded_as_deploy_failed_and_says_it_was_the_pull(): void
    {
        $delivery = $this->queued();

        (new DeliveryRunner($this->syncThatDoes(new GitException('fatal: could not read from remote repository', 400))))->run($delivery);

        $fresh = $delivery->fresh();
        $this->assertSame(HookDelivery::RESULT_DEPLOY_FAILED, $fresh->result);
        $this->assertStringContainsString('git pull failed', (string) $fresh->detail);
        $this->assertStringContainsString('could not read from remote repository', (string) $fresh->detail);
    }

    public function test_a_credential_in_a_failure_message_is_not_written_to_the_row(): void
    {
        $delivery = $this->queued();
        $failure = new GitException('fatal: unable to access https://user:ghp_secrettoken@github.com/o/r.git/', 400);

        (new DeliveryRunner($this->syncThatDoes($failure)))->run($delivery);

        $this->assertStringNotContainsString('ghp_secrettoken', (string) $delivery->fresh()->detail);
    }

    /** The deploy the job lost the race to, holding the account's lock. */
    private function runningDeploy(): DeployLogger
    {
        $logger = DeployLogger::start('alice');
        $this->beforeApplicationDestroyed(static function () use ($logger): void {
            $logger->finish(DeployLogger::STATUS_SUCCESS);
            DeployLogger::deleteUserLogs('alice');
        });

        return $logger;
    }

    public function test_a_deploy_already_running_when_the_job_finally_runs_is_coalesced_not_failed(): void
    {
        Queue::fake();
        $delivery = $this->queued();
        $this->runningDeploy();

        (new DeliveryRunner($this->syncThatDoes(new DeployAlreadyRunningException('A deployment is already running for this user.'))))->run($delivery);

        $fresh = $delivery->fresh();
        $this->assertNull($fresh->result, 'not a failure: it is still waiting to run');
        $this->assertSame(HookDelivery::OUTCOME_QUEUED, $fresh->outcome, 'the outcome was fixed on arrival');
        $hook = DeployHook::findOrFail($delivery->deploy_hook_id);
        $this->assertSame($delivery->id, $hook->pending_delivery_id);
        Queue::assertNothingPushed();
    }

    public function test_a_deploy_that_finished_before_the_job_could_wait_for_it_runs_the_delivery_again(): void
    {
        Queue::fake();
        $delivery = $this->queued();

        // The lock conflict happened, but that deploy's finish() has already
        // looked for pending work -- nothing would ever pick this one up.
        (new DeliveryRunner($this->syncThatDoes(new DeployAlreadyRunningException('A deployment is already running for this user.'))))->run($delivery);

        Queue::assertPushed(RunHookDelivery::class, fn (RunHookDelivery $job) => $job->deliveryId === $delivery->id);
        $this->assertNull(DeployHook::findOrFail($delivery->deploy_hook_id)->pending_delivery_id);
    }

    public function test_a_deploy_already_running_supersedes_whatever_was_pending_before_it(): void
    {
        Queue::fake();
        $delivery = $this->queued();
        $this->runningDeploy();
        $hook = DeployHook::findOrFail($delivery->deploy_hook_id);
        $earlierPending = HookDelivery::create([
            'deploy_hook_id' => $hook->id,
            'provider' => 'github',
            'delivery_id' => 'guid-earlier',
            'event' => 'push',
            'outcome' => HookDelivery::OUTCOME_COALESCED,
        ]);
        $hook->update(['pending_delivery_id' => $earlierPending->id]);

        (new DeliveryRunner($this->syncThatDoes(new DeployAlreadyRunningException('A deployment is already running for this user.'))))->run($delivery);

        $this->assertSame(HookDelivery::RESULT_SUPERSEDED, $earlierPending->fresh()->result);
        $this->assertSame($delivery->id, $hook->fresh()->pending_delivery_id);
    }

    public function test_a_delivery_whose_project_is_gone_is_recorded_as_failed_without_touching_anything(): void
    {
        $delivery = $this->queued();
        \Illuminate\Support\Facades\DB::table('users')->delete();
        $sync = $this->syncThatDoes();

        (new DeliveryRunner($sync))->run($delivery->fresh());

        $this->assertSame([], $sync->calls);
        $this->assertSame(HookDelivery::RESULT_DEPLOY_FAILED, $delivery->fresh()->result);
    }

    public function test_the_job_hands_its_delivery_to_the_runner(): void
    {
        $delivery = $this->queued();
        $sync = $this->syncThatDoes();

        (new RunHookDelivery($delivery->id))->handle(new DeliveryRunner($sync));

        $this->assertCount(1, $sync->calls);
        $this->assertSame(HookDelivery::RESULT_DEPLOYED, $delivery->fresh()->result);
    }

    public function test_the_job_for_a_delivery_that_no_longer_exists_does_nothing(): void
    {
        $sync = $this->syncThatDoes();

        (new RunHookDelivery(9999))->handle(new DeliveryRunner($sync));

        $this->assertSame([], $sync->calls);
    }

    public function test_a_job_that_dies_leaves_the_delivery_failed_rather_than_forever_queued(): void
    {
        $delivery = $this->queued();

        (new RunHookDelivery($delivery->id))->failed(new \RuntimeException('worker killed'));

        $fresh = $delivery->fresh();
        $this->assertSame(HookDelivery::RESULT_DEPLOY_FAILED, $fresh->result);
        $this->assertStringContainsString('worker killed', (string) $fresh->detail);
    }

    public function test_a_job_that_dies_after_the_result_was_written_does_not_overwrite_it(): void
    {
        $delivery = $this->queued();
        (new DeliveryRunner($this->syncThatDoes()))->run($delivery);

        (new RunHookDelivery($delivery->id))->failed(new \RuntimeException('late'));

        $this->assertSame(HookDelivery::RESULT_DEPLOYED, $delivery->fresh()->result);
    }

    private function forgetDeploys(): void
    {
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs('alice'));
    }

    /**
     * A `partial` deploy returns normally from the rebuild -- the app started
     * but answered 500. On the live dev server that read as `deployed`.
     */
    public function test_a_partial_deploy_is_recorded_as_partial_and_why(): void
    {
        $this->forgetDeploys();
        $delivery = $this->queued();

        (new DeliveryRunner(new RecordingCheckoutSync(null, DeployLogger::STATUS_PARTIAL, 'The application is running but failing: it answered 500.')))->run($delivery);

        $fresh = $delivery->fresh();
        $this->assertSame(HookDelivery::RESULT_PARTIAL, $fresh->result);
        $this->assertStringContainsString('answered 500', (string) $fresh->detail);
        $this->assertSame(DeployLogger::readLatestFor('alice')['id'] ?? null, $fresh->deploy_id);
    }

    public function test_a_successful_deploy_records_the_deploy_it_ran_under(): void
    {
        $this->forgetDeploys();
        $delivery = $this->queued();

        (new DeliveryRunner(new RecordingCheckoutSync(null, DeployLogger::STATUS_SUCCESS)))->run($delivery);

        $fresh = $delivery->fresh();
        $this->assertSame(HookDelivery::RESULT_DEPLOYED, $fresh->result);
        $this->assertNull($fresh->detail);
        $this->assertSame(DeployLogger::readLatestFor('alice')['id'] ?? null, $fresh->deploy_id);
    }

    /**
     * The worker running a delivery was killed mid-deploy (reproduced on the
     * dev server with `supervisorctl restart queue:*`). Its job's failed()
     * fires only after the queue's day-long retry_after, so the delivery sat
     * `queued` with no result. The next delivery settles it.
     */
    public function test_a_delivery_whose_deploy_was_killed_is_failed_when_the_next_one_runs(): void
    {
        $this->forgetDeploys();
        $killed = $this->queued();
        $dead = DeployLogger::start('alice');
        $killed->update(['deploy_id' => $dead->getDeployId()]);
        unset($dead); // the process is gone, its lock with it; finish() never ran

        $next = HookDelivery::create([
            'deploy_hook_id' => $killed->deploy_hook_id,
            'provider' => 'github',
            'delivery_id' => 'guid-run-2',
            'event' => 'push',
            'branch' => 'main',
            'outcome' => HookDelivery::OUTCOME_QUEUED,
        ]);

        (new DeliveryRunner(new RecordingCheckoutSync(null, DeployLogger::STATUS_SUCCESS)))->run($next);

        $this->assertSame(HookDelivery::RESULT_DEPLOY_FAILED, $killed->fresh()->result);
        $this->assertSame(InterruptedDeliveries::DETAIL, $killed->fresh()->detail);
        $this->assertSame(HookDelivery::RESULT_DEPLOYED, $next->fresh()->result);
    }

    public function test_a_killed_delivery_is_settled_when_the_hook_is_shown(): void
    {
        $this->forgetDeploys();
        $killed = $this->queued();
        $dead = DeployLogger::start('alice');
        $killed->update(['deploy_id' => $dead->getDeployId()]);
        unset($dead);

        InterruptedDeliveries::settle($killed->hook, 'alice');

        $this->assertSame(HookDelivery::RESULT_DEPLOY_FAILED, $killed->fresh()->result);
    }

    public function test_a_delivery_whose_deploy_is_still_running_is_left_alone(): void
    {
        $delivery = $this->queued();
        $running = $this->runningDeploy();
        $delivery->update(['deploy_id' => $running->getDeployId()]);

        InterruptedDeliveries::settle($delivery->hook, 'alice');

        $this->assertNull($delivery->fresh()->result);
    }

    /**
     * Its deploy ended normally and the runner is a moment away from writing
     * the result: latest.json names that deploy with a final status.
     */
    public function test_a_delivery_whose_deploy_finished_normally_is_left_for_its_runner(): void
    {
        $this->forgetDeploys();
        $delivery = $this->queued();
        $finished = DeployLogger::start('alice');
        $delivery->update(['deploy_id' => $finished->getDeployId()]);
        $finished->finish(DeployLogger::STATUS_SUCCESS);

        InterruptedDeliveries::settle($delivery->hook, 'alice');

        $this->assertNull($delivery->fresh()->result);
    }
}

final class RecordingCheckoutSync implements CheckoutSync
{
    /** @var list<array{username: string, pathKey: string, commit: ?string}> */
    public array $calls = [];

    /**
     * @param ?string $deployStatus when set, run a real deploy for the account that ends with this status
     */
    public function __construct(
        private readonly ?\Throwable $failure = null,
        private readonly ?string $deployStatus = null,
        private readonly ?string $deployError = null,
    ) {
    }

    public function pullAndRebuild(User $user, string $pathKey, ?string $pushedCommit, ?callable $onDeployStarted = null): void
    {
        $this->calls[] = ['username' => $user->username, 'pathKey' => $pathKey, 'commit' => $pushedCommit];

        if ($this->deployStatus !== null) {
            $logger = DeployLogger::start($user->username);
            if ($onDeployStarted !== null) {
                $onDeployStarted($logger->getDeployId());
            }
            $logger->finish($this->deployStatus, $this->deployError);
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
