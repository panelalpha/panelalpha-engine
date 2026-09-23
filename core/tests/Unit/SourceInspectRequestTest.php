<?php

namespace Tests\Unit;

use App\Exceptions\ProblemException;
use App\Http\Requests\SourceInspectRequest;
use App\Http\Requests\UserStoreRequest;
use App\Lib\Deploy\Inspect\InspectException;
use App\Lib\Deploy\Inspect\SourceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Inspect and create, asked the same question. They used to disagree on every
 * interesting repository URL, so an agent that inspected a source and then
 * created a project from it got two answers to one question.
 */
class SourceInspectRequestTest extends TestCase
{
    /** The regression test for the whole exercise: one string, one verdict. */
    #[DataProvider('repositoryProvider')]
    public function test_inspect_and_create_agree_on_a_repository(string $repo, ?string $code): void
    {
        $this->assertSame(
            $code,
            $this->inspectProblem(['source' => $repo])['code'] ?? null,
            "inspect disagreed about {$repo}"
        );
        $this->assertSame(
            $code === null ? null : str_replace('source_', 'git_repo_', $code),
            $this->createProblem(['email' => 'o@e.com', 'git_repo' => $repo])['code'] ?? null,
            "create disagreed about {$repo}"
        );
    }

    public static function repositoryProvider(): array
    {
        return [
            'https' => ['https://github.com/vvolv/market-radar.git', null],
            'schemeless' => ['github.com/vvolv/market-radar', null],
            'self-hosted, no owner' => ['https://git.internal/nope.git', null],
            'scp-style SSH' => ['git@github.com:vvolv/market-radar.git', 'source_ssh_unsupported'],
            'ssh scheme' => ['ssh://git@github.com/o/r.git', 'source_ssh_unsupported'],
            'ftp' => ['ftp://github.com/o/r.git', 'source_unsupported_scheme'],
            'bare forge host' => ['https://github.com', 'source_incomplete'],
            'credentials in url' => ['https://u:p@github.com/o/r.git', 'source_embedded_credentials'],
        ];
    }

    /** And the suggestion survives, which is what makes the 422 actionable. */
    public function test_inspect_also_hands_back_the_https_spelling(): void
    {
        $problem = $this->inspectProblem(['source' => 'git@github.com:vvolv/market-radar.git']);

        $this->assertSame('https://github.com/vvolv/market-radar.git', $problem['suggestion']);
    }

    /** A path and a project name are not repositories and must not be read as one. */
    #[DataProvider('nonRepositoryProvider')]
    public function test_a_path_or_project_is_left_to_the_resolver(string $source): void
    {
        $request = SourceInspectRequest::create('/api/source/inspect', 'POST', ['source' => $source]);
        $request->setContainer($this->app);
        $request->validateResolved();

        $this->assertSame($source, $request->validated()['source'], 'the value was rewritten');
    }

    public static function nonRepositoryProvider(): array
    {
        return [
            'absolute path' => ['/home/myapp/project'],
            'project name' => ['myapp'],
        ];
    }

    /** Something that is none of the three now fails with a code, not a bare sentence. */
    public function test_an_unrecognisable_source_is_reported_with_a_code(): void
    {
        $problem = $this->inspectProblem(['source' => 'not a source at all!!']);

        $this->assertSame('source_unrecognised', $problem['code']);
    }

    /** An explicit `type` wins, so a path that looks like a host stays a path. */
    public function test_a_declared_type_overrides_classification(): void
    {
        $request = SourceInspectRequest::create('/api/source/inspect', 'POST', [
            'source' => 'example.com/not/a/repo',
            'type' => 'path',
        ]);
        $request->setContainer($this->app);
        $request->validateResolved();

        $this->assertSame('example.com/not/a/repo', $request->validated()['source']);
    }

    // ---- the token rules travel too -------------------------------------

    public function test_inspect_runs_the_same_token_shape_checks_as_create(): void
    {
        $problem = $this->inspectProblem([
            'source' => 'https://github.com/o/r.git',
            'git_token' => 'Bearer ghp_' . str_repeat('a', 36),
        ]);

        $this->assertSame('git_token_has_auth_prefix', $problem['code']);
    }

