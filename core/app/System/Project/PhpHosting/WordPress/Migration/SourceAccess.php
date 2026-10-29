<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Models\WordpressMigration;

/**
 * How to reach the source WordPress, as the caller gave it: the start and test
 * bodies, or a stored migration record. Holds secrets; never log or return it.
 */
final class SourceAccess
{
    public function __construct(
        public readonly string $url,
        public readonly string $method = WordpressMigration::METHOD_WP_ADMIN,
        public readonly ?string $loginUrl = null,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        public readonly ?string $sourceToken = null,
        public readonly ?string $sourceIp = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            url: (string) $validated['url'],
            method: self::stringOrNull($validated['method'] ?? null) ?? WordpressMigration::METHOD_WP_ADMIN,
            loginUrl: self::stringOrNull($validated['login_url'] ?? null),
            username: self::stringOrNull($validated['username'] ?? null),
            password: self::stringOrNull($validated['password'] ?? null),
            sourceToken: self::stringOrNull($validated['source_token'] ?? null),
            sourceIp: self::stringOrNull($validated['source_ip'] ?? null),
        );
    }

    public static function of(WordpressMigration $migration): self
    {
        $secrets = $migration->secretsForWorker();

        return new self(
            url: $migration->source_url,
            method: $migration->importMethod(),
            loginUrl: $migration->login_url,
            username: $migration->username,
            password: $secrets['password'],
            sourceToken: $secrets['source_token'],
            sourceIp: $migration->source_ip,
        );
    }

    public function usesManualPlugin(): bool
    {
        return $this->method === WordpressMigration::METHOD_MANUAL_PLUGIN;
    }

    /**
     * Pin for the source host when the caller named the address to reach it at.
     */
    public function pin(): ?SourceIpPin
    {
        if ($this->sourceIp === null) {
            return null;
        }

        $host = parse_url($this->url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? new SourceIpPin($host, $this->sourceIp) : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
