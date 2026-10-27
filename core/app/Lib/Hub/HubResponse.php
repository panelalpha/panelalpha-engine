<?php

namespace App\Lib\Hub;

/** A Hub answer reduced to what the callers branch on. */
final class HubResponse
{
    /** The Hub's own `error` on a 403 for a revoked or unknown connection. */
    public const CONNECTION_UNKNOWN = 'connection_unknown';

    /** @param array<string, mixed> $json */
    public function __construct(
        public readonly int $status,
        public readonly array $json,
        public readonly ?int $retryAfter = null,
    ) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** The `error` code the Hub sends on 4xx, if any. */
    public function error(): ?string
    {
        return is_string($this->json['error'] ?? null) ? $this->json['error'] : null;
    }

    /** Only the Hub's own refusal: a CDN or WAF in front of it answers 403 without this code. */
    public function connectionUnknown(): bool
    {
        return $this->status === 403 && $this->error() === self::CONNECTION_UNKNOWN;
    }

    public function message(): string
    {
        $message = $this->json['message'] ?? null;
        if (is_string($message) && $message !== '') {
            return $message;
        }

        return $this->error() ?? "HTTP {$this->status}";
    }
}
