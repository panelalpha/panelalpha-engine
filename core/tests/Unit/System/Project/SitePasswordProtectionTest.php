<?php

namespace Tests\Unit\System\Project;

use App\Models\User;
use App\System\Project\SitePasswordProtection;
use Tests\TestCase;

class SitePasswordProtectionTest extends TestCase
{
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
}
