<?php

namespace App\System\Services\Webserver;

use App\Lib\Domains\NamingZones;
use App\Models\Domain;
use App\Models\User;

/**
 * Under a shared zone a shared parent's cookie is narrowed to the host, and a neighbour's
 * same-site request reaches the app without cookies unless it is a top-level GET.
 */
final class CookieIsolation
{
    /**
     * @return array{cookie_isolation_server: ?string, cookie_isolation_location: ?string, cookie_isolation_grpc: ?string, cookie_isolation_phpmyadmin: ?string}
     */
    public static function forDomain(Domain $domain, User $user, bool $behindFront): array
    {
        return self::directives(CookieScope::forDomain($domain, $user, $behindFront));
    }

    /**
     * @param  list<string>  $vhostNames  every name the vhost answers to, its tunnels' included
     * @param  list<string>  $projectNames  every name the project owns
     * @return array{cookie_isolation_server: ?string, cookie_isolation_location: ?string, cookie_isolation_grpc: ?string, cookie_isolation_phpmyadmin: ?string}
     */
    public static function templateVars(NamingZones $zones, array $vhostNames, array $projectNames, bool $behindFront): array
    {
        return self::directives(CookieScope::of($zones, $vhostNames, $projectNames, $behindFront));
    }

    /**
     * @return array{cookie_isolation_server: ?string, cookie_isolation_location: ?string, cookie_isolation_grpc: ?string, cookie_isolation_phpmyadmin: ?string}
     */
    public static function directives(CookieScope $scope): array
    {
        $gated = $scope->gated();

        return [
            'cookie_isolation_server' => $gated ? self::serverDirectives($scope->exposed, $scope->own, $scope->behindFront) : null,
            'cookie_isolation_location' => self::locationDirectives($scope->sharedParents, $gated),
            'cookie_isolation_grpc' => $gated ? 'grpc_set_header Cookie $pa_cookie;' : null,
            'cookie_isolation_phpmyadmin' => $gated ? 'proxy_set_header Cookie $pa_cookie;' : null,
        ];
    }

    /**
     * Sets $pa_cookie to the Cookie header, or to nothing for a neighbour's request.
     *
     * @param  list<string>  $exposed
     * @param  list<string>  $own
     */
    private static function serverDirectives(array $exposed, array $own, bool $behindFront): string
    {
        return implode("\n", [
            ...self::initiatorDirectives(),
            'set $pa_cookie_shared 0;',
            ...($exposed === [] ? [] : self::ifDirective('$host ~* "^'.self::alternation($exposed).'$"', 'set $pa_cookie_shared 1;')),
            ...($behindFront ? self::frontDirectives() : []),
            'set $pa_cookie_request "$pa_cookie_shared|$http_sec_fetch_site|$http_sec_fetch_mode|$request_method|$pa_cookie_from";',
            'set $pa_cookie $http_cookie;',
            ...self::ifDirective('$pa_cookie_request ~* "'.self::neighbourRequestPattern($own).'"', 'set $pa_cookie "";'),
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
            'set $pa_cookie_from "";',
            ...self::ifDirective('$http_referer ~* "^https?://([^/?#:]+)"', 'set $pa_cookie_from $1;'),
            ...self::ifDirective('$http_origin ~* "^https?://([^/:]+)"', 'set $pa_cookie_from $1;'),
        ];
    }

    /**
     * The front only forwards *.panelalpha.online names, previews of custom domains included;
     * realip moves the address only for a request that came through it.
     *
     * @return list<string>
     */
    private static function frontDirectives(): array
    {
        return [
            'set $pa_cookie_peer "$realip_remote_addr|$remote_addr";',
            ...self::ifDirective('$pa_cookie_peer !~ "^(.*)\|\1$"', 'set $pa_cookie_shared 1;'),
        ];
    }

    /**
     * Matches "shared|same-site|mode|method|initiator" for anything but a top-level GET or HEAD,
     * from a host that is not one of $own.
     *
     * @param  list<string>  $own
     */
    public static function neighbourRequestPattern(array $own): string
    {
        return '^1\|same-site\|(?!navigate\|(?:GET|HEAD)\|)[^|]*\|[^|]*\|(?!'.self::alternation($own).'$)';
    }

    /**
     * @param  list<string>  $sharedParents
     */
    private static function locationDirectives(array $sharedParents, bool $gated): ?string
    {
        $lines = [];
        if ($sharedParents !== []) {
            $lines[] = 'proxy_cookie_domain "~(?i)^\.?'.self::alternation($sharedParents).'$" $host;';
        }
        if ($gated) {
            $lines[] = 'proxy_set_header Cookie $pa_cookie;';
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private static function ifDirective(string $condition, string $then): array
    {
        return ['if ('.$condition.') {', '    '.$then, '}'];
    }

    /**
     * @param  list<string>  $names  hostnames, so only the dots need escaping
     */
    public static function alternation(array $names): string
    {
        return '(?:'.implode('|', array_map(static fn (string $name): string => str_replace('.', '\.', $name), $names)).')';
    }
}
