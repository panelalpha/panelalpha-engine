<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\PmaSso;
use App\Models\MysqlSsoToken;
use App\Models\SftpAccount;
use Carbon\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A refusal about one input names that input. Without a key, every one of
 * these answered under field 0 with code 0_invalid, so a client could not tell
 * which field it had got wrong.
 */
class ValidationFieldKeysTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware([Authenticate::class, PmaSso::class]);
    }

    public function test_an_sftp_username_outside_the_project_prefix_is_refused_under_username(): void
    {
        $this->makeUser('alice');

        $this->assertField('username', 'Username must start with alice_', $this->createSftp([
            'username' => 'other_backup',
            'auth_method' => 'password',
            'password' => 'secret-pass',
        ]));
    }

    public function test_a_taken_sftp_username_is_refused_under_username(): void
    {
        $alice = $this->makeUser('alice');
        SftpAccount::create([
            'user_id' => $alice->id,
            'username' => 'alice_backup',
            'auth_method' => 'password',
            'password' => 'hashed',
        ]);

        $this->assertField('username', 'Username not available', $this->createSftp([
            'username' => 'alice_backup',
            'auth_method' => 'password',
            'password' => 'secret-pass',
        ]));
    }

    public function test_an_unknown_sftp_auth_method_is_refused_under_auth_method(): void
    {
        $this->makeUser('alice');

        $this->assertField('auth_method', 'Invalid auth_method', $this->createSftp([
            'username' => 'alice_backup',
            'auth_method' => 'keyboard',
            'password' => 'secret-pass',
        ]));
    }

    public function test_a_missing_sftp_password_is_refused_under_password(): void
    {
        $this->makeUser('alice');

        $this->assertField('password', 'Password is required', $this->createSftp([
            'username' => 'alice_backup',
            'auth_method' => 'password',
        ]));
    }

    public function test_a_missing_sftp_public_key_is_refused_under_public_key(): void
    {
        $this->makeUser('alice');

        $this->assertField('public_key', 'Public key is required', $this->createSftp([
            'username' => 'alice_backup',
            'auth_method' => 'public_key',
        ]));
    }

    public function test_an_unknown_phpmyadmin_token_is_refused_under_token(): void
    {
        $this->assertField('token', 'Invalid token', $this->redeemSso('never-issued'));
    }

    public function test_an_expired_phpmyadmin_token_is_refused_under_token(): void
    {
        $alice = $this->makeUser('alice');
        MysqlSsoToken::create([
            'user_id' => $alice->id,
            'token' => 'stale',
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        $this->assertField('token', 'Invalid token expired', $this->redeemSso('stale'));
    }

    public function test_an_ssl_certificate_that_does_not_match_its_key_is_refused_under_cert(): void
    {
        $alice = $this->makeUser('alice');
        $this->makeMainDomain($alice, 'shop.test');

        $this->assertField('cert', 'Invalid certificate/key', $this->putJson(
            '/api/projects/alice/domains/shop.test/install-ssl-cert',
            ['cert' => 'not-a-pem', 'key' => 'not-a-pem']
        ));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createSftp(array $body): TestResponse
    {
        return $this->postJson('/api/projects/alice/sftp-accounts', $body);
    }

    private function redeemSso(string $token): TestResponse
    {
        return $this->putJson('/api/mysql/phpmyadmin-sso-token', ['token' => $token]);
    }

    private function assertField(string $field, string $message, TestResponse $response): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('errors.' . $field . '.0', $message);
        $response->assertJsonPath('problems.0.field', $field);
    }
}
