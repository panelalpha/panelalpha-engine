<?php

namespace Tests\Unit\Vault;

use App\Http\Controllers\User\AppUserController;
use App\Http\Requests\DomainInstallSslCertRequest;
use App\Http\Requests\FtpAccountStoreRequest;
use App\Http\Requests\Git\GitUpdateCredentialsRequest;
use App\Http\Requests\MysqlUserStoreRequest;
use App\Http\Requests\ProjectPasswordSetRequest;
use App\Http\Requests\SftpAccountUpdateRequest;
use App\Models\SecretVaultEntry;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
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
     * A reference sent to a project that does not exist is the route's 404 before
     * the vault is read: the entry is neither owned by that name, refused to the
     * project it was meant for, nor counted as used.
     *
     * @param  class-string  $class
     */
    #[DataProvider('passwordFields')]
    public function test_a_project_that_does_not_exist_is_not_found_before_the_vault_is_read(string $class, string $field): void
    {
        [$entry] = $this->projectEntry($field, 'the-secret');

        try {
            $this->prepared($class, [$field => $entry->reference()], 'typo');
            $this->fail('A project that does not exist was not refused.');
        } catch (HttpResponseException $e) {
            $this->assertSame(404, $e->getResponse()->getStatusCode());
            $this->assertSame(['message' => 'Project not found'], $e->getResponse()->getData(true));
        }
        $this->assertNull($entry->refresh()->project);
        $this->assertSame(0, $entry->use_count);
        $this->assertNull($entry->last_used_at);

        $request = $this->prepared($class, [$field => $entry->reference()]);
        $this->assertSame('the-secret', $request->input($field));
        $this->assertSame('shop', $entry->refresh()->project);
    }

    /** Without a reference there is nothing to read: validation and the controller's own 404 run as before. */
    public function test_a_literal_sent_to_a_project_that_does_not_exist_is_left_to_the_route(): void
    {
        $request = $this->prepared(ProjectPasswordSetRequest::class, ['password' => 'just-a-literal'], 'typo');

        $this->assertSame('just-a-literal', $request->input('password'));
    }

    /**
     * The app-user endpoints read their secret in the controller: a project that
     * does not exist is a 404 before the vault is read, and the entry is still
     * the intended project's to claim.
     */
    public function test_an_app_user_call_to_a_project_that_does_not_exist_does_not_claim_the_entry(): void
    {
        [$entry] = $this->projectEntry('password', 'the-app-secret');
        $user = ['login' => 'x', 'email' => 'x@example.com', 'role' => 'admin'];
        $calls = [
            'create' => fn (AppUserController $c, string $p, Request $r) => $c->store($p, $r),
            'password reset' => fn (AppUserController $c, string $p, Request $r) => $c->resetPassword($p, '1', $r),
        ];

        foreach ($calls as $label => $call) {
            $this->assertSame(404, $this->appUserStatus($call, 'typo', $user + ['password' => $entry->reference()]), $label);
            $this->assertNull($entry->refresh()->project, $label);
            $this->assertSame(0, $entry->use_count, "{$label}: the vault was not read");
        }

        $this->appUserStatus($calls['password reset'], 'shop', ['password' => $entry->reference()]);
        $this->assertSame('shop', $entry->refresh()->project);
    }

    public function test_an_app_install_on_a_project_that_does_not_exist_does_not_claim_the_entry(): void
    {
        [$entry] = $this->projectEntry('admin_password', 'the-admin-secret');
        $install = fn (AppUserController $c, string $p, Request $r) => $c->install($p, $r);
        $input = [
            'url' => 'https://shop.example.com',
            'title' => 'Shop',
            'admin_user' => 'admin',
            'admin_email' => 'admin@example.com',
            'admin_password' => $entry->reference(),
        ];

        $this->assertSame(404, $this->appUserStatus($install, 'typo', $input));
        $this->assertNull($entry->refresh()->project);

        $this->appUserStatus($install, 'shop', $input);
        $this->assertSame('shop', $entry->refresh()->project);
    }

    /**
     * The status a call answers with; `shop` is no container project, so it is
     * refused once its secret has been read.
     *
     * @param  callable(AppUserController, string, Request): mixed  $call
     */
    private function appUserStatus(callable $call, string $project, array $input): int
    {
        try {
            $call(new AppUserController(), $project, Request::create('/api/projects/' . $project . '/app/users', 'POST', $input));
        } catch (HttpResponseException $e) {
            return $e->getResponse()->getStatusCode();
        }

        $this->fail('The call was not refused.');
    }

    public function test_a_reference_of_the_wrong_type_is_refused(): void
    {
        [$entry] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_x');

        $this->expectException(ValidationException::class);

        $this->prepared(MysqlUserStoreRequest::class, ['password' => $entry->reference()]);
    }
}
