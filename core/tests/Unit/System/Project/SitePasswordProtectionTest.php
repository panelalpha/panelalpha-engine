<?php

namespace Tests\Unit\System\Project;

use App\Models\User;
use App\System\Project\SitePasswordProtection;
use Tests\Support\RecordingRunner;
use Tests\TestCase;

class SitePasswordProtectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The stored hash is encrypted and the cookie signed with the app key; a bare run has none.
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
    }

    public function test_password_from_basic_header_ignores_username(): void
    {
        $encoded = base64_encode('anyone:s3cret');
        $this->assertSame(
            's3cret',
            SitePasswordProtection::passwordFromBasicHeader('Basic ' . $encoded)
        );

        $encodedEmptyUser = base64_encode(':s3cret');
        $this->assertSame(
            's3cret',
            SitePasswordProtection::passwordFromBasicHeader('Basic ' . $encodedEmptyUser)
        );

        $this->assertNull(SitePasswordProtection::passwordFromBasicHeader(null));
        $this->assertNull(SitePasswordProtection::passwordFromBasicHeader('Bearer x'));
    }

    public function test_cookie_round_trip_and_version_invalidation(): void
    {
        $user = new User();
        $user->username = 'alice';
        $user->details = [
            'site_password_enabled' => true,
            'site_password_hash' => password_hash('pw', PASSWORD_BCRYPT),
            'site_password_version' => 2,
        ];

        $cookie = SitePasswordProtection::cookieValue($user);
        $this->assertTrue(SitePasswordProtection::cookieIsValid($user, $cookie));

        $user->details = array_merge($user->getDetails(), ['site_password_version' => 3]);
        $this->assertFalse(SitePasswordProtection::cookieIsValid($user, $cookie));
    }

    public function test_verify_password(): void
    {
        $user = new User();
        $user->username = 'bob';
        $user->details = [
            'site_password_enabled' => true,
            'site_password_hash' => password_hash('correct', PASSWORD_BCRYPT),
            'site_password_version' => 1,
        ];

        $this->assertTrue(SitePasswordProtection::verifyPassword($user, 'correct'));
        $this->assertFalse(SitePasswordProtection::verifyPassword($user, 'wrong'));
        $this->assertTrue(SitePasswordProtection::isEnabled($user));
    }

    public function test_auth_mode_defaults_to_custom(): void
    {
        config(['env.SITE_PASSWORD_AUTH_MODE' => 'custom']);
        $this->assertSame('custom', SitePasswordProtection::authMode());

        config(['env.SITE_PASSWORD_AUTH_MODE' => 'basic']);
        $this->assertSame('basic', SitePasswordProtection::authMode());

        config(['env.SITE_PASSWORD_AUTH_MODE' => 'nope']);
        $this->assertSame('custom', SitePasswordProtection::authMode());
    }

    public function test_reclaim_framework_cache_as_root_chowns_www_data(): void
    {
        $cache = storage_path('framework/cache');
        if (!is_dir($cache)) {
            mkdir($cache, 0755, true);
        }

        $runner = new RecordingRunner();
        $runner->exitCode = 0;

        SitePasswordProtection::reclaimFrameworkCacheOwnership(0, $runner);

        $this->assertCount(1, $runner->commands);
        $this->assertSame(
            ['sudo', 'chown', '-R', 'www-data:www-data', $cache],
            $runner->commands[0]
        );
    }

    public function test_reclaim_framework_cache_as_non_root_is_noop(): void
    {
        $runner = new RecordingRunner();

        SitePasswordProtection::reclaimFrameworkCacheOwnership(33, $runner);

        $this->assertSame([], $runner->commands);
    }
}
