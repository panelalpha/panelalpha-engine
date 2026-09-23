<?php

namespace App\System\Project;

use App\Integrations\Tunnels\Cloudflare;
use App\Models\Tunnel;
use App\Models\User;
use App\System;
use Illuminate\Support\Facades\Log;

/**
 * Per-project HTTP password gate for nginx-proxy vhosts.
 *
 * Password is stored (encrypted) on User.details; vhosts call an internal
 * check endpoint that accepts Authorization: Basic (any username) or a
 * browser-session cookie. Host-wide UX mode is SITE_PASSWORD_AUTH_MODE.
 */
class SitePasswordProtection
{
    public const string COOKIE_NAME = 'pa_site_auth';

    public const string DETAIL_ENABLED = 'site_password_enabled';

    public const string DETAIL_HASH = 'site_password_hash';

    public const string DETAIL_VERSION = 'site_password_version';

    public const string MODE_CUSTOM = 'custom';

    public const string MODE_BASIC = 'basic';

    public static function authMode(): string
    {
        $mode = strtolower(trim((string) config('env.SITE_PASSWORD_AUTH_MODE', self::MODE_CUSTOM)));
        if ($mode === '') {
            $mode = strtolower(trim((string) env('SITE_PASSWORD_AUTH_MODE', self::MODE_CUSTOM)));
        }

        return $mode === self::MODE_BASIC ? self::MODE_BASIC : self::MODE_CUSTOM;
    }

    public static function isEnabled(User $user): bool
    {
        $details = $user->getDetails();

        return !empty($details[self::DETAIL_ENABLED])
            && is_string($details[self::DETAIL_HASH] ?? null)
            && $details[self::DETAIL_HASH] !== '';
    }

