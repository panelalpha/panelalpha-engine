<?php

namespace Tests\Integration\Deploy\Inspect;

use App\Lib\Deploy\Inspect\InspectException;
use App\Lib\Deploy\Inspect\SourceResolver;
use PHPUnit\Framework\TestCase;
use Tests\Support\SourceResolverEgressFixtures;

/**
 * The inspect clone runs in core, so the host it resolves once must be the host
 * it connects to, and a remote that redirects must be refused rather than
 * followed to whatever the redirect names.
 */
class SourceResolverEgressTest extends TestCase
{
    use SourceResolverEgressFixtures;

    public function test_a_redirecting_remote_is_refused_with_the_final_url(): void
    {
        $newline = chr(10);
        $output = 'fatal: unable to update url base from redirection:' . $newline
            . '  asked for: https://example.com/o/r.git/info/refs?service=git-upload-pack' . $newline
            . '   redirect: https://evil.example/o/r.git/info/refs?service=git-upload-pack';

        try {
            $this->runGitCommand(['sh', '-c', 'printf %s ' . escapeshellarg($output) . ' >&2; exit 1']);
            $this->fail('a redirecting remote was not refused');
        } catch (InspectException $e) {
            $problem = $e->toProblem();
            $this->assertSame('redirect_not_followed', $problem['code'] ?? null);
            $this->assertStringContainsString('https://evil.example/o/r.git', (string) ($problem['message'] ?? ''));
        }
    }

    /** With redirects off, git names only the status of a moved repository: it is still refused as a redirect. */
    public function test_a_bare_redirect_status_is_refused_as_a_redirect(): void
    {
        $output = "fatal: unable to access 'https://gitlab.com/o/r/': The requested URL returned error: 301";

        try {
            $this->runGitCommand(['sh', '-c', 'printf %s ' . escapeshellarg($output) . ' >&2; exit 128']);
            $this->fail('a redirecting remote was not refused');
        } catch (InspectException $e) {
            $problem = $e->toProblem();
            $this->assertSame('redirect_not_followed', $problem['code'] ?? null);
            $this->assertStringContainsString('redirects to another address', (string) ($problem['message'] ?? ''));
        }
    }

    public function test_another_http_error_is_still_a_clone_failure(): void
    {
        try {
            $this->runGitCommand(['sh', '-c', "printf %s 'fatal: unable to access: The requested URL returned error: 500' >&2; exit 128"]);
            $this->fail('the clone failure was not raised');
        } catch (InspectException $e) {
            $this->assertNotSame('redirect_not_followed', $e->toProblem()['code'] ?? null);
            $this->assertStringStartsWith('Could not clone the repository', $e->getMessage());
        }
    }

    /** @param list<string> $command */
    private function runGitCommand(array $command): void
    {
        $method = new \ReflectionMethod(SourceResolver::class, 'run');
        $method->invoke(new SourceResolver(sys_get_temp_dir(), 10), $command, 'Could not clone the repository', '/tmp');
    }
}
