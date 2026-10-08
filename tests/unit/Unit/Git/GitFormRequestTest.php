<?php

namespace Tests\Unit\Git;

use App\Http\Requests\Git\GitChangeBranchRequest;
use App\Http\Requests\Git\GitConnectRequest;
use App\Http\Requests\Git\GitPathRequest;
use App\Http\Requests\Git\GitPullRequest;
use App\Http\Requests\Git\GitStatusRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use ReflectionMethod;
use Tests\TestCase;

class GitFormRequestTest extends TestCase
{
    /** @param array<string, mixed> $payload */
    private function fails(array $payload, string $requestClass): bool
    {
        /** @var FormRequest $request */
        $request = new $requestClass();
        $validator = Validator::make($payload, $request->rules());
        if (method_exists($request, 'withValidator')) {
            $request->withValidator($validator);
        }

        return $validator->fails();
    }

    /** @param array<string, mixed> $payload */
    private function failsAfterPrepare(array $payload, string $requestClass): bool
    {
        $base = Request::create('/git/status', 'GET', $payload);
        /** @var FormRequest $request */
        $request = $requestClass::createFrom($base);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));

        $prepare = new ReflectionMethod($request, 'prepareForValidation');
        $prepare->invoke($request);

        $validator = Validator::make($request->all(), $request->rules());

        return $validator->fails();
    }

    public function test_missing_path_passes(): void
    {
        $this->assertFalse($this->fails([], GitPathRequest::class));
        $this->assertFalse($this->fails(['branch' => 'main'], GitPathRequest::class));
        $this->assertFalse($this->fails(['path' => ''], GitPathRequest::class));
        $this->assertFalse($this->fails(['path' => null], GitPathRequest::class));
        $this->assertFalse($this->fails(['fetch' => true], GitStatusRequest::class));
    }

    public function test_invalid_pull_strategy_fails(): void
    {
        $this->assertTrue($this->fails([
            'path' => 'public_html',
            'strategy' => 'rebase',
        ], GitPullRequest::class));
    }

    public function test_ssh_auth_type_fails_on_connect(): void
    {
        $this->assertTrue($this->fails([
            'path' => 'public_html',
            'repo_url' => 'https://github.com/example/repo.git',
            'branch' => 'main',
            'auth_type' => 'ssh',
        ], GitConnectRequest::class));
    }

    public function test_force_strategy_passes(): void
    {
        $this->assertFalse($this->fails([
            'path' => 'public_html',
            'strategy' => 'force',
        ], GitPullRequest::class));
    }

    public function test_ff_strategy_passes(): void
    {
        $this->assertFalse($this->fails([
            'path' => 'public_html',
            'strategy' => 'ff',
        ], GitPullRequest::class));
    }

    public function test_invalid_branch_name_fails_on_change_branch(): void
    {
        $this->assertTrue($this->fails([
            'branch' => '--output=/tmp/x',
        ], GitChangeBranchRequest::class));
    }

    public function test_fetch_query_string_true_passes_after_prepare(): void
    {
        $this->assertFalse($this->failsAfterPrepare([
            'path' => 'public_html',
            'fetch' => 'true',
        ], GitStatusRequest::class));
    }

    public function test_fetch_query_string_false_passes_after_prepare(): void
    {
        $this->assertFalse($this->failsAfterPrepare([
            'fetch' => 'false',
        ], GitStatusRequest::class));
    }

    public function test_fetch_query_string_banana_fails_after_prepare(): void
    {
        $this->assertTrue($this->failsAfterPrepare([
            'fetch' => 'banana',
        ], GitStatusRequest::class));
    }
}
