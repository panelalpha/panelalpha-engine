<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\GitRemoteProbe;
use PHPUnit\Framework\TestCase;

/**
 * A remote that cuts the protocol-v2 ref listing over HTTP/2, as GitHub's
 * edge does to some git clients, but answers the same request over HTTP/1.1.
 */
class GitRemoteProbeHttp11RetryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/probe-h11-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    /** @param string $refusal what the fake git prints when not asked for HTTP/1.1 */
    private function probe(string $refusal): object
    {
        $git = $this->dir . '/git';
        $sha = str_repeat('a', 40);
        file_put_contents($git, "#!/bin/sh\n"
            . "case \" \$* \" in *' http.version=HTTP/1.1 '*)\n"
            . "  printf 'ref: refs/heads/main\\tHEAD\\n{$sha}\\tHEAD\\n{$sha}\\trefs/heads/main\\n'; exit 0;;\n"
            . "esac\n"
            . "echo " . escapeshellarg($refusal) . " >&2; exit 128\n");
        chmod($git, 0755);

        return new class ($git) extends GitRemoteProbe {
            /** @var list<list<string>> */
            public array $commands = [];

            public function __construct(private string $git)
            {
                parent::__construct(10, 1);
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

    public function test_a_cut_ref_listing_is_retried_over_http11(): void
    {
        $probe = $this->probe('fatal: expected flush after ref listing');

        $this->assertNull($probe->problem('git_repo', 'https://example.test/o/r.git', null));
        $this->assertCount(2, $probe->commands);
        $this->assertNotContains('http.version=HTTP/1.1', $probe->commands[0]);
        $this->assertSame(['git', '-c', 'http.version=HTTP/1.1'], array_slice($probe->commands[1], 0, 3));
    }

    public function test_the_retry_also_answers_the_branch_check(): void
    {
        $probe = $this->probe('fatal: expected flush after ref listing');

        $this->assertNull($probe->problem('git_repo', 'https://example.test/o/r.git', null, 'git_token', 'main'));
        $this->assertSame('git_branch_not_found', $probe->problem('git_repo', 'https://example.test/o/r.git', null, 'git_token', 'mian')['code']);
    }

    public function test_the_retry_keeps_the_askpass_wrapper_in_front(): void
    {
        $probe = $this->probe('fatal: expected flush after ref listing');

        $this->assertNull($probe->problem('git_repo', 'https://example.test/o/r.git', 'tok'));
        $retry = $probe->commands[1];
        $this->assertSame('env', $retry[0]);
        $at = array_search('git', $retry, true);
        $this->assertSame(['-c', 'http.version=HTTP/1.1'], array_slice($retry, $at + 1, 2));
    }

    public function test_any_other_failure_is_not_retried(): void
    {
        $probe = $this->probe('fatal: repository not found');

        $this->assertSame('git_repo_requires_token', $probe->problem('git_repo', 'https://example.test/o/r.git', null)['code']);
        $this->assertCount(1, $probe->commands);
    }
}
