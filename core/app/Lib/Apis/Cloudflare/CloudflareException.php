<?php

namespace App\Lib\Apis\Cloudflare;

class CloudflareException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        private readonly ?int $responseStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** The HTTP status Cloudflare answered with, when the failure was one. */
    public function responseStatus(): ?int
    {
        return $this->responseStatus;
    }

    public function isPermissionDenied(): bool
    {
        return $this->responseStatus === 403;
    }
}
