<?php

namespace App\Lib\Hub;

/**
 * The Hub is `config('hub.url')`. Everything that carries a Hub credential
 * goes over https with verification on; plain http is accepted only for a Hub
 * on this machine in a local environment.
 */
final class HubUrl
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1'];

    /** @throws HubException */
    public static function base(): string
    {
        $url = rtrim(trim((string) config('hub.url', '')), '/');
        $problem = self::problem($url, (string) config('app.env'));
        if ($problem !== null) {
            throw new HubException($problem);
        }

        return $url;
    }

    public static function problem(string $url, string $environment): ?string
    {
        if ($url === '') {
            return 'No PanelAlpha Hub is configured (PANELALPHA_HUB is empty).';
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return "PANELALPHA_HUB is not a usable URL: {$url}";
        }
        if ($scheme === 'https') {
            return null;
        }
        if ($scheme === 'http' && $environment === 'local' && in_array($host, self::LOCAL_HOSTS, true)) {
            return null;
        }

        return "PANELALPHA_HUB must be an https URL to connect this engine (got {$url}).";
    }
}
