<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The phpMyAdmin SSO exchange answers only the phpMyAdmin container.
 *
 * "Any private address" used to be the rule, and every tenant container sits
 * on the same Docker network as core, so every tenant qualified. The sender now
 * has to be one of `services.phpmyadmin.sso_sources`, resolved per request so a
 * recreated container's new address is picked up.
 */
class PmaSso
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $peer = $this->peerAddress($request);
        if ($peer === null || !IpUtils::checkIp($peer, $this->allowedSources())) {
            abort(new JsonResponse([
                'message' => 'Not found',
            ], 404));
        }
        return $next($request);
    }

    /**
     * The TCP peer, not the client address realip derived from it: behind a
     * trusted hop REMOTE_ADDR is whatever that hop forwarded, and an older
     * nginx.conf trusted all of 172.16/12. PA_PEER_ADDR is `$realip_remote_addr`;
     * REMOTE_ADDR is only the fallback for an nginx.conf older than that param.
     */
    private function peerAddress(Request $request): ?string
    {
        $peer = $request->server('PA_PEER_ADDR') ?: $request->server('REMOTE_ADDR');

        return is_string($peer) && filter_var($peer, FILTER_VALIDATE_IP) !== false ? $peer : null;
    }

    /**
     * @return list<string> addresses and CIDR ranges
     */
    private function allowedSources(): array
    {
        $configured = config('services.phpmyadmin.sso_sources');
        $entries = array_filter(array_map('trim', explode(',', is_string($configured) ? $configured : '')));

        $allowed = [];
        foreach ($entries as $entry) {
            if ($this->isAddressOrRange($entry)) {
                $allowed[] = $entry;
                continue;
            }
            array_push($allowed, ...$this->resolve($entry));
        }

        return $allowed;
    }

    private function isAddressOrRange(string $entry): bool
    {
        [$address, $bits] = array_pad(explode('/', $entry, 2), 2, null);

        return filter_var($address, FILTER_VALIDATE_IP) !== false
            && ($bits === null || ctype_digit($bits));
    }

    /**
     * @return list<string>
     */
    protected function resolve(string $hostname): array
    {
        $addresses = gethostbynamel($hostname);

        return $addresses === false ? [] : array_values($addresses);
    }
}
