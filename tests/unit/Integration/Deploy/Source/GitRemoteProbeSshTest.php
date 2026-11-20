<?php

namespace Tests\Integration\Deploy\Source;

use App\Lib\Deploy\Source\GitRemoteProbe;
use App\Lib\Deploy\Source\GitTransport;
use PHPUnit\Framework\TestCase;

/**
 * An SSH remote is probed with the clone's deploy key; the key and pinned hosts
 * reach ssh as files of their own, gone afterwards.
 */
class GitRemoteProbeSshTest extends TestCase
{
    private const KEY = [
        'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nx\n-----END OPENSSH PRIVATE KEY-----\n",
        'public_key' => 'ssh-ed25519 AAAA test',
        'fingerprint' => 'SHA256:x',
        'known_hosts' => ['[git.example.com]:2222' => ['[git.example.com]:2222 ssh-ed25519 AAAAhostkey']],
    ];

    public function test_the_key_and_known_hosts_go_along_and_are_removed(): void
    {
        $probe = $this->probe(['ok' => true, 'stderr' => '', 'stdout' => '']);

        $this->assertNull($probe->problem('git_repo', 'ssh://git@git.example.com:2222/acme/shop.git', null, 'git_token', null, 'git_branch', self::KEY));

        $command = $probe->commands[0];
        $this->assertSame('env', $command[0]);
        $ssh = (string) $command[1];
        $this->assertStringStartsWith('GIT_SSH_COMMAND=ssh -i ', $ssh);
        $this->assertStringContainsString('StrictHostKeyChecking=yes', $ssh);
        $this->assertContains('ssh://git@git.example.com:2222/acme/shop.git', $command);
        $this->assertContains(GitTransport::assignment(GitTransport::schemes(withSsh: true)), $command);
        $this->assertSame(self::KEY['private_key'], $probe->seenKey);
        $this->assertStringContainsString('[git.example.com]:2222 ssh-ed25519 AAAAhostkey', $probe->seenKnownHosts);
        $this->assertFileDoesNotExist($probe->keyPath);
    }

    public function test_a_key_the_forge_does_not_take_names_the_key(): void
    {
        foreach (['git@github.com: Permission denied (publickey).', 'ERROR: Repository not found.'] as $stderr) {
            $problem = $this->probe(['ok' => false, 'stderr' => $stderr])
                ->problem('git_repo', 'git@github.com:acme/shop.git', null, 'git_token', null, 'git_branch', self::KEY);

            $this->assertSame('git_deploy_key', $problem['field'] ?? null, $stderr);
            $this->assertSame('git_deploy_key_rejected', $problem['code'] ?? null, $stderr);
        }
    }

    public function test_a_changed_host_key_and_an_unreachable_host(): void
    {
        $changed = $this->probe(['ok' => false, 'stderr' => 'Host key verification failed.'])
            ->problem('git_repo', 'git@github.com:acme/shop.git', null, 'git_token', null, 'git_branch', self::KEY);
        $this->assertSame('git_repo_host_key_changed', $changed['code'] ?? null);

        $down = $this->probe(['ok' => false, 'stderr' => 'ssh: connect to host github.com port 22: Connection refused'])
            ->problem('git_repo', 'git@github.com:acme/shop.git', null, 'git_token', null, 'git_branch', self::KEY);
        $this->assertSame('git_repo_unreachable', $down['code'] ?? null);
        $this->assertTrue($down['retryable'] ?? false);
    }

    public function test_a_branch_the_remote_lacks(): void
    {
        $probe = $this->probe(['ok' => true, 'stderr' => '', 'stdout' => "ref: refs/heads/main\tHEAD\n".str_repeat("a", 40)."\tHEAD\n".str_repeat("a", 40)."\trefs/heads/main\n"]);

        $problem = $probe->problem('git_repo', 'git@github.com:acme/shop.git', null, 'git_token', 'mian', 'git_branch', self::KEY);

        $this->assertSame('git_branch_not_found', $problem['code'] ?? null);
        $this->assertSame('main', $problem['suggestion'] ?? null);
    }

    /** @param array{ok: bool, stderr: string, stdout?: string} $answer */
    private function probe(array $answer): GitRemoteProbe
    {
        return new class($answer) extends GitRemoteProbe
        {
            /** @var list<list<string>> */
            public array $commands = [];

            public string $seenKey = '';

            public string $seenKnownHosts = '';

            public string $keyPath = '';

            /** @param array{ok: bool, stderr: string, stdout?: string} $answer */
            public function __construct(private readonly array $answer)
            {
                parent::__construct(5, 1);
            }

            protected function run(array $command): array
            {
                $this->commands[] = $command;
                preg_match('/-i (\S+).*UserKnownHostsFile=(\S+)/', (string) ($command[1] ?? ''), $m);
                $this->keyPath = $m[1] ?? '';
                $this->seenKey = (string) @file_get_contents($m[1] ?? '');
                $this->seenKnownHosts = (string) @file_get_contents($m[2] ?? '');
                $this->setLastOutput($this->answer['stdout'] ?? '');

                return ['timedOut' => false, 'ok' => $this->answer['ok'], 'stderr' => $this->answer['stderr']];
            }

            private function setLastOutput(string $output): void
            {
                $property = new \ReflectionProperty(GitRemoteProbe::class, 'lastOutput');
                $property->setValue($this, $output);
            }
        };
    }
}
