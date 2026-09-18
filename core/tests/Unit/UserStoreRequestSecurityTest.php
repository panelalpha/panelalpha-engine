<?php

namespace Tests\Unit;

use App\Exceptions\ProblemException;
use App\Http\Requests\UserStoreRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserStoreRequestSecurityTest extends TestCase
{
    #[DataProvider('unsafeRepositoryProvider')]
    public function test_git_token_rejects_unsafe_repository_url(string $repo): void
    {
        $validator = $this->validator([
            'git_repo' => $repo,
            'git_token' => 'secret',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('git_token', $validator->errors()->toArray());
    }

    public static function unsafeRepositoryProvider(): array
    {
        return [
            'HTTP' => ['http://git.example.com/org/repo.git'],
            'embedded credentials' => ['https://user:password@git.example.com/org/repo.git'],
        ];
    }

    public function test_git_token_accepts_clean_https_repository_url(): void
    {
        $validator = $this->validator([
            'git_repo' => 'https://git.example.com/org/repo.git',
            'git_token' => 'secret',
        ]);

        $this->assertFalse($validator->fails(), (string) $validator->errors());
    }

    public function test_git_token_requires_repository_url(): void
    {
        $validator = $this->validator(['git_token' => 'secret']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('git_token', $validator->errors()->toArray());
    }

    private function validator(array $payload): \Illuminate\Contracts\Validation\Validator
    {
        $request = UserStoreRequest::create('/api/users', 'POST', $payload);
        $request->setContainer($this->app);

        return Validator::make($payload, $request->rules());
    }

    // ---- `name` is an alias for `username` ------------------------------

    /**
     * Every other client calls the field `name`. The MCP tool maps it to
     * `username` on the way in, so only a direct REST caller was affected --
     * and affected silently: the request validated, and the engine derived a
     * username from the git repository instead.
     *
     * suroi's test sent `name: suroi2dd4`; the engine created `suroi`, which
     * then collided with an account already of that name and failed the deploy
     * from inside a stage, reported as `code: deploy_failed` on a request that
     * was in fact misread.
     */
    public function test_name_is_accepted_as_the_username(): void
    {
        $request = UserStoreRequest::create('/api/users', 'POST', [
            'name' => 'shop4a2f',
            'email' => 'ops@example.com',
        ]);
        $request->setContainer($this->app);
        $request->validateResolved();

        $this->assertSame('shop4a2f', $request->validated()['username']);
    }

    /** `username` still wins, and still works on its own. */
    public function test_username_still_works_and_wins_over_name(): void
    {
        $only = UserStoreRequest::create('/api/users', 'POST', [
            'username' => 'shop4a2f',
            'email' => 'ops@example.com',
        ]);
        $only->setContainer($this->app);
        $only->validateResolved();
        $this->assertSame('shop4a2f', $only->validated()['username']);

        $both = UserStoreRequest::create('/api/users', 'POST', [
            'username' => 'firstname',
            'name' => 'secondname',
            'email' => 'ops@example.com',
        ]);
        $both->setContainer($this->app);
        $both->validateResolved();
        $this->assertSame('firstname', $both->validated()['username']);
    }

    /**
     * The alias is validated like the field it aliases, not waved through.
     *
     * Asserted against the *merged* input rather than through
     * `validateResolved()`, because a failing validation there goes to the
     * exception handler and needs a URL generator this unit test has no
     * reason to build. What matters is that the aliased value ends up under
     * `username` and is then judged by `username`'s own rules.
     */
    public function test_the_aliased_name_is_held_to_the_username_rules(): void
    {
        foreach (['has space', 'has_underscore', str_repeat('a', 16)] as $bad) {
            $request = UserStoreRequest::create('/api/users', 'POST', ['name' => $bad]);
            $request->setContainer($this->app);

            $prepare = new \ReflectionMethod($request, 'prepareForValidation');
            $prepare->setAccessible(true);
            $prepare->invoke($request);

            $validator = Validator::make($request->all(), $request->rules());

            $this->assertTrue($validator->fails(), $bad);
            $this->assertArrayHasKey('username', $validator->errors()->toArray(), $bad);
        }
    }

    /** A name that is already a valid username passes. */
    public function test_a_valid_name_is_accepted_by_the_username_rules(): void
    {
        $request = UserStoreRequest::create('/api/users', 'POST', ['name' => 'shop4a2f']);
        $request->setContainer($this->app);

        $prepare = new \ReflectionMethod($request, 'prepareForValidation');
        $prepare->setAccessible(true);
        $prepare->invoke($request);

        $validator = Validator::make($request->all(), $request->rules());

        $this->assertFalse($validator->fails(), (string) $validator->errors());
        $this->assertSame('shop4a2f', $request->input('username'));
    }

    // ---- `git_repo` is a repository, not merely a URL -------------------

    /**
     * The incident this replaced `url` for: "The git repo must be a valid
     * URL." -- true, and silent about the URL the engine could have named.
     */
    public function test_an_ssh_remote_is_refused_with_the_https_spelling(): void
    {
        $problem = $this->firstProblem([
            'email' => 'ops@example.com',
            'git_repo' => 'git@github.com:vvolv/market-radar.git',
        ]);

        $this->assertSame('git_repo', $problem['field']);
        $this->assertSame('git_repo_ssh_unsupported', $problem['code']);
        $this->assertSame('https://github.com/vvolv/market-radar.git', $problem['suggestion']);
    }

    /** `errors` is what the panel reads; `problems` arrives beside it. */
    public function test_the_legacy_errors_shape_survives(): void
    {
        $e = $this->failureFor([
            'email' => 'ops@example.com',
            'git_repo' => 'git@github.com:vvolv/market-radar.git',
        ]);

        $errors = $e->errors();
        $this->assertArrayHasKey('git_repo', $errors);
        $this->assertIsString($errors['git_repo'][0]);
        $this->assertStringContainsString('SSH remotes are not supported', $errors['git_repo'][0]);
    }

    /** `url` used to wave these through, to fail at clone minutes later. */
    #[DataProvider('uncloneableProvider')]
    public function test_what_cannot_be_cloned_is_refused_at_the_request(string $repo, string $code): void
    {
        $problem = $this->firstProblem(['email' => 'ops@example.com', 'git_repo' => $repo]);

        $this->assertSame($code, $problem['code']);
    }

    public static function uncloneableProvider(): array
    {
        return [
            'ftp' => ['ftp://github.com/o/r.git', 'git_repo_unsupported_scheme'],
            'bare host' => ['https://github.com', 'git_repo_incomplete'],
            'no repository' => ['https://github.com/vvolv', 'git_repo_incomplete'],
        ];
    }

    /** The schemeless spelling still works, and still gains its scheme. */
    public function test_a_schemeless_repository_is_accepted(): void
    {
        $request = UserStoreRequest::create('/api/users', 'POST', [
            'email' => 'ops@example.com',
            'git_repo' => 'github.com/vvolv/market-radar',
        ]);
        $request->setContainer($this->app);
        $request->validateResolved();

        $this->assertSame(
            'https://github.com/vvolv/market-radar',
            $request->validated()['git_repo']
        );
    }

    /** A field with no rule class of its own still reports a code. */
    public function test_a_plain_rule_failure_also_carries_a_code(): void
    {
        $problem = $this->firstProblem(['email' => 'not-an-email']);

        $this->assertSame('email', $problem['field']);
        $this->assertSame('email_email', $problem['code']);
    }

    // ---- `git_token` is checked only when one was sent ------------------

    /**
     * A bad paste and a bad permission both come back from the forge as
     * "Authentication failed", which sends people to check their scopes.
     */
    public function test_a_pasted_authorization_header_is_caught_at_the_request(): void
    {
        $problem = $this->firstProblem([
            'email' => 'ops@example.com',
            'git_repo' => 'https://github.com/vvolv/market-radar.git',
            'git_token' => 'Bearer ghp_' . str_repeat('a', 36),
        ]);

        $this->assertSame('git_token_has_auth_prefix', $problem['code']);
    }

    /** The response is logged, relayed over MCP and kept in transcripts. */
    public function test_a_rejected_token_is_never_echoed_in_the_response(): void
    {
        $token = 'ghp_' . str_repeat('z', 36);
        $e = $this->failureFor([
            'email' => 'ops@example.com',
            'git_repo' => 'http://insecure.example.com/o/r.git',
            'git_token' => $token,
        ]);

        $encoded = json_encode(['errors' => $e->errors(), 'problems' => $e->problems]);

        $this->assertNotFalse($encoded);
        $this->assertStringNotContainsString($token, $encoded);
        $this->assertStringContainsString('git_token_requires_https_repo', $encoded);
    }

    /** A vault reference stands in for the token and is resolved later. */
    public function test_a_vault_reference_passes_the_shape_check(): void
    {
        $request = UserStoreRequest::create('/api/users', 'POST', [
            'email' => 'ops@example.com',
            'git_repo' => 'https://github.com/vvolv/market-radar.git',
            'git_token' => 'vault:9f2c1d7b',
        ]);
        $request->setContainer($this->app);
        $request->validateResolved();

        $this->assertSame('vault:9f2c1d7b', $request->validated()['git_token']);
    }

    /** No token, no token checks -- including the repository pairing. */
    public function test_a_repository_without_a_token_is_not_held_to_the_token_rules(): void
    {
        $request = UserStoreRequest::create('/api/users', 'POST', [
            'email' => 'ops@example.com',
            'git_repo' => 'http://git.internal/o/r.git',
        ]);
        $request->setContainer($this->app);
        $request->validateResolved();

        $this->assertSame('http://git.internal/o/r.git', $request->validated()['git_repo']);
    }

    /** @param array<string, mixed> $payload */
    private function failureFor(array $payload): ProblemException
    {
        $request = UserStoreRequest::create('/api/users', 'POST', $payload);
        $request->setContainer($this->app);

        try {
            $request->validateResolved();
        } catch (ProblemException $e) {
            return $e;
        }

        $this->fail('Expected the request to fail validation.');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function firstProblem(array $payload): array
    {
        $problems = $this->failureFor($payload)->problems;
        $this->assertNotSame([], $problems);

        return $problems[0];
    }
}
