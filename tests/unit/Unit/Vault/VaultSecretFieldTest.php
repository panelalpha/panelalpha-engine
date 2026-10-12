<?php

namespace Tests\Unit\Vault;

use App\Http\Requests\FtpAccountStoreRequest;
use App\Http\Requests\Git\GitUpdateCredentialsRequest;
use App\Http\Requests\MysqlUserStoreRequest;
use App\Http\Requests\ProjectPasswordSetRequest;
use App\Http\Requests\SftpAccountUpdateRequest;
use App\Models\SecretVaultEntry;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * A `vault:<id>` is resolved for every secret field, not only git_token and
 * env_vars. It used to be stored as the literal string `vault:<id>` -- long
 * enough to pass a min:8 rule -- so the credential silently became that text.
 */
class VaultSecretFieldTest extends VaultTestCase
{
    /** @param class-string $class */
    private function prepared(string $class, array $input, string $project = 'shop'): object
    {
        $request = $class::create('/api/projects/' . $project . '/x', 'POST', $input);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));

        $route = new Route(['POST'], '/api/projects/{username}/x', []);
        $route->parameters = ['username' => $project];
        $request->setRouteResolver(fn () => $route);

        $method = new ReflectionMethod($class, 'prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);

        return $request;
    }

    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function passwordFields(): array
    {
        return [
            'mysql user create' => [MysqlUserStoreRequest::class, 'password'],
            'ftp account create' => [FtpAccountStoreRequest::class, 'password'],
            'sftp account update' => [SftpAccountUpdateRequest::class, 'password'],
            'site password set' => [ProjectPasswordSetRequest::class, 'password'],
            'git token' => [GitUpdateCredentialsRequest::class, 'token'],
        ];
    }

    /** @param class-string $class */
    #[DataProvider('passwordFields')]
    public function test_a_vault_reference_is_read_in_place_of_the_secret(string $class, string $field): void
    {
        [$entry] = $this->globalEntry($field, 'the-real-secret');

        $request = $this->prepared($class, [$field => $entry->reference()]);

        $this->assertSame('the-real-secret', $request->input($field));
    }

    /** @param class-string $class */
    #[DataProvider('passwordFields')]
    public function test_a_literal_passes_through_untouched(string $class, string $field): void
    {
        $request = $this->prepared($class, [$field => 'just-a-literal']);

        $this->assertSame('just-a-literal', $request->input($field));
    }

    /** @param class-string $class */
    #[DataProvider('passwordFields')]
    public function test_an_unknown_reference_is_refused_not_stored_literally(string $class, string $field): void
    {
        $this->expectException(ValidationException::class);

        $this->prepared($class, [$field => 'vault:999999']);
    }

    public function test_a_reference_of_the_wrong_type_is_refused(): void
    {
        [$entry] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_x');

        $this->expectException(ValidationException::class);

        $this->prepared(MysqlUserStoreRequest::class, ['password' => $entry->reference()]);
    }
}
