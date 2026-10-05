<?php

namespace Tests\Unit\Git;

use App\Http\Resources\UserResource;
use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Deploy\Source\GitUrl;
use App\Lib\Git\DeployKey;
use App\Models\User;
use App\System\Project\Git\CheckoutRedeploy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Unit\DeployHook\DeployHookTestCase;
use Tests\Unit\DeployHook\SpyCheckoutRedeploy;

/**
 * SSH deploy keys: a project's own ed25519 key, the host keys it trusts, and
 * SSH remotes that clone with it. Before, an SSH remote could only be refused,
 * because the engine held no key material at all.
 */
class DeployKeyTest extends DeployHookTestCase
{
    use FakesGitHost;

    private const URL = '/api/projects/alice/git';

    /** github.com's published ed25519 key and the fingerprint GitHub publishes for it. */
    private const GITHUB_ED25519 = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';
    private const GITHUB_ED25519_FP = 'SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU';

    protected function setUp(): void
    {
        parent::setUp();

        if (trim((string) shell_exec('command -v ssh-keygen')) === '') {
            $this->markTestSkipped('ssh-keygen is not installed here.');
        }
        $this->withoutMiddleware();
        config(['app.debug' => false]);
        $this->fakeGitHost();
        $this->app->instance(CheckoutRedeploy::class, new SpyCheckoutRedeploy());
        // ssh-keyscan answers from the shim: `example.test`'s key is GitHub's, so its fingerprint is known.
        file_put_contents($this->shim . '/ssh-keyscan', "#!/bin/sh\nprintf '%s\\n' \"ssh-keyscan \$*\" >> \"\$(dirname \"\$0\")/calls.log\"\n"
            . "case \"\$*\" in *nothing.example.test*) exit 1;; esac\n"
            . "echo '# git.example.test:2222 SSH-2.0-OpenSSH'\necho 'git.example.test " . self::GITHUB_ED25519 . "'\n");
        chmod($this->shim . '/ssh-keyscan', 0755);
    }

    protected function tearDown(): void
    {
        $this->restoreHost();

        parent::tearDown();
    }

    public function test_a_key_is_created_once_and_only_its_public_half_leaves(): void
    {
        $user = $this->project();

        $first = $this->postJson(self::URL . '/deploy-key')->assertStatus(201);
        $public = (string) $first->json('data.public_key');
        $this->assertStringStartsWith('ssh-ed25519 AAAA', $public);
        $this->assertStringEndsWith(' panelalpha-alice', $public);
        $this->assertSame($this->sshKeygenFingerprint($public), $first->json('data.fingerprint'));
        $this->assertSame(['github.com', 'gitlab.com', 'bitbucket.org'], array_column($first->json('data.hosts'), 'host'));
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $first->getContent());

        // Asking again shows the same key; it never rotates one a forge may already trust.
        $again = $this->postJson(self::URL . '/deploy-key')->assertStatus(200);
        $this->assertSame($public, $again->json('data.public_key'));
        $this->assertFalse($again->json('data.created'));

