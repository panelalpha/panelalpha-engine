<?php

namespace Tests\Unit\Vault;

use App\Http\Requests\DomainInstallSslCertRequest;
use App\Http\Requests\FtpAccountStoreRequest;
use App\Http\Requests\Git\GitUpdateCredentialsRequest;
use App\Http\Requests\MysqlUserStoreRequest;
use App\Http\Requests\ProjectPasswordSetRequest;
use App\Http\Requests\SftpAccountUpdateRequest;
use App\Models\SecretVaultEntry;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * A `vault:<key>` is resolved for every secret field, not only git_token and
 * env_vars. It used to be stored as the literal string `vault:<key>` -- long
 * enough to pass a min:8 rule -- so the credential silently became that text.
 */
class VaultSecretFieldTest extends VaultTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('sqlite')->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('domain')->nullable();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });
        User::query()->create(['username' => 'shop']);
    }

    protected function tearDown(): void
    {
        Schema::connection('sqlite')->dropIfExists('users');
        parent::tearDown();
    }

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
            'certificate key' => [DomainInstallSslCertRequest::class, 'key'],
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

    public function test_a_project_entry_is_assigned_to_the_project_that_reads_it(): void
    {
        [$entry] = $this->projectEntry('password', 'the-site-secret');

        $this->prepared(ProjectPasswordSetRequest::class, ['password' => $entry->reference()]);

        $this->assertSame('shop', $entry->refresh()->project);
    }

    /**
     * A reference sent to a project that does not exist answers 404 later; it
     * must not leave the entry owned by that name, refused to the project it
     * was meant for.
     */
    public function test_a_project_that_does_not_exist_does_not_claim_the_entry(): void
    {
        [$entry] = $this->projectEntry('password', 'the-site-secret');

        $this->prepared(ProjectPasswordSetRequest::class, ['password' => $entry->reference()], 'typo');
        $this->assertNull($entry->refresh()->project);

        $request = $this->prepared(ProjectPasswordSetRequest::class, ['password' => $entry->reference()]);
        $this->assertSame('the-site-secret', $request->input('password'));
        $this->assertSame('shop', $entry->refresh()->project);
    }

    public function test_a_reference_of_the_wrong_type_is_refused(): void
    {
        [$entry] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_x');

        $this->expectException(ValidationException::class);

        $this->prepared(MysqlUserStoreRequest::class, ['password' => $entry->reference()]);
    }
}
