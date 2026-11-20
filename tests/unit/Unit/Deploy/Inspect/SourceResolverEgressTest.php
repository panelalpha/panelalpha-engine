<?php

namespace Tests\Unit\Deploy\Inspect;

use App\Lib\Deploy\Inspect\SourceResolver;
use App\Lib\Http\OutboundUrl;
use PHPUnit\Framework\TestCase;
use Tests\Support\SourceResolverEgressFixtures;
use Tests\Support\TestNetwork;

/**
 * The inspect clone runs in core, so the host it resolves once must be the host
 * it connects to, and a remote that redirects must be refused rather than
 * followed to whatever the redirect names.
 */
class SourceResolverEgressTest extends TestCase
{
    use SourceResolverEgressFixtures;

    public function test_the_clone_is_pinned_to_the_checked_address(): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => [TestNetwork::routable(34)]);

        $command = $this->cloneCommand('https://files.example.com/o/r.git');

        $this->assertContains('http.curloptResolve=files.example.com:443:' . TestNetwork::routable(34), $command);
    }

    /** @return list<string> */
    private function cloneCommand(string $repoUrl): array
    {
        $method = new \ReflectionMethod(SourceResolver::class, 'cloneCommand');

        return (array) $method->invoke(null, $repoUrl, null, '/tmp/target');
    }
}
