<?php

namespace Tests\Unit\Git;

use App\Jobs\DeployProject;
use App\Jobs\RebuildProject;
use App\Models\DeployHook;
use App\Models\Task;
use App\Models\User;
use App\System\Project\Git\CheckoutRedeploy;
use Tests\Unit\DeployHook\DeployHookTestCase;
use Tests\Unit\DeployHook\SpyCheckoutRedeploy;

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
        $this->redeploy = new SpyCheckoutRedeploy();
        $this->app->instance(CheckoutRedeploy::class, $this->redeploy);
    }

    protected function tearDown(): void
    {
        $this->restoreHost();

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
            '{"message":"Invalid git ref name.","errors":{"branch":["Invalid git ref name."]},"problems":[{"field":"branch","code":"branch_invalid","message":"Invalid git ref name."}]}',
            $response->getContent(),
        );
    }

    public function test_a_git_refusal_is_a_422_under_git(): void
    {
        $this->user('main', ['git_repo' => '']);

        $response = $this->postJson(self::URL . '/pull', []);

        $response->assertStatus(422);
        $this->assertSame(
            '{"message":"Git is not connected.","errors":{"git":["Git is not connected."]},"problems":[{"field":"git","code":"git_invalid","message":"Git is not connected."}]}',
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
            $this->assertSame(
                "Project 'alice' is still being created; try again when its deploy has finished.",
                $response->json('message'),
                $endpoint,
            );
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

        $response->assertStatus(409);
        $this->assertSame(
            "A deploy of project 'alice' is queued or running (task {$task->id}); try again when it has finished.",
            $response->json('message'),
        );
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

    public function test_pull_revert_and_change_branch_rebuild_the_deploy_checkout(): void
    {
        $this->user('main');
        $this->withRepository();

        $this->postJson(self::URL . '/pull', ['strategy' => 'force'])->assertStatus(200);
        $this->postJson(self::URL . '/revert', ['ref' => 'abc123'])->assertStatus(200);
        $this->putJson(self::URL . '/change-branch', ['branch' => 'dev'])->assertStatus(200);

        $this->assertCount(3, $this->redeploy->calls);
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

    public function test_a_failed_rebuild_is_rendered_by_the_exception_handler(): void
    {
        $this->app->instance(CheckoutRedeploy::class, new SpyCheckoutRedeploy(new \RuntimeException('kaboom')));
        $this->user('main');
        $this->withRepository();

        $response = $this->postJson(self::URL . '/revert', ['ref' => 'abc123']);

        $response->assertStatus(500);
        $this->assertSame('Server Error', $response->json('message'));
    }
}
