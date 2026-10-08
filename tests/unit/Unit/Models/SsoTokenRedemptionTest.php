<?php

namespace Tests\Unit\Models;

use App\Models\AppSsoToken;
use App\Models\MysqlSsoToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SSO tokens are single-use. Redemption used to select the row and then mark or
 * delete it, so two concurrent requests could both pass the select and both get
 * the app session cookie or a set of MySQL credentials.
 */
class SsoTokenRedemptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        // The real migrations, so the columns (and cookie_value's NOT NULL) are production's.
        (require database_path('migrations/2026_05_07_000000_create_app_sso_tokens_table.php'))->up();
        (require database_path('migrations/2024_02_08_131428_create_mysql_sso_tokens_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::connection('sqlite')->dropIfExists('app_sso_tokens');
        Schema::connection('sqlite')->dropIfExists('mysql_sso_tokens');
        parent::tearDown();
    }

    public function test_an_app_token_redeems_once_and_the_cookie_leaves_the_table(): void
    {
        $this->appToken('tok');

        $first = AppSsoToken::redeem('tok', 'shop');

        $this->assertNotNull($first);
        $this->assertSame('session-value', $first->cookie_value);

        $row = AppSsoToken::firstOrFail();
        $this->assertNotNull($row->used_at);
        $this->assertSame('', $row->cookie_value);

        $this->assertNull(AppSsoToken::redeem('tok', 'shop'));
    }

    public function test_the_loser_of_a_concurrent_app_redemption_gets_nothing(): void
    {
        $this->appToken('tok');

        // Another request claims the token between this one's select and update.
        $this->afterFirstSelect(fn () => DB::table('app_sso_tokens')->update(['used_at' => now()]));

        $this->assertNull(AppSsoToken::redeem('tok', 'shop'));
    }

    public function test_an_expired_or_foreign_app_token_does_not_redeem(): void
    {
        $this->appToken('old', expiresAt: now()->subSecond());
        $this->appToken('tok');

        $this->assertNull(AppSsoToken::redeem('old', 'shop'));
        $this->assertNull(AppSsoToken::redeem('tok', 'other'));
        $this->assertNotNull(AppSsoToken::redeem('tok', 'shop'));
    }

    public function test_spent_and_expired_tokens_are_pruned(): void
    {
        $this->appToken('used');
        AppSsoToken::redeem('used', 'shop');
        $this->appToken('expired', expiresAt: now()->subMinute());
        $this->appToken('live');
        $this->mysqlToken('expired', now()->subMinute());
        $this->mysqlToken('live', now()->addMinutes(5));

        $this->artisan('model:prune', ['--model' => [AppSsoToken::class, MysqlSsoToken::class]])
            ->assertSuccessful();

        $this->assertSame(['live'], AppSsoToken::pluck('token')->all());
        $this->assertSame(['live'], MysqlSsoToken::pluck('token')->all());
    }

    public function test_a_mysql_token_is_claimed_once(): void
    {
        $this->mysqlToken('tok', now()->addMinutes(5));

        $this->assertNotNull(MysqlSsoToken::claim('tok'));
        $this->assertSame(0, MysqlSsoToken::count());
        $this->assertNull(MysqlSsoToken::claim('tok'));
    }

    public function test_the_loser_of_a_concurrent_mysql_claim_gets_nothing(): void
    {
        $this->mysqlToken('tok', now()->addMinutes(5));

        $this->afterFirstSelect(fn () => DB::table('mysql_sso_tokens')->delete());

        $this->assertNull(MysqlSsoToken::claim('tok'));
    }

    public function test_an_expired_mysql_token_is_consumed_and_reported_expired(): void
    {
        $this->mysqlToken('tok', now()->subSecond());

        $claimed = MysqlSsoToken::claim('tok');

        $this->assertNotNull($claimed);
        $this->assertTrue($claimed->expired());
        $this->assertSame(0, MysqlSsoToken::count());
    }

    private function appToken(string $token, ?\DateTimeInterface $expiresAt = null): void
    {
        AppSsoToken::create([
            'token' => $token,
            'username' => 'shop',
            'cookie_name' => 'wordpress_logged_in',
            'cookie_value' => 'session-value',
            'redirect' => '/wp-admin/',
            'expires_at' => $expiresAt ?? now()->addMinute(),
        ]);
    }

    private function mysqlToken(string $token, \DateTimeInterface $expiresAt): void
    {
        MysqlSsoToken::create(['user_id' => 1, 'token' => $token, 'expires_at' => $expiresAt]);
    }

    /** Run $race once, right after the first SELECT the code under test issues. */
    private function afterFirstSelect(callable $race): void
    {
        $fired = false;

        DB::listen(function ($query) use (&$fired, $race): void {
            if ($fired || !str_starts_with(strtolower($query->sql), 'select')) {
                return;
            }
            $fired = true;
            $race();
        });
    }
}
