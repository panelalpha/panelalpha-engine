<?php

namespace Tests\Unit\System\Project\Dind;

use App\Models\User;
use App\System\Project\Dind\AppDatabase;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * The app's database password is generated once and must never be rotated
 * behind the app's back: its own config file still holds the first one.
 */
class AppDatabasePasswordTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useAppKey(str_repeat('k', 32));
    }

    public function test_stored_password_is_returned_decrypted_without_a_write(): void
    {
        $user = $this->user();
        $user->details = [AppDatabase::PASSWORD_DETAIL => 'db-secret'];

        $this->assertSame('db-secret', $this->password($user));
        $this->assertSame(0, $user->saves);
    }

    public function test_legacy_plaintext_password_is_still_used(): void
    {
        $user = $this->user();
        $user->setRawAttributes(['details' => json_encode([AppDatabase::PASSWORD_DETAIL => 'legacy-db'])]);

        $this->assertSame('legacy-db', $this->password($user));
        $this->assertSame(0, $user->saves);
    }

    public function test_missing_password_is_generated_once_and_stored_encrypted(): void
    {
        $user = $this->user();
        $user->details = ['mysql_prefix' => 'alice_'];

        $password = $this->password($user);

        $this->assertSame(24, strlen($password));
        $this->assertSame(1, $user->saves);
        $this->assertStringNotContainsString($password, (string) $user->getAttributes()['details']);
        $this->assertSame($password, $this->password($user));
        $this->assertSame(1, $user->saves);
    }

    public function test_undecryptable_password_fails_instead_of_regenerating(): void
    {
        $user = $this->user();
        $user->details = [AppDatabase::PASSWORD_DETAIL => 'db-secret'];
        $before = $user->getAttributes()['details'];
        $this->useAppKey(str_repeat('x', 32));

        try {
            $this->password($user);
            $this->fail('An unreadable password must not be replaced');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot be decoded', $e->getMessage());
            $this->assertStringNotContainsString('APP_KEY', $e->getMessage());
        }

        $this->assertSame(0, $user->saves);
        $this->assertSame($before, $user->getAttributes()['details']);
    }

    private function password(User $user): string
    {
        $method = new \ReflectionMethod(AppDatabase::class, 'password');

        return $method->invoke(null, $user);
    }

    /** A user whose save() only counts, so no database is needed. */
    private function user(): User
    {
        return new class extends User {
            public int $saves = 0;

            public function save(array $options = []): bool
            {
                $this->saves++;

                return true;
            }
        };
    }

    private function useAppKey(string $key): void
    {
        config(['app.key' => 'base64:' . base64_encode($key)]);
        $this->app->forgetInstance('encrypter');
        Facade::clearResolvedInstances();
    }
}
