<?php

namespace App\Lib\Project;

use App\Models\AppSsoToken;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The sign-in URL for one of the three answers an app's SSO gives: a ready URL (the app handles SSO itself, e.g.
 * WordPress's mu-plugin), a path on the project's primary domain, or cookie credentials the engine sets through
 * /panelalpha-sso before redirecting into the app.
 */
final class AppSsoLink
{
    /**
     * @param  array<string, mixed>  $result  the app's `users:sso` answer
     *
     * @throws \Exception when the answer is none of the three, or the project has no primary domain
     */
    public static function url(string $username, array $result): string
    {
        if (! empty($result['url'])) {
            return (string) $result['url'];
        }

        if (! empty($result['path'])) {
            return self::primaryDomainOrigin($username).$result['path'];
        }

        if (empty($result['cookie']) || empty($result['value'])) {
            throw new \Exception('Invalid SSO response returned by app');
        }

        return self::cookieHandoffUrl($username, $result);
    }

    /** @param array<string, mixed> $result */
    private static function cookieHandoffUrl(string $username, array $result): string
    {
        $origin = self::primaryDomainOrigin($username);

        $token = AppSsoToken::create([
            'token' => Str::random(48),
            'username' => $username,
            'cookie_name' => $result['cookie'],
            'cookie_value' => $result['value'],
            'redirect' => $result['redirect'] ?? '/',
            'expires_at' => now()->addSeconds(60),
        ]);

        return "{$origin}/panelalpha-sso?token={$token->token}";
    }

    /** @throws \Exception when the project has no primary domain */
    private static function primaryDomainOrigin(string $username): string
    {
        $domain = User::findByUsername($username)?->getMainDomain();
        if (! $domain) {
            throw new \Exception('No primary domain configured for this user');
        }
        $scheme = $domain->sslEnabled() ? 'https' : 'http';

        return "{$scheme}://{$domain->domain}";
    }
}
