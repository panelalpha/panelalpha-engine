<?php

namespace App\System\Services\Webserver;

/**
 * Marks a neighbour's request in the rewrite engine where both LiteSpeed servers can act on it;
 * clearing its Cookie is a header operation only LiteSpeed Enterprise applies.
 */
final class LiteSpeedCookieIsolation
{
    /**
     * @return array{cookie_isolation_rewrite: ?string, cookie_isolation_headers: ?string, cookie_isolation_proxy_gate: ?string}
     */
    public static function templateVars(CookieScope $scope, bool $clearsRequestCookie): array
    {
        if ($scope->exposed === []) {
            return ['cookie_isolation_rewrite' => null, 'cookie_isolation_headers' => null, 'cookie_isolation_proxy_gate' => null];
        }

        return [
            'cookie_isolation_rewrite' => self::rewrite($scope->exposed, $scope->own),
            'cookie_isolation_headers' => $clearsRequestCookie ? self::headerOperation() : null,
            'cookie_isolation_proxy_gate' => self::proxyGate(),
        ];
    }

    /**
     * @param  list<string>  $exposed
     * @param  list<string>  $own
     */
    private static function rewrite(array $exposed, array $own): string
    {
        $lines = [
            ...self::topLevelLines(),
            ...self::initiatorLines($own),
            ...self::stripLines($exposed),
        ];

        return implode("\n", array_map(static fn (string $line): string => '  '.$line, $lines));
    }

    /**
     * A navigate GET or HEAD is a link click, which keeps its cookies.
     *
     * @return list<string>
     */
    private static function topLevelLines(): array
    {
        return [
            'RewriteCond %{HTTP:Sec-Fetch-Site} ^same-site$ [NC]',
            'RewriteCond %{HTTP:Sec-Fetch-Mode} ^navigate$ [NC]',
            'RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$ [NC]',
            'RewriteRule .* - [E=pa_cookie_top:1]',
        ];
    }

    /**
     * The initiator is the Origin's host, else the Referer's, exactly as the nginx-proxy rules read them.
     *
     * @param  list<string>  $own
     * @return list<string>
     */
    private static function initiatorLines(array $own): array
    {
        $initiator = CookieIsolation::alternation($own).'(?::\d+)?';

        return [
            'RewriteCond %{HTTP:Referer} ^https?://'.$initiator.'(?:[/?#]|$) [NC]',
            'RewriteRule .* - [E=pa_cookie_from_own:1]',
            'RewriteCond %{HTTP:Origin} ^https?://[^/:]',
            'RewriteRule .* - [E=pa_cookie_from_own:0]',
            'RewriteCond %{HTTP:Origin} ^https?://'.$initiator.'(?:/|$) [NC]',
            'RewriteRule .* - [E=pa_cookie_from_own:1]',
        ];
    }

    /**
     * @param  list<string>  $exposed
     * @return list<string>
     */
    private static function stripLines(array $exposed): array
    {
        return [
            'RewriteCond %{HTTP_HOST} ^'.CookieIsolation::alternation($exposed).'(?::\d+)?$ [NC]',
            'RewriteCond %{HTTP:Sec-Fetch-Site} ^same-site$ [NC]',
            'RewriteCond %{ENV:pa_cookie_top} !^1$',
            'RewriteCond %{ENV:pa_cookie_from_own} !^1$',
            'RewriteRule .* - [E=pa_cookie_strip:1]',
        ];
    }

    private static function headerOperation(): string
    {
        return 'RequestHeader unset Cookie env=pa_cookie_strip';
    }

    private static function proxyGate(): string
    {
        return '  RewriteCond %{ENV:pa_cookie_strip} !^1$';
    }
}
