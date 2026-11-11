<?php

namespace App\Lib\Http;

use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * A file fetched from a caller's URL: redirects are followed by hand, so every hop passes the outbound guard and is
 * pinned to the address it checked; the body is capped.
 */
final class GuardedDownload
{
    /**
     * @throws InvalidArgumentException when a hop is refused or the body is too large
     * @throws RuntimeException when the URL does not answer with a file
     */
    public static function fetch(string $url, string $into, int $maxBytes = OutboundUrl::MAX_DOWNLOAD_BYTES): void
    {
        $current = $url;
        $bounded = new BoundedDownload($maxBytes);

        for ($hop = 0; $hop <= OutboundUrl::MAX_REDIRECTS; $hop++) {
            OutboundUrl::assertAllowed($current);

            $response = $bounded->fetch($current, $into, OutboundUrl::pin($current));

            if ($response->redirect()) {
                $current = self::redirectTarget($current, $response);

                continue;
            }

            self::assertDownloaded($response, $current, $into, $maxBytes);

            return;
        }

        throw new RuntimeException("Downloading {$url} followed more than ".OutboundUrl::MAX_REDIRECTS.' redirects.');
    }

    private static function redirectTarget(string $current, Response $response): string
    {
        $location = (string) $response->header('Location');
        if ($location === '') {
            throw new RuntimeException("Downloading {$current} answered HTTP {$response->status()} with no Location.");
        }

        return OutboundUrl::absoluteUrl($current, $location);
    }

    private static function assertDownloaded(Response $response, string $current, string $into, int $maxBytes): void
    {
        if (! $response->successful()) {
            throw new RuntimeException("Downloading {$current} answered HTTP {$response->status()}.");
        }

        clearstatcache(true, $into);
        if ((int) filesize($into) > $maxBytes) {
            throw new InvalidArgumentException("The file at {$current} is larger than the ".($maxBytes / 1048576).' MB limit.');
        }
    }
}
