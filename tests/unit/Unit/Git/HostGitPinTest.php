<?php

namespace Tests\Unit\Git;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Http\OutboundUrl;
use App\Models\User;
use App\System\Project\Git\CheckoutRedeploy;
use Tests\Unit\DeployHook\DeployHookTestCase;
use Tests\Unit\DeployHook\SpyCheckoutRedeploy;

/**
 * A classic project's git runs on the host, whose network reaches internal
 * addresses, so every remote command is pinned to the address the egress guard
 * accepts at that moment; a DinD project's git runs behind its own network.
 */
class HostGitPinTest extends DeployHookTestCase
{
    use FakesGitHost;

    private const URL = '/api/projects/alice/git';
    private const REPO = 'https://github.com/octocat/Hello-World.git';
    private const PIN = 'http.curloptResolve=github.com:443:140.82.121.4';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        config(['app.debug' => false]);
        $this->fakeGitHost();
        $this->app->instance(CheckoutRedeploy::class, new SpyCheckoutRedeploy());
    }

    protected function tearDown(): void
    {
        $this->restoreHost();
        OutboundUrl::setResolverForTests(null);
        OutboundUrl::setPolicyForTests(null, null);
        DeployLogger::deleteUserLogs('alice');

        parent::tearDown();
    }

    private function resolvesTo(string $address): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => $host === 'github.com' ? [$address] : []);
    }

    private function hostGitIs(string $version): void
    {
        $this->respond('git --version', 0, "git version {$version}\n");
    }

    private function classicSiteGit(): User
    {
        $user = $this->user('main', ['template' => 'wordpress', 'git_repo' => '', 'git_branch' => null]);
        $user->putSiteGit('public_html', ['repo_url' => self::REPO, 'branch' => 'main', 'token' => null]);

        return $user;
    }

    /** A host command reaches the shim as plain argv, a DinD one quoted for the shell. */
    private function withHostRepository(string $origin = self::REPO): void
    {
        $this->respond('rev-parse --is-inside-work-tree', 0, "true\n");
        $this->respond('remote get-url', 0, $origin . "\n");
    }

    /** @return list<string> */
    private function remoteCalls(): array
    {
        return array_values(array_filter(
            $this->calls(),
            static fn (string $call): bool => preg_match("/(^|[\\s'])(fetch|ls-remote)(\$|[\\s'])/", $call) === 1,
        ));
    }

    public function test_a_classic_pull_is_pinned_to_the_checked_address(): void
    {
        $this->resolvesTo('140.82.121.4');
        $this->classicSiteGit();
        $this->withHostRepository();

        $this->postJson(self::URL . '/pull', ['path' => 'public_html', 'strategy' => 'force'])->assertStatus(200);

        $this->assertNotSame([], $this->remoteCalls());
        foreach ($this->remoteCalls() as $call) {
            $this->assertStringContainsString(self::PIN, $call);
            $this->assertStringContainsString('http.followRedirects=false', $call);
        }
    }

    public function test_a_classic_branch_listing_is_pinned_to_the_checked_address(): void
    {
        $this->resolvesTo('140.82.121.4');
        $this->classicSiteGit();
        $this->withHostRepository();

        $this->getJson(self::URL . '/branches?path=public_html')->assertStatus(200);

        $this->assertNotSame([], $this->remoteCalls());
        foreach ($this->remoteCalls() as $call) {
            $this->assertStringContainsString(self::PIN, $call);
        }
    }

    public function test_a_classic_connect_fetches_from_the_checked_address(): void
    {
        $this->resolvesTo('140.82.121.4');
        $this->user('main', ['template' => 'wordpress', 'git_repo' => '', 'git_branch' => null]);
        $this->respond('rev-parse --is-inside-work-tree', 128, "fatal: not a git repository\n");
        $this->respond('remote get-url', 0, self::REPO . "\n");

        $this->postJson(self::URL . '/connect', ['path' => 'public_html', 'repo_url' => self::REPO, 'branch' => 'main'])
            ->assertStatus(200);

        $this->assertNotSame([], $this->remoteCalls());
        foreach ($this->remoteCalls() as $call) {
            $this->assertStringContainsString(self::PIN, $call);
        }
    }

    public function test_the_address_pinned_is_the_one_git_resolves_origin_to(): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => $host === 'rebind.example' ? ['127.0.0.1'] : ['140.82.121.4']);
        $this->classicSiteGit();
        $this->withHostRepository('https://rebind.example/x.git');

        $this->postJson(self::URL . '/pull', ['path' => 'public_html', 'strategy' => 'force'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.code', 'host_refused');

        $this->assertSame([], $this->remoteCalls());
    }

    public function test_a_classic_pull_whose_host_now_resolves_to_loopback_is_refused_before_git_connects(): void
    {
        $this->resolvesTo('127.0.0.1');
        $this->classicSiteGit();
        $this->withHostRepository();

        $this->postJson(self::URL . '/pull', ['path' => 'public_html', 'strategy' => 'force'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'git')
            ->assertJsonPath('problems.0.code', 'host_refused');

        $this->assertSame([], $this->remoteCalls());
    }

    public function test_a_dind_pull_runs_behind_the_account_network_unpinned(): void
    {
        $this->resolvesTo('140.82.121.4');
        $user = $this->siteGitUser();
        $user->putSiteGit('public_html', ['repo_url' => self::REPO, 'branch' => 'main', 'token' => null]);
        $this->withRepository();

        $this->postJson(self::URL . '/pull', ['path' => 'public_html', 'strategy' => 'force'])->assertStatus(200);

        $this->assertNotSame([], $this->remoteCalls());
        foreach ($this->remoteCalls() as $call) {
            $this->assertStringNotContainsString('curloptResolve', $call);
        }
    }

    public function test_a_host_git_older_than_2_37_refuses_a_pull_it_cannot_pin(): void
    {
        $this->resolvesTo('140.82.121.4');
        $this->hostGitIs('2.34.1');
        $this->classicSiteGit();
        $this->withHostRepository();

        $response = $this->postJson(self::URL . '/pull', ['path' => 'public_html', 'strategy' => 'force'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'git')
            ->assertJsonPath('problems.0.code', 'host_refused');

        $this->assertStringContainsString('git 2.37 or newer is required', (string) $response->json('problems.0.message'));
        $this->assertSame([], $this->remoteCalls());
    }

    public function test_a_host_git_older_than_2_37_connects_without_fetching_and_says_why(): void
    {
        $this->resolvesTo('140.82.121.4');
        $this->hostGitIs('2.34.1');
        $this->user('main', ['template' => 'wordpress', 'git_repo' => '', 'git_branch' => null]);
        $this->respond('rev-parse --is-inside-work-tree', 128, "fatal: not a git repository\n");
        $this->respond('remote get-url', 0, self::REPO . "\n");

        $response = $this->postJson(self::URL . '/connect', ['path' => 'public_html', 'repo_url' => self::REPO, 'branch' => 'main'])
            ->assertStatus(200);

        $this->assertStringContainsString('git 2.37 or newer is required', (string) $response->json('data.sync_error'));
        $this->assertSame([], $this->remoteCalls());
    }

    public function test_a_host_git_older_than_2_37_still_fetches_a_name_the_operator_allows(): void
    {
        $this->resolvesTo('140.82.121.4');
        OutboundUrl::setPolicyForTests(null, ['github.com']);
        $this->hostGitIs('2.34.1');
        $this->classicSiteGit();
        $this->withHostRepository();

        $this->postJson(self::URL . '/pull', ['path' => 'public_html', 'strategy' => 'force'])->assertStatus(200);

        $this->assertNotSame([], $this->remoteCalls());
    }

    public function test_a_host_git_of_2_37_or_newer_is_pinned(): void
    {
        $this->resolvesTo('140.82.121.4');
        $this->hostGitIs('2.43.0');
        $this->classicSiteGit();
        $this->withHostRepository();

        $this->postJson(self::URL . '/pull', ['path' => 'public_html', 'strategy' => 'force'])->assertStatus(200);

        $this->assertNotSame([], $this->remoteCalls());
        foreach ($this->remoteCalls() as $call) {
            $this->assertStringContainsString(self::PIN, $call);
        }
    }

    public function test_the_host_git_version_is_read_once_across_requests(): void
    {
        $this->resolvesTo('140.82.121.4');
        $this->hostGitIs('2.43.0');
        $this->classicSiteGit();
        $this->withHostRepository();

        $this->postJson(self::URL . '/pull', ['path' => 'public_html', 'strategy' => 'force'])->assertStatus(200);
        $this->getJson(self::URL . '/branches?path=public_html')->assertStatus(200);

        $probes = array_filter($this->calls(), static fn (string $call): bool => str_contains($call, 'git --version'));
        $this->assertCount(1, $probes);
    }

    public function test_an_old_host_git_does_not_refuse_a_dind_pull(): void
    {
        $this->resolvesTo('140.82.121.4');
        $this->hostGitIs('2.34.1');
        $user = $this->siteGitUser();
        $user->putSiteGit('public_html', ['repo_url' => self::REPO, 'branch' => 'main', 'token' => null]);
        $this->withRepository();

        $this->postJson(self::URL . '/pull', ['path' => 'public_html', 'strategy' => 'force'])->assertStatus(200);

        $this->assertNotSame([], $this->remoteCalls());
    }
}
