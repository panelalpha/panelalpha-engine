<?php

namespace Tests\Unit\System\Project;

use App\Models\User;
use App\System\Project\SitePasswordProtection;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** The whole nginx gate a protected vhost gets, in both auth modes. */
class SitePasswordGateLocationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
    }

    public function test_the_basic_mode_gate(): void
    {
        config(['env.SITE_PASSWORD_AUTH_MODE' => 'basic']);

        $vars = SitePasswordProtection::nginxTemplateVars($this->protectedUser());

        $this->assertSame('basic', $vars['site_password_auth_mode']);
        $this->assertSame($this->gate('basic', "add_header WWW-Authenticate 'Basic realm=\"Protected\"' always;\n        return 401;"), $vars['site_password_locations']);
    }

    public function test_the_custom_mode_gate(): void
    {
        config(['env.SITE_PASSWORD_AUTH_MODE' => 'custom']);
        $dns = TestNetwork::ENGINE_DNS_PROXY;

        $vars = SitePasswordProtection::nginxTemplateVars($this->protectedUser());

        $this->assertSame("auth_request /_pa_site_auth;\n            error_page 401 = @pa_site_auth_fail;", $vars['site_password_auth_request']);
        $this->assertSame($this->gate('custom', implode("\n", [
            "resolver {$dns} valid=30s;",
            '        set $enginehost core.shared-hosting.palocal;',
            '        proxy_pass http://$enginehost/api/internal/site-password/shop/gate;',
            '        proxy_set_header Host $host;',
            '        proxy_set_header X-Forwarded-Proto $scheme;',
            '        proxy_set_header X-Original-URI $request_uri;',
            '        proxy_set_header X-Real-IP $remote_addr;',
            '        proxy_method GET;',
            '        proxy_pass_request_body off;',
            '        proxy_set_header Content-Length "";',
        ])), $vars['site_password_locations']);
    }

    private function gate(string $mode, string $failBody): string
    {
        $log = '/opt/panelalpha/shared-hosting/webserver-logs/nginx-proxy/site-password.log';
        $dns = TestNetwork::ENGINE_DNS_PROXY;

        return implode("\n", [
            '    set $pa_site_password_basic_failed 0;',
            "    access_log {$log} site_password if=\$pa_site_password_basic_failed;",
            "    access_log {$log} site_password if=\$pa_site_password_form_failed;",
            '    location = /_pa_site_auth {',
            '        internal;',
            "        resolver {$dns} valid=30s;",
            '        set $enginehost core.shared-hosting.palocal;',
            '        proxy_pass http://$enginehost/api/internal/site-password/shop/check;',
            '        proxy_pass_request_body off;',
            '        proxy_set_header Content-Length "";',
            '        proxy_set_header Authorization $http_authorization;',
            '        proxy_set_header Cookie $http_cookie;',
            '        proxy_set_header X-Forwarded-Proto $scheme;',
            "        proxy_set_header X-PA-Auth-Mode {$mode};",
            '    }',
            '    location @pa_site_auth_fail {',
            '        set $pa_site_password_basic_failed $pa_site_password_basic;',
            "        {$failBody}",
            '    }',
            '    location = /_pa_site_password {',
            "        resolver {$dns} valid=30s;",
            '        set $enginehost core.shared-hosting.palocal;',
            '        proxy_pass http://$enginehost/api/internal/site-password/shop/login;',
            '        proxy_set_header Host $host;',
            '        proxy_set_header X-Forwarded-Proto $scheme;',
            '        proxy_set_header X-Forwarded-For $remote_addr;',
            '        proxy_set_header X-Real-IP $remote_addr;',
            '    }',
        ]);
    }

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
}
