<?php

namespace App\Lib\Apis\Cloudflare;

class CloudflareException extends \RuntimeException
{
    /** Cloudflare's codes for a malformed or missing token: 6111 sits in the error chain of a 6003. */
    private const MALFORMED_TOKEN_CODES = [6111, 9106];

    /** Code 9109 also answers a valid token that lacks a permission; only this text marks the token itself. */
    private const INVALID_ACCESS_TOKEN = [9109, 'Invalid access token'];

    /**
     * @param  list<array{code: int, message: string}>  $errors  Cloudflare's own errors, error chains included
     */
    public function __construct(
        string $message = '',
        private readonly ?int $responseStatus = null,
        ?\Throwable $previous = null,
        private readonly array $errors = [],
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** The HTTP status Cloudflare answered with, when the failure was one. */
    public function responseStatus(): ?int
    {
        return $this->responseStatus;
    }

    /** Cloudflare does not accept the token at all: unknown, expired, revoked or malformed. */
    public function isInvalidToken(): bool
    {
        if ($this->responseStatus === 401) {
            return true;
        }

        foreach ($this->errors as $error) {
            if (in_array($error['code'], self::MALFORMED_TOKEN_CODES, true) || self::isInvalidAccessToken($error)) {
                return true;
            }
        }

        return false;
    }

    public function isPermissionDenied(): bool
    {
        return $this->responseStatus === 403;
    }

    public function isRateLimited(): bool
    {
        return $this->responseStatus === 429;
    }

    /** Cloudflare refused the request itself (a 4xx), as opposed to failing to answer it. */
    public function isRefusedRequest(): bool
    {
        return $this->responseStatus !== null && $this->responseStatus >= 400 && $this->responseStatus < 500;
    }

    /** @param array{code: int, message: string} $error */
    private static function isInvalidAccessToken(array $error): bool
    {
        [$code, $text] = self::INVALID_ACCESS_TOKEN;

        return $error['code'] === $code && stripos($error['message'], $text) !== false;
    }
}
