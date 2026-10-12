<?php

namespace Tests\Unit\Http;

use App\Http\Requests\SourceRefsRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The repository and token rules POST /source/refs shares with create and
 * inspect, pointed at this endpoint's field names.
 */
class SourceRefsRequestTest extends TestCase
{
    public function test_a_repository_is_required(): void
    {
        $this->assertArrayHasKey('repository', $this->errors([]));
    }

    public function test_a_bare_owner_repo_is_refused(): void
    {
        $this->assertArrayHasKey('repository', $this->errors(['repository' => 'owner/repo']));
    }

    public function test_a_repository_url_is_accepted(): void
    {
        $this->assertArrayNotHasKey('repository', $this->errors([
            'repository' => 'https://github.com/owner/repo.git',
        ]));
    }

    public function test_a_limit_above_the_range_is_refused(): void
    {
        $this->assertArrayHasKey('limit', $this->errors([
            'repository' => 'https://github.com/owner/repo.git',
            'limit' => 0,
        ]));
        $this->assertArrayHasKey('limit', $this->errors([
            'repository' => 'https://github.com/owner/repo.git',
            'limit' => 1001,
        ]));
    }

    public function test_a_limit_in_the_range_is_accepted(): void
    {
        $this->assertArrayNotHasKey('limit', $this->errors([
            'repository' => 'https://github.com/owner/repo.git',
            'limit' => 1,
        ]));
    }

    public function test_a_token_requires_an_https_repository(): void
    {
        $errors = $this->errors([
            'repository' => 'http://example.test/owner/repo.git',
            'git_token' => 'ghp_' . str_repeat('a', 36),
        ]);

        $this->assertArrayHasKey('git_token', $errors);
    }

    public function test_a_vault_reference_is_accepted_for_a_token(): void
    {
        $this->assertArrayNotHasKey('git_token', $this->errors([
            'repository' => 'https://github.com/owner/repo.git',
            'git_token' => 'vault:7',
        ]));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function errors(array $payload): array
    {
        $request = new SourceRefsRequest();
        $request->setContainer($this->app);

        return Validator::make($payload, $request->rules())->errors()->toArray();
    }
}
