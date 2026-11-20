<?php

namespace Tests\Integration\Git;

use App\Jobs\DeployProject;
use App\Exceptions\DeployBusyException;
use App\Jobs\RebuildProject;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Lib\Http\OutboundUrl;
use App\Lib\Project\ProjectRebuild;
use App\Models\DeployHook;
use App\Models\Task;
use App\Models\User;
use App\System\Project\Git\CheckoutRedeploy;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestNetwork;
use Tests\Integration\DeployHook\DeployHookTestCase;
use Tests\Integration\DeployHook\SpyCheckoutRedeploy;

/**
 * The /git REST endpoints, through the router with bearer authentication
 * switched off and a fake host underneath ({@see FakesGitHost}).
 */
class GitEndpointsTest extends DeployHookTestCase
{
    use FakesGitHost;

    private const URL = '/api/projects/alice/git';
    private const REPO = 'https://github.com/octocat/Hello-World.git';

    private SpyCheckoutRedeploy $redeploy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        config(['app.debug' => false]);
        $this->fakeGitHost();
        OutboundUrl::setResolverForTests(static fn (string $host): array => $host === 'github.com' ? [TestNetwork::routable(4)] : []);
        $this->redeploy = new SpyCheckoutRedeploy();
        $this->app->instance(CheckoutRedeploy::class, $this->redeploy);
    }

    protected function tearDown(): void
    {
        $this->restoreHost();
        OutboundUrl::setResolverForTests(null);
        DeployLogger::deleteUserLogs('alice');

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function envelope(string $path, string $managedBy, bool $connected, bool $exists = false): array
    {
        return [
            'path' => $path,
            'path_key' => $path,
            'managed_by' => $managedBy,
            'connected' => $connected,
            'repository_exists' => $exists,
            'remote_url' => null,
            'branch' => null,
            'dirty' => false,
            'tracking' => null,
            'commits_ahead' => null,
            'commits_behind' => null,
            'connecting' => false,
            'connecting_since' => null,
        ];
    }

    private function siteGitPublicHtml(?string $token = null): User
    {
        $user = $this->siteGitUser();
        $user->putSiteGit('public_html', ['repo_url' => self::REPO, 'branch' => 'main', 'token' => $token]);

        return $user;
    }

    public function test_status(): void
    {
        $this->user('main');
        $this->withoutRepository();

        $response = $this->getJson(self::URL . '/status');

        $response->assertStatus(200);
        $this->assertSame(json_encode(['data' => $this->envelope('project', 'deploy', true)]), $response->getContent());
    }

    /** A stopped container says nothing about the checkout in it: the answer names the container, not a missing repository. */
    public function test_status_of_a_stopped_container_is_a_503_naming_it(): void
    {
        $this->user('main');
        $this->respond("'rev-parse' '--is-inside-work-tree'", 1, "service \"dind\" is not running\n");

        $response = $this->getJson(self::URL . '/status');

        $response->assertStatus(503);
        $this->assertSame(
            json_encode(['message' => 'Git could not run: the project\'s container is not running (service "dind" is not running)']),
            $response->getContent(),
        );
    }

    public function test_status_of_a_container_that_refuses_the_exec_is_a_503_with_the_docker_error(): void
    {
        $this->user('main');
        $refused = 'OCI runtime exec failed: exec failed: unable to start container process: unsafe procfs detected: unknown';
        $this->respond("'rev-parse' '--is-inside-work-tree'", 126, $refused . "\n");

        $response = $this->getJson(self::URL . '/status');

        $response->assertStatus(503);
        $this->assertSame(
            json_encode(['message' => "Git could not run: docker would not run it in the project's container ({$refused})"]),
            $response->getContent(),
        );
    }

    /** @return array<string, array{string, string, string, array<string, string>, string, array<string, string>}> */
    public static function queuedChangesGitCannotRunFor(): array
    {
        $stopped = "service \"dind\" is not running\n";
        $refused = "OCI runtime exec failed: exec failed: unable to start container process: unsafe procfs detected: unknown\n";

        return [
            'pull, stopped' => [$stopped, 'POST', '/pull', ['strategy' => 'force'], ProjectRebuild::GIT_PULL, ['path' => 'project', 'strategy' => 'force']],
            'branch change, stopped' => [$stopped, 'PUT', '/change-branch', ['branch' => 'dev'], ProjectRebuild::CHANGE_BRANCH, ['path' => 'project', 'branch' => 'dev']],
            'revert, stopped' => [$stopped, 'POST', '/revert', ['ref' => 'abc123'], ProjectRebuild::REVERT, ['path' => 'project', 'ref' => 'abc123']],
            'pull, exec refused' => [$refused, 'POST', '/pull', [], ProjectRebuild::GIT_PULL, ['path' => 'project', 'strategy' => 'ff']],
        ];
    }

    /**
     * The checks run git in the account's container, which a stopped account does not have: the change is queued
     * anyway, as a push to it is, and the job starts the account before its change, which checks the same there.
     *
     * @param array<string, string> $body
     * @param array<string, string> $queued
     */
    #[DataProvider('queuedChangesGitCannotRunFor')]
    public function test_a_change_git_cannot_run_for_yet_is_queued_for_the_job_to_start_the_account(
        string $dockerError,
        string $method,
        string $endpoint,
        array $body,
        string $action,
        array $queued,
    ): void {
        Queue::fake();
        $this->user('main');
        $this->respond("'rev-parse' '--is-inside-work-tree'", 1, $dockerError);

        $response = $this->json($method, self::URL . $endpoint, $body);

        $response->assertStatus(202);
        $response->assertJsonPath('data.details.action', $action);
        Queue::assertPushed(RebuildProject::class, static fn (RebuildProject $job): bool => $job->action === $action && $job->git === $queued);
        $this->assertCheckoutUntouched();
        $this->assertSame([], $this->redeploy->starts, 'the account is started by the job, under its deploy, not in the request');
    }

    public function test_status_fetch_accepts_a_string_flag(): void
    {
        $this->user('main');
        $this->withRepository();

        $this->getJson(self::URL . '/status?fetch=true')->assertStatus(200);
        $this->assertTrue($this->called("'fetch' 'origin'"));
    }

    public function test_an_unknown_project_is_a_404(): void
    {
        $response = $this->getJson('/api/projects/nobody/git/status');

        $response->assertStatus(404);
        $this->assertSame('{"message":"Project not found"}', $response->getContent());
    }

    public function test_a_bad_ref_is_a_422_naming_the_field(): void
    {
        $this->user('main');

        $response = $this->putJson(self::URL . '/change-branch', ['branch' => 'bad..ref']);

        $response->assertStatus(422);
        $this->assertSame(
            '{"message":"Invalid git ref name.","errors":{"branch":["Invalid git ref name."]},"problems":[{"field":"branch","code":"branch_invalid","message":"Invalid git ref name.","hints":[]}]}',
            $response->getContent(),
        );
    }

    public function test_a_git_refusal_is_a_422_under_git(): void
    {
        $this->user('main', ['git_repo' => '']);

        $response = $this->postJson(self::URL . '/pull', []);

        $response->assertStatus(422);
        $this->assertSame(
            '{"message":"Git is not connected.","errors":{"git":["Git is not connected."]},"problems":[{"field":"git","code":"git_invalid","message":"Git is not connected.","hints":[]}]}',
            $response->getContent(),
        );
        $this->assertSame(0, $this->redeploy->requests);
    }

    public function test_any_other_git_failure_keeps_its_status_and_a_sanitized_message(): void
    {
        $this->user('main');
        $this->withRepository();
        $this->respond("'fetch' 'origin'", 1, 'fatal: could not read from https://user:pw@github.com/x.git');

        $response = $this->getJson(self::URL . '/status?fetch=1');

        $response->assertStatus(400);
        $this->assertSame(
            json_encode(['message' => 'fatal: could not read from https://user:pw@github.com/x.git']),
            $response->getContent(),
        );
    }

    public function test_changing_to_a_branch_the_remote_lacks_is_a_422_with_the_closest_one(): void
    {
        $this->user('main');
        $this->withRepository();
        $this->respond("'fetch' 'origin'", 128, "fatal: couldn't find remote ref mian\n");
        $sha = str_repeat('a', 40);
        $this->respond("'ls-remote' '--heads' 'origin'", 0, "{$sha}\trefs/heads/main\n{$sha}\trefs/heads/develop\n");

        $this->putJson(self::URL . '/change-branch', ['branch' => 'mian'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'git')
            ->assertJsonPath('problems.0.code', 'git_branch_not_found')
            ->assertJsonPath('problems.0.message', "The remote has no branch named 'mian'. Did you mean 'main'?");
        $this->assertFalse($this->called("'checkout'"));
        $this->assertSame(0, $this->redeploy->requests);
    }

    public function test_branches_and_commits(): void
    {
        $this->user('main');
        $this->withRepository();
        $this->respond("'for-each-ref'", 0, "refs/heads/main\x1fmain\x1forigin/main\x1f*\n");

        $this->getJson(self::URL . '/branches')
            ->assertStatus(200)
            ->assertExactJson(['data' => [['name' => 'main', 'current' => true, 'tracking' => 'origin/main']]]);

        $this->getJson(self::URL . '/commits?limit=7&branch=dev')
            ->assertStatus(200)
            ->assertExactJson(['data' => []]);
        $this->assertTrue($this->called("'log' '-n' '7' '--format=%H%x1f%h%x1f%s%x1f%an%x1f%aI' 'dev'"));
    }

    /** The branch list reads the remote, so a branch it offers is fetched to list its commits instead of git's error. */
    public function test_commits_fetches_the_branch_first_and_refuses_one_the_remote_does_not_have(): void
    {
        $this->siteGitPublicHtml();
        $this->withRepository();
        $this->respond("'--end-of-options' 'origin/test^{commit}'", 128, "fatal: Needed a single revision\n");
        $this->respond("'--end-of-options' 'test^{commit}'", 128, "fatal: Needed a single revision\n");
        $sha = str_repeat('a', 40);
        $this->respond("'ls-remote' '--heads' 'origin'", 0, "{$sha}\trefs/heads/main\n{$sha}\trefs/heads/develop\n");

        $response = $this->getJson(self::URL . '/commits?path=public_html&branch=test');

        $response->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'git')
            ->assertJsonPath('problems.0.code', 'git_branch_not_found')
            ->assertJsonPath('problems.0.message', "The remote has no branch named 'test'. Its branches: main, develop.");
        $this->assertTrue($this->called("'fetch' 'origin' '+refs/heads/test:refs/remotes/origin/test'"));
        $this->assertFalse($this->called("'log'"));
    }

    /** A branch that cannot be fetched is a typed refusal, never git's own words. */
    public function test_commits_refuses_a_branch_it_could_not_fetch(): void
    {
        $this->siteGitPublicHtml();
        $this->withRepository();
        $this->respond("'--end-of-options' 'origin/test^{commit}'", 128, "fatal: Needed a single revision\n");
        $this->respond("'--end-of-options' 'test^{commit}'", 128, "fatal: Needed a single revision\n");
        $this->respond("'fetch' 'origin' '+refs/heads/test:refs/remotes/origin/test'", 128,
            "fatal: could not read Username for 'https://github.com': terminal prompts disabled\n");

        $response = $this->getJson(self::URL . '/commits?path=public_html&branch=test');

        $response->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'git')
            ->assertJsonPath('problems.0.code', 'git_branch_not_fetched')
            ->assertJsonPath('problems.0.message', "The branch 'test' could not be fetched from the remote, so this "
                ."project's checkout has no commits of it to list. Check that this server can reach the repository "
                .'and that the stored token still reads it, then try again.');
        $this->assertFalse($this->called("'log'"));
    }

    public function test_connect_keeps_the_deploy_origin_in_sync(): void
    {
        $user = $this->user('main');
        $this->withRepository();
        $this->respond("'config' '--get' 'remote.origin.url'", 0, self::REPO . "\n");

        $response = $this->postJson(self::URL . '/connect', ['repo_url' => self::REPO, 'branch' => 'dev', 'token' => 'ghp_x']);

        $response->assertStatus(200);
        $this->assertSame('deploy', $response->json('data.managed_by'));
        $this->assertSame('ghp_x', $user->fresh()->getDetails()['git_token']);
    }

    /** The repository is read before anything is written, so one that does not exist is never stored as connected. */
    public function test_connect_refuses_a_repository_it_cannot_read(): void
    {
        $user = $this->user('main', ['git_repo' => '', 'git_branch' => null]);
        $this->withRepository();
        $missing = 'https://github.com/mdn/t3d-no-such-repo-xyz';
        $this->respond("'ls-remote' '--heads' '" . $missing, 128, "remote: Repository not found.\nfatal: repository '{$missing}/' not found\n");

        $response = $this->postJson(self::URL . '/connect', ['path' => 'public_html', 'repo_url' => $missing, 'branch' => 'main']);

        $response->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'git')
            ->assertJsonPath('problems.0.code', 'git_repository_not_found')
            ->assertJsonPath('problems.0.message', 'The repository could not be read: github.com has no repository at '
                .'this address, or it is private and needs a read token. Nothing was connected.');
        $this->assertFalse($this->called("'remote' 'add'"));
        $this->assertFalse($this->called("'remote' 'set-url'"));
        $this->assertNull($user->fresh()->getSiteGit('public_html'));
    }

    public function test_connect_refuses_a_branch_the_remote_does_not_have(): void
    {
        $user = $this->user('main', ['git_repo' => '', 'git_branch' => null]);
        $this->withRepository();
        $sha = str_repeat('a', 40);
        $this->respond("'ls-remote' '--heads'", 0, "{$sha}\trefs/heads/main\n{$sha}\trefs/heads/develop\n");

        $this->postJson(self::URL . '/connect', ['path' => 'public_html', 'repo_url' => self::REPO, 'branch' => 'mian'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'git')
            ->assertJsonPath('problems.0.code', 'git_branch_not_found')
            ->assertJsonPath('problems.0.message', "The remote has no branch named 'mian'. Did you mean 'main'?");
        $this->assertFalse($this->called("'remote' 'add'"));
        $this->assertFalse($this->called("'remote' 'set-url'"));
        $this->assertNull($user->fresh()->getSiteGit('public_html'));
    }

    /** A repository that has no commits yet has no branch to check, and connecting one is still how it gets its first push. */
    public function test_connect_accepts_a_repository_with_no_branches_yet(): void
    {
        $this->user('main', ['git_repo' => '', 'git_branch' => null]);
        $this->withoutRepository();

        $this->postJson(self::URL . '/connect', ['path' => 'public_html', 'repo_url' => self::REPO, 'branch' => 'main'])
            ->assertStatus(200)
            ->assertJsonPath('data.connected', true);
        $this->assertTrue($this->called("'ls-remote' '--heads' '" . self::REPO));
    }

    /** A private repository is read with the token the project stored, which the request does not have to send again. */
    public function test_connect_reads_a_private_remote_with_the_stored_token(): void
    {
        $user = $this->user('main', ['git_repo' => '', 'git_branch' => null]);
        $user->putSiteGit('public_html', ['repo_url' => self::REPO, 'branch' => 'main', 'token' => 'ghp_stored']);
        $this->withRepository();
        $this->respond("'config' '--get' 'remote.origin.url'", 0, self::REPO . "\n");

        $this->postJson(self::URL . '/connect', ['path' => 'public_html', 'repo_url' => self::REPO, 'branch' => 'main'])
            ->assertStatus(200);

        $probe = array_values(array_filter($this->calls(), fn (string $call): bool => str_contains($call, "'ls-remote'")));
        $this->assertCount(1, $probe);
        $this->assertStringNotContainsString('GIT_ASKPASS=/bin/false', $probe[0]);
    }

    public function test_connect_refuses_a_repository_the_egress_guard_will_not_fetch(): void
    {
        $this->user('main');
        $this->withoutRepository();

        foreach (['http://' . TestNetwork::LOOPBACK . '/x.git', 'http://' . TestNetwork::METADATA . '/x.git', 'http://0x7f000001:9/x.git', 'http://[::1]/x.git'] as $url) {
            $this->postJson(self::URL . '/connect', ['repo_url' => $url, 'branch' => 'main'])
                ->assertStatus(422)
                ->assertJsonPath('problems.0.field', 'repo_url')
                ->assertJsonPath('problems.0.code', 'host_refused');
        }
        foreach (['file:///etc/passwd', 'ext::sh -c id'] as $url) {
            $this->postJson(self::URL . '/connect', ['repo_url' => $url, 'branch' => 'main'])
                ->assertStatus(422);
        }
        $this->assertSame([], $this->calls());
    }

    /** `repair: "false"` is not a repair: the repository and branch are still required, and git never runs. */
    public function test_connect_with_repair_false_still_needs_a_repository_and_a_branch(): void
    {
        $this->user('main');
        $this->withoutRepository();

        $this->postJson(self::URL . '/connect', ['repair' => 'false'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['repo_url', 'branch']);
        $this->assertSame([], $this->calls());
    }

    public function test_a_change_while_the_project_is_being_created_is_a_409(): void
    {
        $this->siteGitUser();
        $task = Task::start(DeployProject::class, 'default', 'alice');
        $this->withoutRepository();
        $body = ['path' => 'public_html', 'repo_url' => self::REPO, 'branch' => 'main'];

        $changes = [
            '/connect' => 'postJson', '/disconnect' => 'postJson', '/change-branch' => 'putJson',
            '/update-credentials' => 'putJson', '/pull' => 'postJson', '/push' => 'postJson', '/revert' => 'postJson',
        ];
        foreach ($changes as $endpoint => $method) {
            $response = $this->{$method}(self::URL . $endpoint, $body);
            $response->assertStatus(409);
            $this->assertSame([
                'message' => "Project 'alice' is still being created; try again when its deploy has finished.",
                'task_id' => $task->id,
            ], $response->json(), $endpoint);
        }
        $this->assertSame([], $this->calls(), 'nothing may reach the account while it is being created');
        $this->getJson(self::URL . '/status?path=public_html')->assertStatus(200);

        $task->markRunning();
        $this->postJson(self::URL . '/connect', $body)->assertStatus(409);

        $task->markCompleted();
        $this->postJson(self::URL . '/connect', $body)->assertStatus(200);
        $this->assertTrue($this->called("'ls-remote' '--heads'"));
    }

    /** A queued rebuild or archive deploy builds from the checkout: it is not changed under it. */
    public function test_a_change_while_a_rebuild_is_queued_is_a_409(): void
    {
        $this->siteGitUser();
        $task = Task::start(RebuildProject::class, 'default', 'alice');
        $this->withoutRepository();
        $body = ['path' => 'public_html', 'repo_url' => self::REPO, 'branch' => 'main'];

        $response = $this->postJson(self::URL . '/pull', $body);

        // The shape a Deploy-managed checkout's 409 has: the task to follow.
        $response->assertStatus(409);
        $this->assertSame([
            'message' => "A deploy of project 'alice' is queued or running (task {$task->id}); try again when it has finished.",
            'task_id' => $task->id,
        ], $response->json());
        $this->assertSame([], $this->calls());

        $task->markFailed('earlier');
        $this->postJson(self::URL . '/connect', $body)->assertStatus(200);
    }

    public function test_disconnect_forgets_the_checkout_and_its_hook(): void
    {
        $user = $this->siteGitPublicHtml();
        $this->hook($user, 'secret-of-the-site-checkout', 'public_html');
        $this->withoutRepository();

        $response = $this->postJson(self::URL . '/disconnect', ['path' => 'public_html']);

        $response->assertStatus(200);
        $this->assertSame(json_encode(['data' => $this->envelope('public_html', 'site_git', false)]), $response->getContent());
        $this->assertSame(0, DeployHook::count());
    }

    public function test_update_credentials_without_a_token_changes_nothing(): void
    {
        $user = $this->siteGitPublicHtml('old');
        $this->withoutRepository();

        $this->putJson(self::URL . '/update-credentials', ['path' => 'public_html'])->assertStatus(200);
        $this->assertSame('old', $user->fresh()->getSiteGit('public_html')['token']);

        $this->putJson(self::URL . '/update-credentials', ['path' => 'public_html', 'token' => 'new'])->assertStatus(200);
        $this->assertSame('new', $user->fresh()->getSiteGit('public_html')['token']);
    }

    /** A token stored on a stopped container is a 200 saying so, never a 503 that reads as nothing changed. */
    public function test_update_credentials_on_a_stopped_container_stores_the_token_and_answers_200_with_a_warning(): void
    {
        $user = $this->user('main');
        $this->respond("'rev-parse' '--is-inside-work-tree'", 1, "service \"dind\" is not running\n");

        $response = $this->putJson(self::URL . '/update-credentials', ['token' => 'new-token']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.connected', true);
        $response->assertJsonPath('data.managed_by', 'deploy');
        $this->assertSame(
            'The credentials were stored, but the checkout could not be read: '
            . "Git could not run: the project's container is not running (service \"dind\" is not running)",
            $response->json('data.warning'),
        );
        $this->assertSame('new-token', $user->fresh()->getGitToken());
    }

    /** The remote's answer to `ls-remote --heads origin refs/heads/<branch>` when it has the branch. */
    private function remoteHas(string $branch): void
    {
        $this->respond("'ls-remote' '--heads' 'origin' 'refs/heads/{$branch}'", 0, str_repeat('a', 40) . "\trefs/heads/{$branch}\n");
    }

    /** Nothing in the checkout was changed, nothing rebuilt: that is the job's. */
    private function assertCheckoutUntouched(): void
    {
        foreach (["'reset'", "'checkout'", "'clean'", "'fetch'", "'merge'"] as $verb) {
            $this->assertFalse($this->called($verb), "{$verb} ran in the request");
        }
        $this->assertSame(0, $this->redeploy->requests);
    }

    public function test_a_pull_on_the_deploy_checkout_answers_202_and_queues_it_with_the_rebuild(): void
    {
        Queue::fake();
        $this->user('main');
        $this->withRepository();
        $this->remoteHas('main');

        $response = $this->postJson(self::URL . '/pull', ['strategy' => 'force']);

        $response->assertStatus(202);
        $response->assertJsonPath('data.job_type', RebuildProject::class);
        $response->assertJsonPath('data.status', Task::STATUS_QUEUED);
        $response->assertJsonPath('data.details.action', 'git_pull');
        $response->assertJsonPath('data.details.strategy', 'force');
        $response->assertJsonPath('data.details.path', 'project');
        $taskId = $response->json('data.id');
        Queue::assertPushed(RebuildProject::class, static fn (RebuildProject $job): bool => $job->taskId === $taskId
            && $job->username === 'alice'
            && $job->action === ProjectRebuild::GIT_PULL
            && $job->git === ['path' => 'project', 'strategy' => 'force']);
        $this->assertTrue($this->called("'ls-remote' '--heads' 'origin' 'refs/heads/main'"), 'the remote is read before the 202');
        $this->assertCheckoutUntouched();
    }

    public function test_a_branch_change_on_the_deploy_checkout_answers_202_and_queues_it(): void
    {
        Queue::fake();
        $this->user('main');
        $this->withRepository();
        $this->remoteHas('dev');

        $response = $this->putJson(self::URL . '/change-branch', ['branch' => 'dev']);

        $response->assertStatus(202);
        $response->assertJsonPath('data.details.action', 'change_branch');
        $response->assertJsonPath('data.details.branch', 'dev');
        Queue::assertPushed(RebuildProject::class, static fn (RebuildProject $job): bool => $job->action === ProjectRebuild::CHANGE_BRANCH
            && $job->git === ['path' => 'project', 'branch' => 'dev']);
        $this->assertCheckoutUntouched();
        $this->assertSame('main', User::where('username', 'alice')->first()->getGitBranch(), 'the branch is recorded by the job');
    }

    public function test_a_revert_on_the_deploy_checkout_answers_202_and_queues_it(): void
    {
        Queue::fake();
        $this->user('main');
        $this->withRepository();

        $response = $this->postJson(self::URL . '/revert', ['ref' => 'abc123']);

        $response->assertStatus(202);
        $response->assertJsonPath('data.details.action', 'revert');
        $response->assertJsonPath('data.details.ref', 'abc123');
        Queue::assertPushed(RebuildProject::class, static fn (RebuildProject $job): bool => $job->action === ProjectRebuild::REVERT
            && $job->git === ['path' => 'project', 'ref' => 'abc123']);
        $this->assertTrue($this->called("'rev-parse' '--verify' '--quiet' '--end-of-options' 'abc123^{commit}'"));
        $this->assertCheckoutUntouched();
    }

    public function test_a_revert_to_a_ref_the_checkout_lacks_is_a_422_and_queues_nothing(): void
    {
        Queue::fake();
        $this->user('main');
        $this->withRepository();
        $this->respond("'nope^{commit}'", 1);

        $this->postJson(self::URL . '/revert', ['ref' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'git')
            ->assertJsonPath('problems.0.code', 'git_ref_not_found');
        Queue::assertNothingPushed();
        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_remote_that_cannot_be_read_is_refused_before_anything_is_queued(): void
    {
        Queue::fake();
        $this->user('main');
        $this->withRepository();
        $this->respond("'ls-remote'", 128, "fatal: unable to access 'https://github.com/octocat/Hello-World.git/': Could not resolve host\n");

        $response = $this->postJson(self::URL . '/pull', []);

        $response->assertStatus(400);
        $this->assertStringContainsString('Could not resolve host', (string) $response->json('message'));
        Queue::assertNothingPushed();
        $this->assertCheckoutUntouched();
    }

    public function test_a_second_change_while_the_first_is_queued_is_a_409_naming_its_task(): void
    {
        Queue::fake();
        $this->user('main');
        $this->withRepository();
        $this->remoteHas('main');
        $this->remoteHas('dev');
        $taskId = $this->postJson(self::URL . '/pull', [])->assertStatus(202)->json('data.id');
        file_put_contents($this->shim . '/calls.log', '');

        $calls = [
            ['postJson', '/pull', []],
            ['putJson', '/change-branch', ['branch' => 'dev']],
            ['postJson', '/revert', []],
        ];
        foreach ($calls as [$method, $endpoint, $body]) {
            $response = $this->{$method}(self::URL . $endpoint, $body);
            $response->assertStatus(409);
            $response->assertJsonPath('task_id', $taskId);
            $response->assertJsonPath('code', DeployBusyException::TASK);
            $response->assertJsonPath('problems.0.hints.0.path', '/tasks/{id}');
            $response->assertJsonPath('problems.0.hints.0.path_params', ['id' => $taskId]);
        }
        Queue::assertPushedTimes(RebuildProject::class, 1);
        $this->assertSame([], $this->calls(), 'a refused call does not read the checkout a deploy may be moving aside');
    }

    /** Without the project's queue lock the check could race another request: 503, as for a rebuild. */
    public function test_a_lock_that_cannot_be_taken_is_a_503_and_queues_nothing(): void
    {
        Queue::fake();
        $this->user('main');
        $this->withRepository();
        $this->remoteHas('main');
        $this->app->instance(ProjectRebuild::class, new class extends ProjectRebuild {
            protected function lockQueue(DeployLogPaths $paths)
            {
                return null;
            }
        });

        $response = $this->postJson(self::URL . '/pull', []);

        $response->assertStatus(503);
        $this->assertStringContainsString('Could not lock the project', (string) $response->json('message'));
        Queue::assertNothingPushed();
        $this->assertSame(0, Task::query()->count());
    }

    /** A deploy started without a task -- the CLI, a push -- holds only the lock. */
    public function test_a_change_while_a_deploy_holds_the_lock_is_a_409_without_a_task(): void
    {
        Queue::fake();
        $this->user('main');
        $this->withRepository();
        $running = DeployLogger::start('alice');

        try {
            $response = $this->postJson(self::URL . '/pull', []);
        } finally {
            $running->finish(DeployLogger::STATUS_SUCCESS);
        }

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'A deploy of this project is already running; its deploy log follows it.');
        $response->assertJsonPath('task_id', null);
        $response->assertJsonPath('code', DeployBusyException::CODE);
        $response->assertJsonPath('problems.0.hints.0.path_params', ['username' => 'alice']);
        Queue::assertNothingPushed();
        $this->assertCheckoutUntouched();
    }

    /**
     * Every git command threw while the project's last deploy read `cancelled`,
     * even after it had finished: the pull answered 422 "No git repository at
     * path" and the status said there was no repository, until another deploy.
     */
    public function test_after_a_cancelled_deploy_a_pull_is_queued_and_the_status_reads_the_checkout(): void
    {
        Queue::fake();
        $this->user('main');
        $this->withRepository();
        $this->remoteHas('main');
        $cancelled = DeployLogger::start('alice');
        DeployLogger::requestCancel('alice');
        $cancelled->finish(DeployLogger::STATUS_CANCELLED, 'Deployment cancelled by user');

        $this->postJson(self::URL . '/pull', [])->assertStatus(202)->assertJsonPath('data.details.action', 'git_pull');
        $this->getJson(self::URL . '/status')->assertStatus(200)->assertJsonPath('data.repository_exists', true);
    }

    /** Cancelled, and the process that ran it died before finishing: nothing holds the lock, so nothing is stopped. */
    public function test_after_a_cancelled_deploy_whose_process_is_gone_a_pull_is_queued(): void
    {
        Queue::fake();
        $this->user('main');
        $this->withRepository();
        $this->remoteHas('main');
        $dead = DeployLogger::start('alice');
        DeployLogger::requestCancel('alice');
        unset($dead);
        gc_collect_cycles();

        $this->postJson(self::URL . '/pull', [])->assertStatus(202);
    }

    /** A site_git checkout has nothing to rebuild: it is changed in the request, as before. */
    public function test_a_pull_on_a_site_git_checkout_still_answers_200(): void
    {
        Queue::fake();
        $this->siteGitPublicHtml();
        $this->withRepository();

        $response = $this->postJson(self::URL . '/pull', ['path' => 'public_html', 'strategy' => 'force']);

        $response->assertStatus(200);
        $this->assertSame('site_git', $response->json('data.managed_by'));
        $this->assertTrue($this->called("'reset' '--hard' 'origin/main'"));
        Queue::assertNothingPushed();
        $this->assertSame([], $this->redeploy->calls, 'nothing to rebuild');
    }

    public function test_push_does_not_rebuild(): void
    {
        $this->siteGitPublicHtml();
        $this->withRepository();

        $response = $this->postJson(self::URL . '/push', ['path' => 'public_html']);

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.nothing_to_push'));
        $this->assertSame(0, $this->redeploy->requests);
    }

    public function test_a_push_the_repository_does_not_authorize_is_a_422_problem(): void
    {
        $this->siteGitPublicHtml();
        $this->withRepository();
        $this->respond("'status' '--porcelain'", 0, " M index.php\n");
        $this->respond("'push' 'origin'", 128, "error: unable to read askpass response from '/bin/false'\n"
            . "fatal: could not read Username for 'https://github.com': No such device or address\n");

        $this->postJson(self::URL . '/push', ['path' => 'public_html'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'git')
            ->assertJsonPath('problems.0.code', 'git_push_unauthorized')
            ->assertJsonPath('problems.0.message', fn (string $message): bool => str_starts_with($message, 'The repository did not let this project push to it'));
    }
}
