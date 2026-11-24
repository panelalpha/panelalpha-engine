<?php

namespace App\Lib\Hub;

/** A Hub answer reduced to what the callers branch on. */
final class HubResponse
{
    /** The Hub's own `error` on a 403 for a revoked or unknown connection. */
    public const CONNECTION_UNKNOWN = 'connection_unknown';

    /** A push call on a connection the Hub still holds as pull. */
    public const UPGRADE_REQUIRED = 'upgrade_required';

    /** A backfill upload for a window the Hub did not hand out. */
    public const NO_OPEN_WINDOW = 'no_open_window';

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

    /** The `code` the Hub sends on a 409 that is not a replay. */
    public function code(): ?string
    {
        return is_string($this->json['code'] ?? null) ? $this->json['code'] : null;
    }

    /**
     * Only the Hub's own refusal: a CDN or WAF in front of it answers 403 with a page or its own code. The Hub names
     * the connection unknown, or refuses a signature it has no secret for with a bare JSON 403.
     */
    public function connectionUnknown(): bool
    {
        return $this->status === 403
            && ($this->error() === self::CONNECTION_UNKNOWN || ($this->error() === null && array_key_exists('message', $this->json)));
    }

    /**
     * Laravel's validation errors as `field: message` lines.
     *
     * @return list<string>
     */
    public function fieldErrors(): array
    {
        $lines = [];
        foreach (is_array($this->json['errors'] ?? null) ? $this->json['errors'] : [] as $field => $messages) {
            $lines[] = $field.': '.implode(' ', array_map('strval', (array) $messages));
        }

        return $lines;
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
