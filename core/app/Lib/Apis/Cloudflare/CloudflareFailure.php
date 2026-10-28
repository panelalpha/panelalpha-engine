<?php

namespace App\Lib\Apis\Cloudflare;

use App\Exceptions\ProblemException;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

/**
 * A Cloudflare call the caller cannot fix by changing the request: no zone for the
 * hostname (404), Cloudflare rate-limiting the token (503), or Cloudflare failing or
 * unreachable (502). Carried out of the library with its status and code, so the HTTP
 * boundary answers `{message, code}` with that status.
 */
final class CloudflareFailure extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly string $problemCode,
    ) {
        parent::__construct($message);
    }

    /**
     * What a failed call means to the project. A token Cloudflare does not accept, a token
     * missing $permission on the $resource, or a refused request is the caller's to fix (422);
     * a rate limit or a Cloudflare failure is worth retrying later (503, 502).
     */
    public static function of(
        CloudflareException|ConnectionException $e,
        string $permission,
        string $resource = 'zone'
    ): ProblemException|self {
        if ($e instanceof ConnectionException) {
            return self::unavailable('Cloudflare could not be reached. Try again later.');
        }

        return match (true) {
            $e->isInvalidToken() => self::invalidToken($e),
            $e->isPermissionDenied() => self::permissionDenied($permission, $resource),
            $e->isRateLimited() => self::rateLimited(),
            $e->isRefusedRequest() => ProblemException::one(
                'cloudflare_api_token',
                'cloudflare_request_refused',
                $e->getMessage()
            ),
            default => self::unavailable($e->getMessage()),
        };
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

    private static function invalidToken(CloudflareException $e): ProblemException
    {
        return ProblemException::one(
            'cloudflare_api_token',
            'cloudflare_token_invalid',
            "The project's Cloudflare API token is invalid or revoked ({$e->getMessage()}). "
            .'Save a current token on the project.'
        );
    }

    private static function permissionDenied(string $permission, string $resource): ProblemException
    {
        return ProblemException::one(
            'cloudflare_api_token',
            'cloudflare_permission_denied',
            "The Cloudflare API token is missing the {$permission} permission for this {$resource}."
        );
    }
}
