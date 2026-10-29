<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

/**
 * One HTTP response from the source WordPress site (wp-admin or migrator REST).
 */
final class SourceHttpResponse
{
    public function __construct(
        public readonly string $body,
        public readonly int $status,
        public readonly ?string $effectiveUrl = null,
    ) {}

    /**
     * @return array<array-key, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Source WordPress response is not a JSON object');
        }

        return $decoded;
    }
}