        // At rest the private half is encrypted; in the project resource it is absent.
        $raw = (string) DB::table('users')->where('username', 'alice')->value('details');
        $this->assertStringNotContainsString('PRIVATE KEY', $raw);
        $this->assertStringNotContainsString(substr($public, 12, 40), $raw);
        $this->assertStringContainsString('OPENSSH PRIVATE KEY', DeployKey::stored($user->fresh())['private_key']);
        $publicDetails = new \ReflectionMethod(UserResource::class, 'publicDetails');
        $this->assertArrayNotHasKey('git_deploy_key', $publicDetails->invoke(new UserResource($user->fresh()), $user->fresh()));
    }

    public function test_another_host_is_read_once_and_pinned(): void
    {
        $user = $this->project();

        $response = $this->postJson(self::URL . '/deploy-key', ['host' => 'Git.Example.Test:2222'])->assertStatus(201);

        $this->assertSame(
            ['host' => '[git.example.test]:2222', 'pinned' => 'scanned', 'fingerprints' => [self::GITHUB_ED25519_FP]],
            $response->json('data.hosts.3'),
        );
        $this->assertTrue($this->called('ssh-keyscan -T 10 -p 2222 -t ed25519,ecdsa,rsa git.example.test'));
        $this->assertTrue(DeployKey::pins($user->fresh(), '[git.example.test]:2222'));
        $this->assertStringContainsString(
            '[git.example.test]:2222 ' . self::GITHUB_ED25519,
            DeployKey::knownHosts($user->fresh()),
        );

        $scans = count(array_filter($this->calls(), fn (string $c) => str_starts_with($c, 'ssh-keyscan')));
        $this->postJson(self::URL . '/deploy-key', ['host' => 'git.example.test:2222'])->assertStatus(200);
        $this->assertCount($scans, array_filter($this->calls(), fn (string $c) => str_starts_with($c, 'ssh-keyscan')));
    }

    public function test_a_host_that_is_not_one_or_does_not_answer_is_refused(): void
    {
        $this->project();

        $this->postJson(self::URL . '/deploy-key', ['host' => 'not a host'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.code', 'host_invalid');
        $this->postJson(self::URL . '/deploy-key', ['host' => 'nothing.example.test'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.code', 'host_unreachable');
    }

    public function test_delete_removes_the_key(): void
    {
        $user = $this->project();
        $this->postJson(self::URL . '/deploy-key')->assertStatus(201);

        $this->deleteJson(self::URL . '/deploy-key')->assertStatus(204);
        $this->assertNull(DeployKey::stored($user->fresh()));
        $this->deleteJson(self::URL . '/deploy-key')->assertStatus(404);
    }

    public function test_an_ssh_remote_without_a_deploy_key_is_refused_with_the_way_to_get_one(): void
    {
        $this->project();

        $this->postJson(self::URL . '/connect', ['repo_url' => 'git@github.com:acme/app.git', 'branch' => 'main'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.code', 'repo_url_ssh_needs_deploy_key')
            ->assertJsonPath('problems.0.suggestion', 'https://github.com/acme/app.git');
        $this->assertFalse($this->called('ls-remote'));
    }

    public function test_an_ssh_remote_off_a_forge_gets_no_guessed_https_url(): void
    {
        $this->project();

        $response = $this->postJson(self::URL . '/connect', [
            'repo_url' => 'ssh://git@git.example.test:2222/acme/app.git', 'branch' => 'main',
        ])->assertStatus(422)->assertJsonPath('problems.0.code', 'repo_url_ssh_needs_deploy_key');

        $this->assertArrayNotHasKey('suggestion', $response->json('problems.0'));
        $this->assertStringNotContainsString('https://', (string) $response->json('problems.0.message'));
    }

    public function test_a_pull_after_the_key_was_deleted_says_there_is_no_key(): void
    {
        $this->user('main', ['git_repo' => 'git@github.com:acme/app.git']);
        $this->postJson(self::URL . '/deploy-key')->assertStatus(201);
        $this->deleteJson(self::URL . '/deploy-key')->assertStatus(204);
        $this->withRepository();
        // With no key nothing is pinned, so ssh stops at the host key.
        $this->respond("'fetch'", 128, "Host key verification failed.\nfatal: Could not read from remote repository.\n");

        $response = $this->postJson(self::URL . '/pull', ['strategy' => 'force'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'git')
            ->assertJsonPath('problems.0.code', 'git_ssh_needs_deploy_key');

        $message = (string) $response->json('problems.0.message');
        $this->assertStringContainsString('This project has no deploy key', $message);
        $this->assertStringContainsString('POST /projects/{name}/git/deploy-key', $message);
        $this->assertStringNotContainsString('does not match the one pinned', $message);
        $this->assertFalse($this->called('GIT_SSH_COMMAND'));
    }

    public function test_a_key_the_repository_refuses_is_a_422_like_the_other_refusals(): void
    {
        $this->project();
        $this->postJson(self::URL . '/deploy-key')->assertStatus(201);
        $this->withoutRepository();
        $this->respond("'ls-remote'", 128, "git@github.com: Permission denied (publickey).\nfatal: Could not read from remote repository.\n");

        $response = $this->postJson(self::URL . '/connect', ['repo_url' => 'git@github.com:acme/app.git', 'branch' => 'main'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'git')
            ->assertJsonPath('problems.0.code', 'git_ssh_key_refused');

        $this->assertStringContainsString('does not accept this project\'s deploy key', (string) $response->json('problems.0.message'));
    }

    public function test_an_ssh_remote_on_a_host_nobody_pinned_is_refused(): void
    {
        $this->project();
        $this->postJson(self::URL . '/deploy-key')->assertStatus(201);

        $this->postJson(self::URL . '/connect', ['repo_url' => 'git@git.example.test:acme/app.git', 'branch' => 'main'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.code', 'repo_url_ssh_host_not_pinned');
    }

    public function test_an_ssh_remote_clones_with_the_key_and_the_pinned_hosts_only(): void
    {
        $this->project();
        $this->postJson(self::URL . '/deploy-key')->assertStatus(201);
        $this->withoutRepository();

        $this->postJson(self::URL . '/connect', ['repo_url' => 'git@github.com:acme/app.git', 'branch' => 'main'])
            ->assertStatus(200);

        $lsRemote = array_values(array_filter($this->calls(), fn (string $c) => str_contains($c, "'ls-remote'")));
        $this->assertCount(1, $lsRemote);
        $this->assertMatchesRegularExpression(
            '#GIT_SSH_COMMAND=ssh -i /\S+/\.panelalpha-git-ssh-key-[0-9a-f]{16} -o IdentitiesOnly=yes -o IdentityAgent=none '
            . '-o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/\S+/\.panelalpha-git-ssh-known-hosts-[0-9a-f]{16} '
            . '-o GlobalKnownHostsFile=/dev/null#',
            $lsRemote[0],
        );
        // Both files are put in place for the command and removed after it.
        preg_match_all('#\.panelalpha-git-ssh-(?:key|known-hosts)-[0-9a-f]{16}#', $lsRemote[0], $m);
        foreach ($m[0] as $file) {
            $this->assertTrue($this->called("install -m 0600 "), $file);
            $this->assertTrue($this->called('rm -f ') && $this->calledWith('rm -f', $file), $file);
        }
        // A command that does not reach a remote gets no key.
        foreach ($this->calls() as $call) {
            if (str_contains($call, "'rev-parse'")) {
                $this->assertStringNotContainsString('GIT_SSH_COMMAND', $call);
            }
        }
    }

    public function test_a_changed_host_key_fails_with_what_it_means(): void
    {
        $this->project();
        $this->postJson(self::URL . '/deploy-key')->assertStatus(201);
        $this->withoutRepository();
        $this->respond("'ls-remote'", 128, "Host key verification failed.\nfatal: Could not read from remote repository.\n");

        $response = $this->postJson(self::URL . '/connect', ['repo_url' => 'git@github.com:acme/app.git', 'branch' => 'main']);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertStringContainsString('does not match the one pinned for this host', (string) $response->getContent());
        $this->assertStringNotContainsString('no deploy key', (string) $response->getContent());
    }

    public function test_the_command_creates_shows_and_deletes_the_same_key(): void
    {
        $user = $this->project();

        $this->assertSame(0, Artisan::call('git:deploy-key', ['username' => 'alice', '--raw' => true]));
        $created = json_decode(trim(Artisan::output()), true);
        $this->assertTrue($created['data']['created']);
        $this->assertSame(0, Artisan::call('git:deploy-key', ['username' => 'alice', '--raw' => true]));
        $this->assertSame($created['data']['public_key'], json_decode(trim(Artisan::output()), true)['data']['public_key']);

        $this->assertSame(0, Artisan::call('git:deploy-key', ['username' => 'alice', '--delete' => true]));
        $this->assertNull(DeployKey::stored($user->fresh()));
    }

    public function test_https_remotes_are_unchanged(): void
    {
        $this->assertNull(GitRepoInput::sshHost('https://github.com/acme/app.git'));
        $this->assertSame('github.com', GitRepoInput::sshHost('git@GitHub.com:acme/app.git'));
        $this->assertSame('[git.example.test]:2222', GitRepoInput::sshHost('ssh://git@git.example.test:2222/acme/app.git'));
        $this->assertSame('git.example.test', GitRepoInput::sshHost('ssh://git@git.example.test/acme/app.git'));
        $this->assertSame(
            ['env', 'GIT_SSH_COMMAND=ssh -i /h/k -o IdentitiesOnly=yes -o IdentityAgent=none -o BatchMode=yes '
                . '-o StrictHostKeyChecking=yes -o UserKnownHostsFile=/h/kh -o GlobalKnownHostsFile=/dev/null',
                'GIT_TERMINAL_PROMPT=0', 'git', 'fetch'],
            GitUrl::withSshKey(['env', 'GIT_TERMINAL_PROMPT=0', 'git', 'fetch'], '/h/k', '/h/kh'),
        );
        $this->assertSame(self::GITHUB_ED25519_FP, DeployKey::fingerprint('github.com ' . self::GITHUB_ED25519));
    }

    private function project(): User
    {
        // A DinD project with no repository yet: its `project` checkout is the caller's to connect.
        return $this->user('main', ['git_repo' => '', 'git_branch' => null]);
    }

    private function sshKeygenFingerprint(string $public): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pa-pub-');
        file_put_contents($file, $public . "\n");
        $out = (string) shell_exec('ssh-keygen -lf ' . escapeshellarg($file));
        unlink($file);

        return explode(' ', trim($out))[1] ?? '';
    }

    private function calledWith(string $needle, string $file): bool
    {
        foreach ($this->calls() as $call) {
            if (str_contains($call, $needle) && str_contains($call, $file)) {
                return true;
            }
        }

        return false;
    }
}