    public static function passwordHash(User $user): ?string
    {
        $hash = $user->getDetails()[self::DETAIL_HASH] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    public static function version(User $user): int
    {
        $version = $user->getDetails()[self::DETAIL_VERSION] ?? 1;

        return is_numeric($version) ? (int) $version : 1;
    }

    /**
     * Set or replace the project site password and rebuild nginx-proxy vhosts.
     */
    public static function set(User $user, string $password): void
    {
        $password = self::normalizePassword($password);
        if ($password === '') {
            throw new \InvalidArgumentException('Password must not be empty.');
        }

        $details = $user->getDetails();
        $version = self::version($user);
        if (self::isEnabled($user)) {
            $version++;
        }

        $user->setDetails([
            self::DETAIL_ENABLED => true,
            self::DETAIL_HASH => password_hash($password, PASSWORD_BCRYPT),
            self::DETAIL_VERSION => $version,
        ]);
        $user->save();

        self::applyWebserver($user);
        self::syncCloudflareOrigins($user);
    }

    public static function unset(User $user): void
    {
        if (!self::isEnabled($user)) {
            return;
        }

        $details = $user->getDetails();
        unset(
            $details[self::DETAIL_ENABLED],
            $details[self::DETAIL_HASH],
            $details[self::DETAIL_VERSION],
        );
        // setDetails merges; clear by writing nulls then stripping on next read is messy —
        // overwrite the attribute with the filtered array via direct assignment path.
        $user->details = $details;
        $user->save();

        self::applyWebserver($user);
        self::syncCloudflareOrigins($user);
    }

    public static function verifyPassword(User $user, string $password): bool
    {
        $hash = self::passwordHash($user);
        if ($hash === null) {
            return false;
        }

        return password_verify($password, $hash);
    }

    /**
     * Extract password from an Authorization: Basic header (username ignored).
     */
    public static function passwordFromBasicHeader(?string $authorization): ?string
    {
        if ($authorization === null || $authorization === '') {
            return null;
        }
        if (!preg_match('/^\s*Basic\s+(\S+)\s*$/i', $authorization, $m)) {
            return null;
        }
        $decoded = base64_decode($m[1], true);
        if ($decoded === false) {
            return null;
        }
        // user:pass — username may be empty (" :pass" / ":pass")
        $pos = strpos($decoded, ':');
        if ($pos === false) {
            return null;
        }

        return substr($decoded, $pos + 1);
    }

    public static function cookieValue(User $user): string
    {
        $version = self::version($user);
        $sig = hash_hmac(
            'sha256',
            $user->username . '|' . $version,
            self::cookieSigningKey()
        );

        return $version . '.' . $sig;
    }

    public static function cookieIsValid(User $user, ?string $cookie): bool
    {
        if ($cookie === null || $cookie === '' || !self::isEnabled($user)) {
            return false;
        }
        $parts = explode('.', $cookie, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$versionPart, $sig] = $parts;
        if (!ctype_digit($versionPart) || (int) $versionPart !== self::version($user)) {
            return false;
        }
        $expected = hash_hmac(
            'sha256',
            $user->username . '|' . (int) $versionPart,
            self::cookieSigningKey()
        );

        return hash_equals($expected, $sig);
    }

    /**
     * @return array{site_password_enabled: bool, site_password_auth_mode?: string, site_password_auth_request?: string, site_password_locations?: string}
     */
    public static function nginxTemplateVars(User $user): array
    {
        if (!self::isEnabled($user)) {
            return ['site_password_enabled' => false];
        }

        $mode = self::authMode();
        $username = $user->username;

        $authRequest = "auth_request /_pa_site_auth;\n"
            . "            error_page 401 = @pa_site_auth_fail;";

        if ($mode === self::MODE_BASIC) {
            $failBody = "add_header WWW-Authenticate 'Basic realm=\"Protected\"' always;\n"
                . "        return 401;";
        } else {
            $failBody = "resolver 127.0.0.54 valid=30s;\n"
                . "        set \$enginehost core.shared-hosting.palocal;\n"
                . "        proxy_pass http://\$enginehost/api/internal/site-password/{$username}/gate;\n"
                . "        proxy_set_header Host \$host;\n"
                . "        proxy_set_header X-Forwarded-Proto \$scheme;\n"
                . "        proxy_set_header X-Original-URI \$request_uri;\n"
                . "        proxy_set_header X-Real-IP \$remote_addr;\n"
                . "        proxy_method GET;\n"
                . "        proxy_pass_request_body off;\n"
                . "        proxy_set_header Content-Length \"\";";
        }

        $locations = <<<NGINX
    location = /_pa_site_auth {
        internal;
        resolver 127.0.0.54 valid=30s;
        set \$enginehost core.shared-hosting.palocal;
        proxy_pass http://\$enginehost/api/internal/site-password/{$username}/check;
        proxy_pass_request_body off;
        proxy_set_header Content-Length "";
        proxy_set_header Authorization \$http_authorization;
        proxy_set_header Cookie \$http_cookie;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-PA-Auth-Mode {$mode};
    }
    location @pa_site_auth_fail {
        {$failBody}
    }
    location = /_pa_site_password {
        resolver 127.0.0.54 valid=30s;
        set \$enginehost core.shared-hosting.palocal;
        proxy_pass http://\$enginehost/api/internal/site-password/{$username}/login;
        proxy_set_header Host \$host;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Real-IP \$remote_addr;
    }
NGINX;

        return [
            'site_password_enabled' => true,
            'site_password_auth_mode' => $mode,
            'site_password_auth_request' => $authRequest,
            'site_password_locations' => $locations,
        ];
    }

    private static function applyWebserver(User $user): void
    {
        $system = new System();
        $webserver = $system->webserver()->getCurrentWebserver();
        if ($webserver !== 'nginx-proxy') {
            Log::warning(
                "Site password protection is configured for project '{$user->username}', "
                . "but webserver is '{$webserver}' (only nginx-proxy enforces it)."
            );
        }

        $user->loadMissing('domains');
        foreach ($user->domains as $domain) {
            $domain->projectDomain()->rebuild();
        }
        $system->webserver()->scheduleWebserverReloadInBackground();
    }

    /**
     * Cloudflare tunnels bypass host nginx; when a password is set, hairpin
     * origin through host.docker.internal so the same vhost auth applies.
     */
    private static function syncCloudflareOrigins(User $user): void
    {
        if ($user->getTemplate() !== 'dind') {
            return;
        }
        if (!Tunnel::projectHasCloudflareTunnels($user)) {
            return;
        }

        try {
            $user->loadMissing('domains');
            foreach ($user->domains as $domain) {
                Cloudflare::syncFromProxyRules($user, $domain);
            }
        } catch (\Throwable $e) {
            Log::warning(
                "Failed to sync Cloudflare tunnel origins after site password change for {$user->username}: "
                . $e->getMessage()
            );
        }
    }

    private static function normalizePassword(string $password): string
    {
        // Do not trim interior spaces; only strip a trailing newline from CLI prompts.
        return preg_replace("/\r\n$|\n$|\r$/", '', $password) ?? $password;
    }

    private static function cookieSigningKey(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        return $key !== '' ? $key : 'pa-site-password';
    }
}
