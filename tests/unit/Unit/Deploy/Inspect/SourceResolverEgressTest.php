<?php

namespace Tests\Unit\Deploy\Inspect;

use App\Lib\Deploy\Inspect\InspectException;
use App\Lib\Deploy\Inspect\SourceResolver;
use App\Lib\Http\OutboundUrl;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * The inspect clone runs in core, so the host it resolves once must be the host
 * it connects to, and a remote that redirects must be refused rather than
 * followed to whatever the redirect names.
 */
class SourceResolverEgressTest extends TestCase
{
    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        parent::tearDown();
    }

    public function test_the_clone_is_pinned_to_the_checked_address(): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => [TestNetwork::routable(34)]);

        $command = $this->cloneCommand('https://files.example.com/o/r.git');

        $this->assertContains('http.curloptResolve=files.example.com:443:' . TestNetwork::routable(34), $command);
    }

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

    /** @return list<string> */
    private function cloneCommand(string $repoUrl): array
    {
        $method = new \ReflectionMethod(SourceResolver::class, 'cloneCommand');

        return (array) $method->invoke(null, $repoUrl, null, '/tmp/target');
    }

    /** @param list<string> $command */
    private function runGitCommand(array $command): void
    {
        $method = new \ReflectionMethod(SourceResolver::class, 'run');
        $method->invoke(new SourceResolver(sys_get_temp_dir(), 10), $command, 'Could not clone the repository', '/tmp');
    }
}
