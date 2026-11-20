<?php

namespace Tests\Integration\Deploy\Source;

use App\Lib\Deploy\Source\GitRemoteProbe;
use App\Lib\Http\OutboundUrl;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** A redirect whose target lookup cannot connect is still refused as a redirect, without naming an address. */
class GitRemoteProbeRedirectLookupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OutboundUrl::setResolverForTests(static fn (string $host): array => [TestNetwork::routable(4)]);
    }

    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        parent::tearDown();
    }

    public function test_a_lookup_that_cannot_connect_still_names_the_redirect(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::failedConnection()]);
        $probe = new class extends GitRemoteProbe
        {
            public function __construct()
            {
                parent::__construct(10, 1);
            }

            protected function run(array $command): array
            {
                return ['timedOut' => false, 'ok' => false, 'stderr' => "fatal: unable to access 'https://gitlab.example/group/project/': The requested URL returned error: 301"];
            }
        };

        $problem = $probe->problem('git_repo', 'https://gitlab.example/group/project', null);

        $this->assertSame('git_repo_redirect_not_followed', $problem['code'] ?? null);
        $this->assertStringStartsWith('The repository redirects to another address.', $problem['message'] ?? '');
        Http::assertSentCount(1);
    }
}
