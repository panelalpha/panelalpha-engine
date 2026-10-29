<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\GitRefsUnavailableException;
use App\Lib\Deploy\Source\GitRemoteProbe;
use App\Lib\Http\OutboundUrl;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * The probe runs git from core, so it connects to the address core's own check
 * accepts, not to whatever the name resolves to when git asks again.
 */
class GitRemoteProbePinTest extends TestCase
{
    private const URL = 'https://code.example.test/o/r.git';

    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        parent::tearDown();
    }

    public function test_the_probe_connects_to_the_address_the_check_accepted(): void
    {
        $this->resolveTo(TestNetwork::routable(4));
        $probe = $this->recordingProbe();

        $this->assertNull($probe->problem('git_repo', self::URL, null));

        $this->assertContains('http.curloptResolve=code.example.test:443:' . TestNetwork::routable(4), $probe->commands[0]);
    }

    public function test_a_host_that_now_resolves_to_an_internal_address_is_refused_without_running_git(): void
    {
        $this->resolveTo(TestNetwork::private(5));
        $probe = $this->recordingProbe();

        $problem = $probe->problem('git_repo', self::URL, null);

        $this->assertSame(['git_repo', 'host_refused'], [$problem['field'] ?? null, $problem['code'] ?? null]);
        $this->assertSame([], $probe->commands);
    }

    public function test_the_ref_listing_connects_to_the_address_the_check_accepted(): void
    {
        $this->resolveTo(TestNetwork::routable(4));
        $probe = $this->recordingProbe();

        $probe->refs(self::URL, null);

        $this->assertContains('http.curloptResolve=code.example.test:443:' . TestNetwork::routable(4), $probe->commands[0]);
    }

    public function test_a_ref_listing_for_a_host_that_now_resolves_internal_is_unavailable_without_running_git(): void
    {
        $this->resolveTo(TestNetwork::METADATA);
        $probe = $this->recordingProbe();

        try {
            $probe->refs(self::URL, null);
            $this->fail('expected the ref listing to be refused');
        } catch (GitRefsUnavailableException $e) {
            $this->assertSame(GitRefsUnavailableException::UNREACHABLE, $e->outcome);
        }
        $this->assertSame([], $probe->commands);
    }

    private function resolveTo(string $address): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => [$address]);
    }

    private function recordingProbe(): GitRemoteProbe
    {
        return new class (1, 1) extends GitRemoteProbe
        {
            /** @var list<list<string>> */
            public array $commands = [];

            protected function run(array $command): array
            {
                $this->commands[] = $command;

                return ['timedOut' => false, 'ok' => true, 'stderr' => ''];
            }
        };
    }
}
