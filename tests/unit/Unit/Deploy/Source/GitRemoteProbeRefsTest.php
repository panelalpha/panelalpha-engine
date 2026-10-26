<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\GitRefsUnavailableException;
use App\Lib\Deploy\Source\GitRemoteProbe;
use App\Lib\Http\OutboundUrl;
use PHPUnit\Framework\TestCase;

/**
 * refs() over a fake git. What the endpoint depends on is how a forge's ref
 * listing and its failures are read, which a real remote cannot pin down.
 */
class GitRemoteProbeRefsTest extends TestCase
{
    private const REFS = "ref: refs/heads/main\tHEAD\n"
        . "65b89cc633a2192724681c211c46e74b5ae8f50e\tHEAD\n"
        . "65b89cc633a2192724681c211c46e74b5ae8f50e\trefs/heads/feature/nested\n"
        . "65b89cc633a2192724681c211c46e74b5ae8f50e\trefs/heads/main\n"
        . "65b89cc633a2192724681c211c46e74b5ae8f50e\trefs/tags/v1.0\n"
        . "0dfb2685d04204d4232f1075904de619b900fcb0\trefs/tags/v2.0\n";

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        OutboundUrl::setResolverForTests(static fn (string $host): array => ['140.82.121.4']);
        $this->dir = sys_get_temp_dir() . '/probe-refs-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        foreach (glob($this->dir . '/git*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function probe(string $body): GitRemoteProbe
    {
        $git = $this->dir . '/git';
        file_put_contents($git, "#!/bin/sh\n" . $body);
        chmod($git, 0755);

        return new class ($git) extends GitRemoteProbe {
            /** @var list<list<string>> */
            public array $commands = [];

            public function __construct(private string $git)
            {
                parent::__construct(5, 1);
            }

            protected function run(array $command): array
            {
                $this->commands[] = $command;
                $at = array_search('git', $command, true);
                $command[$at] = $this->git;

                return parent::run($command);
            }
        };
    }

    public function test_a_reachable_remote_returns_its_refs(): void
    {
        $probe = $this->probe('printf ' . escapeshellarg(self::REFS));

        $refs = $probe->refs('https://example.test/o/r.git', null);

        $this->assertSame('main', $refs->defaultBranch);
        $this->assertSame(['feature/nested', 'main'], $refs->branches);
        $this->assertSame(['v1.0', 'v2.0'], $refs->tags);

        $command = $probe->commands[0];
        $this->assertContains('--symref', $command);
        $this->assertContains('HEAD', $command);
        $this->assertContains('refs/heads/*', $command);
        $this->assertContains('refs/tags/*', $command);
        $this->assertContains('http.followRedirects=false', $command);
    }

    public function test_a_private_repository_asks_for_a_token(): void
    {
        $probe = $this->probe(
            "echo 'fatal: could not read Username for https://example.test: terminal prompts disabled' >&2; exit 128"
        );

        $e = $this->refusal($probe, 'https://example.test/o/r.git', null);

        $this->assertSame(GitRefsUnavailableException::AUTHENTICATION_FAILED, $e->outcome);
        $this->assertStringContainsString('git_token', $e->getMessage());
    }

    public function test_a_missing_repository_is_not_found(): void
    {
        $probe = $this->probe("echo 'remote: Repository not found.' >&2; exit 128");

        $e = $this->refusal($probe, 'https://example.test/o/r.git', str_repeat('a', 40));

        $this->assertSame(GitRefsUnavailableException::NOT_FOUND, $e->outcome);
    }

    public function test_an_unresolvable_host_is_unreachable(): void
    {
        $probe = $this->probe(
            "echo 'fatal: unable to access: Could not resolve host: example.test' >&2; exit 128"
        );

        $e = $this->refusal($probe, 'https://example.test/o/r.git', null);

        $this->assertSame(GitRefsUnavailableException::UNREACHABLE, $e->outcome);
    }

    /** The token never travels in argv, and its askpass file is removed again. */
    public function test_a_token_travels_in_an_askpass_file_that_is_cleaned_up(): void
    {
        $token = 'ghp_' . str_repeat('z', 36);
        $probe = $this->probe('printf ' . escapeshellarg(self::REFS));

        $probe->refs('https://example.test/o/r.git', $token);

        $command = $probe->commands[0];
        $this->assertSame('env', $command[0]);
        $this->assertNotContains($token, $command);

        $askpass = null;
        foreach ($command as $argument) {
            if (str_starts_with($argument, 'GIT_ASKPASS=')) {
                $askpass = substr($argument, strlen('GIT_ASKPASS='));
            }
        }
        $this->assertNotNull($askpass);
        $this->assertFileDoesNotExist($askpass, 'the credential helper outlived the call');
    }

    private function refusal(GitRemoteProbe $probe, string $repository, ?string $token): GitRefsUnavailableException
    {
        try {
            $probe->refs($repository, $token);
        } catch (GitRefsUnavailableException $e) {
            return $e;
        }

        $this->fail('the probe was expected to refuse');
    }
}
