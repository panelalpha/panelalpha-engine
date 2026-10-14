<?php

namespace Tests\Unit\System\Project;

use App\Models\User;
use App\System\Project\SitePasswordProtection;
use Illuminate\Support\Facades\Blade;
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

    private const SHARED_LOG = '/opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/site-password.log';

    private function protectedUser(): User
    {
        $user = new User();
        $user->username = 'shop';
        $user->details = [
            'site_password_enabled' => true,
            'site_password_hash' => password_hash('pw', PASSWORD_BCRYPT),
            'site_password_version' => 1,
        ];

        return $user;
    }

    private function locationBody(string $locations, string $name): string
    {
        $this->assertSame(1, preg_match('/location ' . preg_quote($name, '/') . ' \{(.*?)\n    \}/s', $locations, $m), $name);

        return $m[1];
    }

    /**
     * A failed attempt is a Basic header that ended in the auth-failure location, or a
     * form POST answered 401; a first visit, the form page and the app's own 401s are not.
     */
    public function test_only_failed_passwords_are_logged_to_the_shared_file(): void
    {
        foreach (['basic', 'custom'] as $mode) {
            config(['env.SITE_PASSWORD_AUTH_MODE' => $mode]);
            $locations = SitePasswordProtection::nginxTemplateVars($this->protectedUser())['site_password_locations'];

            $this->assertStringContainsString('set $pa_site_password_basic_failed 0;', $locations, $mode);
            $this->assertStringContainsString(
                'access_log ' . self::SHARED_LOG . ' site_password if=$pa_site_password_basic_failed;',
                $locations,
                $mode
            );
            $this->assertStringContainsString(
                'access_log ' . self::SHARED_LOG . ' site_password if=$pa_site_password_form_failed;',
                $locations,
                $mode
            );
            // Raised only where the auth check's failure ends, from the Basic-header map.
            $this->assertSame(2, substr_count($locations, 'set $pa_site_password_basic_failed '), $mode);
            $this->assertStringContainsString(
                'set $pa_site_password_basic_failed $pa_site_password_basic;',
                $this->locationBody($locations, '@pa_site_auth_fail'),
                $mode
            );
        }
    }

    public function test_proxy_main_configs_define_the_failure_format_and_maps(): void
    {
        $root = dirname(base_path());
        foreach (['templates/webserver-nginx-proxy.blade.php', 'templates/webserver-config/nginx-proxy/nginx.conf'] as $file) {
            $conf = (string) file_get_contents("{$root}/{$file}");
            $this->assertStringContainsString(
                "log_format site_password '\$remote_addr [\$time_local] \"\$host\" site-password \$status \"\$request_method \$uri\"';",
                $conf,
                $file
            );
            $this->assertMatchesRegularExpression(
                '/map \$http_authorization \$pa_site_password_basic \{\s*"~\*\^basic\\\\s" 1;\s*default 0;\s*\}/',
                $conf,
                $file
            );
            $this->assertMatchesRegularExpression(
                '/map "\$request_method \$status \$uri" \$pa_site_password_form_failed \{\s*"POST 401 \/_pa_site_password" 1;\s*default 0;\s*\}/',
                $conf,
                $file
            );
        }

        $rotate = (string) file_get_contents("{$root}/templates/config/logrotate/nginx-proxy.conf");
        $this->assertStringContainsString(self::SHARED_LOG . "\n", $rotate);
    }

    public function test_every_server_block_of_a_protected_vhost_logs_failures(): void
    {
        config(['env.SITE_PASSWORD_AUTH_MODE' => 'basic']);
        $vars = [
            'user' => 'shop',
            'domain' => 'shop.example.test',
            'aliases' => [],
            'ips_v4' => [],
            'ips_v6' => [],
            'suspended' => false,
            'redirect_url' => null,
            'force_https_redirect' => false,
            'relative_document_root' => '/shop.example.test/public_html',
            'app_port' => 8000,
            'app_ssl_port' => 8000,
            'proxy_http' => ['host' => 'shop', 'port' => 8000, 'protocol' => 'http'],
            'proxy_https' => ['host' => 'shop', 'port' => 8000, 'protocol' => 'http'],
            'proxy_extra' => [['listen_port' => 8443, 'host' => 'shop', 'port' => 9000, 'protocol' => 'http']],
            'ssl_enabled' => true,
            'ssl_cert_pem_file' => '/tmp/shop.pem',
            'ssl_cert_key_file' => '/tmp/shop.key',
            'trusted_fronts' => [],
            'http_acme_challenges_enabled' => false,
            ...SitePasswordProtection::nginxTemplateVars($this->protectedUser()),
        ];
        $root = dirname(base_path()) . '/templates/';
        $vhost = Blade::render((string) file_get_contents($root . 'dind/virtualHost-nginx-proxy.blade.php'), $vars);

        $this->assertSame(6, substr_count($vhost, 'access_log ' . self::SHARED_LOG . ' site_password if='));
        // The site's own logs are left as they were.
        $this->assertSame(3, substr_count($vhost, 'shop.example.test/access.log combined;'));

        $vars['site_password_enabled'] = false;
        $open = Blade::render((string) file_get_contents($root . 'dind/virtualHost-nginx-proxy.blade.php'), $vars);
        $this->assertStringNotContainsString('site-password.log', $open);
    }
}
