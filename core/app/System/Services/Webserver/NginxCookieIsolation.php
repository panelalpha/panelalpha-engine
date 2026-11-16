<?php

namespace App\System\Services\Webserver;

/** The parents an nginx vhost hands to the filter that narrows a cookie's Domain to the host. */
final class NginxCookieIsolation
{
    /**
     * @return array{cookie_isolation_shared_parents: ?string}
     */
    public static function templateVars(CookieScope $scope): array
    {
        return [
            'cookie_isolation_shared_parents' => $scope->sharedParents === [] ? null : implode(' ', $scope->sharedParents),
        ];
    }
}
