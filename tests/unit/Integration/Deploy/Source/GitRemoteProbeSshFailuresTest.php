<?php

namespace Tests\Integration\Deploy\Source;

use App\Lib\Deploy\Source\GitRemoteProbe;
use PHPUnit\Framework\TestCase;

/** An SSH probe that learns nothing says nothing: no key, a crash or a silent forge never refuses the create on their own. */
class GitRemoteProbeSshFailuresTest extends TestCase
{
    private const KEY = [
        'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nx\n-----END OPENSSH PRIVATE KEY-----\n",
        'public_key' => 'ssh-ed25519 AAAA test',
        'fingerprint' => 'SHA256:x',
        'known_hosts' => ['github.com' => ['github.com ssh-ed25519 AAAAhostkey']],
    ];

    public function test_a_deploy_key_without_its_private_half_runs_nothing(): void
    {
        $probe = $this->probe(static fn (): array => ['timedOut' => false, 'ok' => true, 'stderr' => '']);

        $problem = $probe->problem('git_repo', 'git@github.com:acme/shop.git', null, 'git_token', null, 'git_branch', ['private_key' => ''] + self::KEY);

        $this->assertNull($problem);
        $this->assertSame([], $probe->commands);
    }

    public function test_a_failure_while_probing_is_not_a_refusal_and_leaves_no_key_behind(): void
    {
        $probe = $this->probe(static function (array $command): array {
            throw new \RuntimeException('git is not installed');
        });

        $problem = $probe->problem('git_repo', 'git@github.com:acme/shop.git', null, 'git_token', 'main', 'git_branch', self::KEY);

        $this->assertNull($problem);
        $this->assertCount(1, $probe->commands);
        $this->assertNotSame('', $probe->keyPath);
        $this->assertFileDoesNotExist($probe->keyPath);
        $this->assertDirectoryDoesNotExist(dirname($probe->keyPath));
    }

    public function test_a_forge_that_does_not_answer_over_ssh_is_a_retryable_timeout(): void
    {
        $probe = $this->probe(static fn (): array => ['timedOut' => true, 'ok' => false, 'stderr' => '']);

        $problem = $probe->problem('git_repo', 'ssh://git@git.example.com:2222/acme/shop.git', null, 'git_token', null, 'git_branch', self::KEY);

        $this->assertSame('git_repo_timeout', $problem['code'] ?? null);
        $this->assertSame('[git.example.com]:2222 did not answer over SSH within 5s. Nothing was created; retry.', $problem['message'] ?? null);
        $this->assertTrue($problem['retryable'] ?? false);
        $this->assertCount(1, $probe->commands);
    }

    /** @param \Closure(list<string>): array{timedOut: bool, ok: bool, stderr: string} $answer */
    private function probe(\Closure $answer): GitRemoteProbe
    {
        return new class($answer) extends GitRemoteProbe
        {
            /** @var list<list<string>> */
            public array $commands = [];

            public string $keyPath = '';

            public function __construct(private readonly \Closure $answer)
            {
                parent::__construct(5, 1);
            }

            protected function run(array $command): array
            {
                $this->commands[] = $command;
                preg_match('/-i (\S+)/', (string) ($command[1] ?? ''), $m);
                $this->keyPath = $m[1] ?? '';

                return ($this->answer)($command);
            }
        };
    }
}
