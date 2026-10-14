<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Jobs\DeployProject;
use App\Jobs\RebuildProject;
use App\Lib\Deploy\DeployLog\DeployFinishLine;
use App\Lib\Deploy\DeployLog\DeployHistory;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Lib\Deploy\DeployLog\LogStorage;
use App\Models\DeployHook;
use App\Models\HookDelivery;
use App\Models\Task;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** The kept deploys of a project, read back from their logs, tasks and hook deliveries. */
class DeployHistoryTest extends TestCase
{
    use InMemoryDatabase;

    private const SHA = 'a8c1f4d9e2b7305c6d4a1e8f9b0c2d3e4f5a6b7c';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->user = $this->makeUser('dh' . bin2hex(random_bytes(4)), ['template' => 'dind']);
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->user->username);
        parent::tearDown();
    }

    /**
     * @param list<string> $starts
     */
    private function oldDeploy(string $id, array $starts, ?string $status, ?string $error = null): void
    {
        LogStorage::ensureDirectory(DeployLogPaths::base());
        LogStorage::ensureDirectory(DeployLogPaths::userDir($this->user->username));
        $logger = DeployLogger::forDeploy($this->user->username, $id);
        foreach ($starts as $start) {
            $logger->info($start);
        }
        $logger->dim('build output');
        if ($status !== null) {
            $level = $status === DeployLogger::STATUS_SUCCESS ? DeployLogger::LEVEL_OK : DeployLogger::LEVEL_ERROR;
            $logger->log($level, DeployFinishLine::message($status, $error));
        }
    }

    /**
     * @param array<string, mixed> $details
     */
    private function task(string $jobType, array $details): Task
    {
        return Task::start(jobType: $jobType, queue: 'default', username: $this->user->username, details: $details);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function byId(): array
    {
        $entries = [];
        foreach (DeployHistory::of($this->user) as $entry) {
            $entries[$entry['id']] = $entry;
        }

        return $entries;
    }

    public function test_a_project_that_never_deployed_has_no_history(): void
    {
        $this->assertSame([], DeployHistory::of($this->user));
        $this->assertNull(DeployHistory::find($this->user, '20260101-000000-aaaaaa'));
    }

    public function test_kept_deploys_come_newest_first_with_the_verdict_their_log_ends_on(): void
    {
        $this->oldDeploy('20260101-000000-aaaaaa', ['Deploy started (source: rebuild)'], DeployLogger::STATUS_SUCCESS);
        $this->oldDeploy('20260102-000000-bbbbbb', ['Deploy started (source: rebuild)'], DeployLogger::STATUS_FAILED, 'npm ci exited 1');
        $this->oldDeploy('20260103-000000-cccccc', ['Deploy started (source: rebuild)'], null);
        $latest = DeployLogger::start($this->user->username);
        $latest->info('Deploy started (source: rebuild)');

        $history = DeployHistory::of($this->user);

        $this->assertSame(
            [$latest->getDeployId(), '20260103-000000-cccccc', '20260102-000000-bbbbbb', '20260101-000000-aaaaaa'],
            array_column($history, 'id')
        );
        $this->assertSame(DeployLogger::STATUS_RUNNING, $history[0]['status'], 'the latest deploy reads latest.json');
        $this->assertNull($history[0]['finished_at']);
        $this->assertSame(DeployHistory::STATUS_UNKNOWN, $history[1]['status'], 'a log that ends without a verdict');
        $this->assertNull($history[1]['finished_at']);
        $this->assertSame(DeployLogger::STATUS_FAILED, $history[2]['status']);
        $this->assertSame('npm ci exited 1', $history[2]['error']);
        $this->assertSame(DeployLogger::STATUS_SUCCESS, $history[3]['status']);
        $this->assertNull($history[3]['error']);
        $this->assertIsInt($history[3]['started_at']);
        $this->assertIsInt($history[3]['finished_at']);
    }

    public function test_the_latest_deploy_takes_its_verdict_from_latest_json(): void
    {
        $logger = DeployLogger::start($this->user->username);
        $logger->info('Deploy started (source: rebuild)');
        $logger->finish(DeployLogger::STATUS_CANCELLED, 'cancelled by the operator');

        $entry = DeployHistory::find($this->user, $logger->getDeployId());

        $this->assertSame(DeployLogger::STATUS_CANCELLED, $entry['status']);
        $this->assertSame('cancelled by the operator', $entry['error']);
        $this->assertIsInt($entry['finished_at']);
    }

    public function test_source_and_commit_come_from_the_start_lines(): void
    {
        $this->oldDeploy('20260101-000000-aaaaaa', ['Deploy started (source: git, commit: ' . self::SHA . ')'], DeployLogger::STATUS_SUCCESS);
        $this->oldDeploy('20260102-000000-bbbbbb', ['Deploy started (source: archive)', 'Deploy started (source: rebuild)'], DeployLogger::STATUS_SUCCESS);
        $this->oldDeploy('20260103-000000-cccccc', ['Deploy started (source: dind template)', 'Deploy started (source: archive)'], DeployLogger::STATUS_SUCCESS);
        $this->oldDeploy('20260104-000000-dddddd', ['Deploy started (source: git, repo: https://github.com/acme/app.git)'], DeployLogger::STATUS_SUCCESS);

        $entries = $this->byId();

        $this->assertSame(['git', self::SHA], [$entries['20260101-000000-aaaaaa']['source'], $entries['20260101-000000-aaaaaa']['commit']]);
        $this->assertSame('archive', $entries['20260102-000000-bbbbbb']['source'], 'the rebuild an archive deploy runs is a follow-on');
        $this->assertSame('archive', $entries['20260103-000000-cccccc']['source'], 'files that reached a template account');
        $this->assertSame(['git', null], [$entries['20260104-000000-dddddd']['source'], $entries['20260104-000000-dddddd']['commit']]);
    }

    public function test_the_create_is_the_install_and_every_later_deploy_a_redeploy(): void
    {
        $this->oldDeploy('20260101-000000-aaaaaa', [
            'Deploy started (source: git, repo: https://github.com/acme/app.git)',
            'Cloning repository https://github.com/acme/app.git (branch: main)',
        ], DeployLogger::STATUS_SUCCESS);
        $this->oldDeploy('20260102-000000-bbbbbb', ['Deploy started (source: dind template)', 'Deploy started (source: archive)'], DeployLogger::STATUS_SUCCESS);
        $this->oldDeploy('20260103-000000-cccccc', ['Deploy started (source: git, commit: ' . self::SHA . ', branch: release)'], DeployLogger::STATUS_SUCCESS);
        $this->oldDeploy('20260104-000000-dddddd', ['Deploy started (source: archive)', 'Deploy started (source: rebuild)'], DeployLogger::STATUS_SUCCESS);
        $this->oldDeploy('20260105-000000-eeeeee', ['Deploy started (source: git, commit: ' . self::SHA . ')'], DeployLogger::STATUS_SUCCESS);
        $this->oldDeploy('20260106-000000-ffffff', [], DeployLogger::STATUS_SUCCESS);
        $this->oldDeploy('20260107-000000-abcdef', [
            'Deploy started (source: git, repo: https://github.com/acme/app.git)',
            'Cloning repository https://github.com/acme/app.git',
            'Repository cloned (branch: trunk)',
        ], DeployLogger::STATUS_SUCCESS);

        $entries = $this->byId();
        $pick = static fn (string $id): array => [$entries[$id]['kind'], $entries[$id]['branch']];

        $this->assertSame(['install', 'main'], $pick('20260101-000000-aaaaaa'), 'a git create, and the branch it cloned');
        $this->assertSame(['install', null], $pick('20260102-000000-bbbbbb'), 'a template create that later received files');
        $this->assertSame(['redeploy', 'release'], $pick('20260103-000000-cccccc'));
        $this->assertSame(['redeploy', null], $pick('20260104-000000-dddddd'));
        $this->assertSame(['redeploy', null], $pick('20260105-000000-eeeeee'), 'a redeploy logged before the branch was');
        $this->assertSame([null, null], $pick('20260106-000000-ffffff'), 'a log that names no source');
        $this->assertSame(['install', 'trunk'], $pick('20260107-000000-abcdef'), 'a create that cloned the default branch');
    }

    public function test_trigger_action_task_and_commit_come_from_the_newest_task_that_ran_the_deploy(): void
    {
        $this->oldDeploy('20260101-000000-aaaaaa', ['Deploy started (source: dind template)'], DeployLogger::STATUS_SUCCESS);
        $this->oldDeploy('20260102-000000-bbbbbb', ['Deploy started (source: git)'], DeployLogger::STATUS_SUCCESS);
        $create = $this->task(DeployProject::class, ['action' => 'deploy', 'trigger' => 'mcp', 'deploy_id' => '20260101-000000-aaaaaa']);
        $upload = $this->task(RebuildProject::class, ['action' => 'deploy_archive', 'trigger' => 'api', 'deploy_id' => '20260101-000000-aaaaaa']);
        $pull = $this->task(RebuildProject::class, ['action' => 'git_pull', 'trigger' => 'cli', 'commit' => self::SHA, 'deploy_id' => '20260102-000000-bbbbbb']);
        $this->task(RebuildProject::class, ['action' => 'rebuild', 'trigger' => 'api']);

        $entries = $this->byId();

        $this->assertNotSame($create->id, $entries['20260101-000000-aaaaaa']['task_id']);
        $this->assertSame([$upload->id, 'deploy_archive', 'api'], [
            $entries['20260101-000000-aaaaaa']['task_id'],
            $entries['20260101-000000-aaaaaa']['action'],
            $entries['20260101-000000-aaaaaa']['trigger'],
        ]);
        $this->assertSame([$pull->id, 'git_pull', 'cli', self::SHA], [
            $entries['20260102-000000-bbbbbb']['task_id'],
            $entries['20260102-000000-bbbbbb']['action'],
            $entries['20260102-000000-bbbbbb']['trigger'],
            $entries['20260102-000000-bbbbbb']['commit'],
        ]);
    }

    public function test_a_deploy_no_task_or_delivery_names_has_no_trigger(): void
    {
        $this->oldDeploy('20260101-000000-aaaaaa', ['Deploy started (source: rebuild)'], DeployLogger::STATUS_SUCCESS);

        $entry = DeployHistory::find($this->user, '20260101-000000-aaaaaa');

        $this->assertNull($entry['trigger']);
        $this->assertNull($entry['action']);
        $this->assertNull($entry['task_id']);
    }

    public function test_a_hook_deploy_is_triggered_by_the_hook_and_names_the_delivered_commit(): void
    {
        $this->oldDeploy('20260101-000000-aaaaaa', ['Deploy started (source: push)'], DeployLogger::STATUS_SUCCESS);
        $this->oldDeploy('20260102-000000-bbbbbb', ['Deploy started (source: rebuild)'], DeployLogger::STATUS_SUCCESS);
        $hook = new DeployHook(['user_id' => $this->user->id, 'path_key' => 'project', 'public_id' => DeployHook::newPublicId()]);
        $hook->setSecret('a-shared-secret-of-some-length');
        $hook->save();
        HookDelivery::create([
            'deploy_hook_id' => $hook->id,
            'provider' => 'github',
            'branch' => 'main',
            'commit' => self::SHA,
            'outcome' => HookDelivery::OUTCOME_QUEUED,
            'deploy_id' => '20260102-000000-bbbbbb',
        ]);

        $entries = $this->byId();

        $this->assertSame(['hook', 'hook'], [$entries['20260101-000000-aaaaaa']['source'], $entries['20260101-000000-aaaaaa']['trigger']], 'a delivery since pruned');
        $this->assertSame(['hook', self::SHA, 'main'], [
            $entries['20260102-000000-bbbbbb']['trigger'],
            $entries['20260102-000000-bbbbbb']['commit'],
            $entries['20260102-000000-bbbbbb']['branch'],
        ], 'the delivery names what the log does not');
    }

    public function test_another_projects_task_and_delivery_never_name_this_projects_deploy(): void
    {
        $this->oldDeploy('20260101-000000-aaaaaa', ['Deploy started (source: rebuild)'], DeployLogger::STATUS_SUCCESS);
        $other = $this->makeUser('dho' . bin2hex(random_bytes(4)));
        Task::start(jobType: RebuildProject::class, queue: 'default', username: $other->username, details: ['action' => 'rebuild', 'trigger' => 'api', 'deploy_id' => '20260101-000000-aaaaaa']);
        $hook = new DeployHook(['user_id' => $other->id, 'path_key' => 'project', 'public_id' => DeployHook::newPublicId()]);
        $hook->setSecret('a-shared-secret-of-some-length');
        $hook->save();
        HookDelivery::create(['deploy_hook_id' => $hook->id, 'provider' => 'github', 'outcome' => HookDelivery::OUTCOME_QUEUED, 'deploy_id' => '20260101-000000-aaaaaa']);

        $entry = DeployHistory::find($this->user, '20260101-000000-aaaaaa');

        $this->assertNull($entry['trigger']);
        $this->assertNull($entry['task_id']);
    }

    public function test_find_answers_only_ids_the_project_keeps(): void
    {
        $this->oldDeploy('20260101-000000-aaaaaa', ['Deploy started (source: rebuild)'], DeployLogger::STATUS_SUCCESS);

        $this->assertNotNull(DeployHistory::find($this->user, '20260101-000000-aaaaaa'));
        $this->assertNull(DeployHistory::find($this->user, '20260101-000000-ffffff'));
        $this->assertNull(DeployHistory::find($this->user, '../latest'));
    }

    public function test_a_new_deploy_keeps_deploy_log_keep_logs(): void
    {
        config(['deploy.log_keep' => 2]);
        $this->oldDeploy('20260101-000000-aaaaaa', ['Deploy started (source: rebuild)'], DeployLogger::STATUS_SUCCESS);
        $this->oldDeploy('20260102-000000-bbbbbb', ['Deploy started (source: rebuild)'], DeployLogger::STATUS_SUCCESS);
        $this->oldDeploy('20260103-000000-cccccc', ['Deploy started (source: rebuild)'], DeployLogger::STATUS_SUCCESS);

        $latest = DeployLogger::start($this->user->username);

        $this->assertSame([$latest->getDeployId(), '20260103-000000-cccccc'], array_column(DeployHistory::of($this->user), 'id'));
    }
}
