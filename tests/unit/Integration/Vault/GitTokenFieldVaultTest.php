<?php

namespace Tests\Integration\Vault;

use App\Http\Requests\Git\GitConnectRequest;
use App\Http\Requests\Git\GitUpdateCredentialsRequest;
use App\Models\SecretVaultEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;

/** The git routes' `token` field reads a `git_token` entry as well as one made for the field itself. */
class GitTokenFieldVaultTest extends VaultTestCase
{
    public function test_a_git_token_entry_is_read_by_both_git_credential_requests(): void
    {
        [$entry] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_from_vault');

        foreach ($this->gitRequests() as $class => $input) {
            $request = $this->validated($class, $input + ['token' => $entry->reference()]);

            $this->assertSame('ghp_from_vault', $request->input('token'), $class);
        }
    }

    public function test_an_entry_made_for_the_token_field_is_still_read(): void
    {
        [$entry] = $this->globalEntry('token', 'ghp_token_typed');

        $request = $this->validated(GitUpdateCredentialsRequest::class, ['token' => $entry->reference()]);

        $this->assertSame('ghp_token_typed', $request->input('token'));
    }

    public function test_an_entry_of_another_type_is_refused(): void
    {
        [$entry] = $this->globalEntry(SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN, 'cf_secret');

        try {
            $this->validated(GitUpdateCredentialsRequest::class, ['token' => $entry->reference()]);
            $this->fail('A Cloudflare entry must not be spent as a git token.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString("not a 'token' or 'git_token'", $e->errors()['token'][0]);
        }
    }

    /** @return array<class-string<FormRequest>, array<string, string>> */
    private function gitRequests(): array
    {
        return [
            GitUpdateCredentialsRequest::class => [],
            GitConnectRequest::class => ['repo_url' => 'https://github.com/acme/app.git', 'branch' => 'main'],
        ];
    }

    /**
     * @param  class-string<FormRequest>  $class
     * @param  array<string, string>  $input
     */
    private function validated(string $class, array $input): FormRequest
    {
        $request = $class::create('/api/projects/shop/git', 'POST', $input);
        $request->setContainer($this->app)->setRedirector($this->app->make(Redirector::class));
        $request->validateResolved();

        return $request;
    }
}
