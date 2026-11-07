<?php

namespace App\System\Services\Webserver;

/**
 * Renders cookie isolation for a classic Apache vhost: a neighbour's request reaches PHP and
 * phpMyAdmin without its Cookie header, and a shared parent's Set-Cookie loses that Domain.
 */
final class ApacheCookieIsolation
{
    /**
     * @return array{cookie_isolation_request: ?string, cookie_isolation_response: ?string}
     */
    public static function templateVars(CookieScope $scope): array
    {
        return [
            'cookie_isolation_request' => self::requestDirectives($scope),
            'cookie_isolation_response' => self::responseDirectives($scope->sharedParents),
        ];
    }

    private static function requestDirectives(CookieScope $scope): ?string
    {
        if (! $scope->gated()) {
            return null;
        }

        return implode("\n", [
            ...self::initiatorDirectives(),
            'RequestHeader unset Cookie "expr='.self::neighbourRequestExpression($scope).'"',
        ]);
    }

    /**
     * The host the request came from: the Origin, else the Referer, else nothing.
     *
     * @return list<string>
     */
    private static function initiatorDirectives(): array
    {
        return [
            'SetEnvIfNoCase Referer "^https?://([^/?#:]+)" pa_cookie_from=$1',
            'SetEnvIfNoCase Origin "^https?://([^/:]+)" pa_cookie_from=$1',
        ];
    }

    /** The field string nginx-proxy hands to the same pattern, matched on an exposed host only. */
    private static function neighbourRequestExpression(CookieScope $scope): string
    {
        return self::hostCondition($scope->exposed)
            .' && (\''.self::requestFields().'\' =~ m#(?i)'.CookieIsolation::neighbourRequestPattern($scope->own).'#)';
    }

    /**
     * @param  list<string>  $exposed
     */
    private static function hostCondition(array $exposed): string
    {
        return "'%{HTTP_HOST}' =~ m#(?i)^".CookieIsolation::alternation($exposed).'(?::\d+)?$#';
    }

    private static function requestFields(): string
    {
        return '1|%{HTTP:Sec-Fetch-Site}|%{HTTP:Sec-Fetch-Mode}|%{REQUEST_METHOD}|%{env:pa_cookie_from}';
    }

    /**
     * @param  list<string>  $sharedParents
     */
    private static function responseDirectives(array $sharedParents): ?string
    {
        if ($sharedParents === []) {
            return null;
        }

        $domain = '(?i)(;\s*Domain\s*=\s*)\.?'.CookieIsolation::alternation($sharedParents).'(?=\s*;|\s*$)';

        return implode("\n", [
            'Header edit Set-Cookie "'.$domain.'" ""',
            'Header always edit Set-Cookie "'.$domain.'" ""',
        ]);
    }
}
