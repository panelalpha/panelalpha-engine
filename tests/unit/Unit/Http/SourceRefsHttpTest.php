<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Lib\Deploy\Source\GitRefs;
use App\Lib\Deploy\Source\GitRefsUnavailableException;
use App\Lib\Deploy\Source\GitRemoteProbe;
use App\Lib\Http\OutboundUrl;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * POST /api/source/refs: the branches and tags a remote advertises, and what
 * each refusal reads as. The probe and the address check's DNS are doubled, so
 * what is asserted is the endpoint's mapping, not a forge's answer.
 */
class SourceRefsHttpTest extends TestCase
{
    use InMemoryDatabase;

    private const REFS = "ref: refs/heads/main\tHEAD\n"
        . "65b89cc633a2192724681c211c46e74b5ae8f50e\tHEAD\n"
        . "65b89cc633a2192724681c211c46e74b5ae8f50e\trefs/heads/feature/nested\n"
        . "65b89cc633a2192724681c211c46e74b5ae8f50e\trefs/heads/main\n"
        . "65b89cc633a2192724681c211c46e74b5ae8f50e\trefs/tags/v1.0\n"
        . "0dfb2685d04204d4232f1075904de619b900fcb0\trefs/tags/v2.0\n";

    private object $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->probe = $this->fakeProbe();
        $this->app->instance(GitRemoteProbe::class, $this->probe);
        OutboundUrl::setResolverForTests(static fn (string $host): array => $host === 'github.com' ? ['140.82.121.4'] : []);
    }

    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        parent::tearDown();
    }

    public function test_it_lists_the_branches_and_tags_of_a_remote(): void
    {
        $this->probe->refs = GitRefs::parse(self::REFS);

        $response = $this->postJson('/api/source/refs', ['repository' => 'github.com/owner/repo']);

        $response->assertOk();
        $response->assertJsonPath('data.repository', 'https://github.com/owner/repo');
        $response->assertJsonPath('data.default_branch', 'main');
        $response->assertJsonPath('data.branches', ['main', 'feature/nested']);
        $response->assertJsonPath('data.tags', ['v1.0', 'v2.0']);
        $response->assertJsonPath('data.truncated', false);
    }

    public function test_a_limit_takes_one_of_each_and_flags_the_rest(): void
    {
        $this->probe->refs = GitRefs::parse(self::REFS);

        $response = $this->postJson('/api/source/refs', [
            'repository' => 'https://github.com/owner/repo.git',
            'limit' => 1,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.branches', ['main']);
        $response->assertJsonPath('data.tags', ['v1.0']);
        $response->assertJsonPath('data.truncated', true);
    }

    public function test_a_missing_repository_is_a_422(): void
    {
        $this->probe->error = GitRefsUnavailableException::NOT_FOUND;

        $response = $this->postJson('/api/source/refs', ['repository' => 'https://github.com/owner/gone.git']);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'repository');
        $response->assertJsonPath('problems.0.code', 'repository_not_found');
    }

    public function test_an_authentication_failure_is_reported_on_the_token(): void
    {
        $this->probe->error = GitRefsUnavailableException::AUTHENTICATION_FAILED;

        $response = $this->postJson('/api/source/refs', ['repository' => 'https://github.com/owner/private.git']);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'git_token');
        $response->assertJsonPath('problems.0.code', 'authentication_failed');
    }

    public function test_an_unreachable_remote_is_a_502(): void
    {
        $this->probe->error = GitRefsUnavailableException::UNREACHABLE;

        $response = $this->postJson('/api/source/refs', ['repository' => 'https://github.com/owner/slow.git']);

        $response->assertStatus(502);
        $response->assertExactJson(['message' => 'stub message', 'code' => 'remote_unreachable']);
    }

    public function test_a_loopback_host_is_refused_before_git_runs(): void
    {
        $response = $this->postJson('/api/source/refs', ['repository' => 'http://127.0.0.1/x.git']);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'repository');
        $response->assertJsonPath('problems.0.code', 'host_refused');
        $this->assertSame([], $this->probe->calls);
    }

    public function test_a_private_repository_is_read_with_the_token(): void
    {
        $this->probe->refs = GitRefs::parse(self::REFS);
        $token = 'ghp_' . str_repeat('a', 36);

        $response = $this->postJson('/api/source/refs', [
            'repository' => 'https://github.com/owner/private.git',
            'git_token' => $token,
        ]);

        $response->assertOk();
        $this->assertSame([['https://github.com/owner/private.git', $token]], $this->probe->calls);
    }

    private function fakeProbe(): object
    {
        return new class extends GitRemoteProbe {
            /** @var list<array{0: string, 1: ?string}> */
            public array $calls = [];

            public ?GitRefs $refs = null;

            public ?string $error = null;

            public function refs(string $repoUrl, ?string $token): GitRefs
            {
                $this->calls[] = [$repoUrl, $token];
                if ($this->error !== null) {
                    throw new GitRefsUnavailableException($this->error, 'stub message');
                }

                return $this->refs ?? GitRefs::parse('');
            }
        };
    }
}
