<?php

namespace App\Lib\Cdn;

use RuntimeException;

/**
 * A CDN call the caller cannot fix by changing the request: no zone for the
 * hostname (404), Cloudflare rate-limiting the token (503), or Cloudflare
 * failing or unreachable (502). Carried out of the library with its status
 * and code, so the HTTP boundary answers `{message, code}` with that status.
 */
final class CdnFailure extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly string $problemCode,
    ) {
        parent::__construct($message);
    }

    public static function zoneNotFound(string $hostname): self
    {
        return new self("No Cloudflare zone for '{$hostname}' is visible to this API token.", 404, 'zone_not_found');
    }

    public static function rateLimited(): self
    {
        return new self(
            'Cloudflare is rate-limiting this API token. Try again in a few minutes.',
            503,
            'cloudflare_rate_limited',
        );
    }

    public static function unavailable(string $message): self
    {
        return new self($message, 502, 'cloudflare_unavailable');
    }
}