    /** The pairing check still holds, against this endpoint's field name. */
    public function test_a_token_still_requires_an_https_source(): void
    {
        $problem = $this->inspectProblem([
            'source' => 'http://git.internal/o/r.git',
            'git_token' => 'ghp_' . str_repeat('a', 36),
        ]);

        $this->assertSame('git_token_requires_https_repo', $problem['code']);
    }

    /** A schemeless source is a valid pairing, as it is at create. */
    public function test_a_schemeless_source_can_carry_a_token(): void
    {
        $request = SourceInspectRequest::create('/api/source/inspect', 'POST', [
            'source' => 'github.com/o/r',
            'git_token' => 'ghp_' . str_repeat('a', 36),
        ]);
        $request->setContainer($this->app);
        $request->validateResolved();

        $this->assertSame('https://github.com/o/r', $request->validated()['source']);
    }

    // ---- the resolver carries the problem too ---------------------------

    /**
     * assertCloneable is reached by callers that never went through the
     * request, and used to flatten the problem to its sentence -- losing the
     * suggestion on the way out.
     */
    public function test_the_resolver_refuses_ssh_with_the_suggestion_intact(): void
    {
        try {
            SourceResolver::assertCloneable('git@github.com:vvolv/market-radar.git');
            $this->fail('expected the resolver to refuse an SSH remote');
        } catch (InspectException $e) {
            $problem = $e->toProblem();

            $this->assertSame('source_ssh_unsupported', $problem['code']);
            $this->assertSame('https://github.com/vvolv/market-radar.git', $problem['suggestion']);
            // The sentence still reads on its own, for `message`.
            $this->assertStringContainsString('SSH remotes are not supported', $e->getMessage());
        }
    }

    /** A failure with nothing extra to say still gets a field and a code. */
    public function test_a_plain_resolver_failure_still_becomes_a_problem(): void
    {
        $problem = (new InspectException('No such directory: /nope'))->toProblem();

        $this->assertSame('source', $problem['field']);
        $this->assertSame('source_unreadable', $problem['code']);
        $this->assertSame('No such directory: /nope', $problem['message']);
        $this->assertArrayNotHasKey('suggestion', $problem);
    }

    /** The project endpoint attributes to the field its caller actually sent. */
    public function test_the_project_endpoint_attributes_to_project(): void
    {
        $problem = (new InspectException('Project has no files yet.'))->toProblem('project');

        $this->assertSame('project', $problem['field']);
        $this->assertSame('project_unreadable', $problem['code']);
    }

    // ---- the shape that actually ships ----------------------------------

    /**
     * The contract: `message` unchanged for clients reading it today,
     * `problems` added beside it. Asserted through the real handler here
     * rather than in a feature test, which would need a database.
     */
    public function test_a_resolver_failure_renders_as_message_errors_and_problems(): void
    {
        $inspect = null;
        try {
            SourceResolver::assertCloneable('git@github.com:vvolv/market-radar.git');
        } catch (InspectException $e) {
            $inspect = $e;
        }
        $this->assertNotNull($inspect);

        $request = \Illuminate\Http\Request::create('/api/source/inspect', 'POST');
        $request->headers->set('Accept', 'application/json');

        $response = $this->app
            ->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            ->render($request, ProblemException::of([$inspect->toProblem()]));

        $this->assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);

        $this->assertStringContainsString('SSH remotes are not supported', $body['message']);

        $this->assertArrayHasKey('errors', $body);
        $this->assertArrayHasKey('source', $body['errors']);

        $this->assertArrayHasKey('problems', $body);
        $this->assertSame('source', $body['problems'][0]['field']);
        $this->assertSame('source_ssh_unsupported', $body['problems'][0]['code']);
        $this->assertSame(
            'https://github.com/vvolv/market-radar.git',
            $body['problems'][0]['suggestion']
        );
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function inspectProblem(array $payload): array
    {
        return $this->firstProblem(
            SourceInspectRequest::create('/api/source/inspect', 'POST', $payload)
        );
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function createProblem(array $payload): array
    {
        return $this->firstProblem(
            UserStoreRequest::create('/api/users', 'POST', $payload)
        );
    }

    /** @return array<string, mixed> */
    private function firstProblem(\Illuminate\Foundation\Http\FormRequest $request): array
    {
        $request->setContainer($this->app);

        try {
            $request->validateResolved();
        } catch (ProblemException $e) {
            return $e->problems[0] ?? [];
        }

        return [];
    }
}
