<?php

namespace App\Lib\Project\Create;

/**
 * The `source` of a typed create or attach, read after {@see CreateBody}
 * checked its shape: what a project's first deploy brings into it.
 */
final class SourceSpec
{
    /** @param array<string, mixed> $fields */
    private function __construct(public readonly string $type, private readonly array $fields) {}

    /** @param array<string, mixed> $source */
    public static function of(array $source): self
    {
        return new self(CreateShape::sourceTypeOf($source), $source);
    }

    public static function none(): self
    {
        return new self(CreateShape::SOURCE_NONE, ['type' => CreateShape::SOURCE_NONE]);
    }

    public function url(): ?string
    {
        return $this->string('url');
    }

    public function branch(): ?string
    {
        return $this->string('branch');
    }

    public function authType(): string
    {
        $auth = $this->fields['auth'] ?? null;

        return is_array($auth) && is_string($auth['type'] ?? null) ? $auth['type'] : CreateShape::AUTH_NONE;
    }

    /** The token as sent: a literal or a `vault:` reference. */
    public function token(): ?string
    {
        return $this->authType() === CreateShape::AUTH_TOKEN ? $this->authString('token') : null;
    }

    /** The deploy key's `vault:` reference. */
    public function deployKey(): ?string
    {
        return $this->authType() === CreateShape::AUTH_DEPLOY_KEY ? $this->authString('key') : null;
    }

    public function uploadId(): ?string
    {
        return $this->string('upload_id');
    }

    /** An archive's URL; a git source's URL is {@see url()} too, so this answers only for an archive. */
    public function archiveUrl(): ?string
    {
        return $this->type === CreateShape::SOURCE_ARCHIVE ? $this->string('url') : null;
    }

    public function archivePath(): ?string
    {
        return $this->string('path');
    }

    public function copiedFrom(): ?string
    {
        return $this->string('from');
    }

    public function copyDatabases(): bool
    {
        return ($this->fields['copy_databases'] ?? true) !== false;
    }

    /**
     * @return array{title: string, admin_user: string, admin_email: string, admin_password: string, locale: ?string, version: ?string}
     */
    public function wordpress(): array
    {
        $admin = is_array($this->fields['admin'] ?? null) ? $this->fields['admin'] : [];

        return [
            'title' => (string) ($this->fields['title'] ?? ''),
            'admin_user' => (string) ($admin['user'] ?? ''),
            'admin_email' => (string) ($admin['email'] ?? ''),
            'admin_password' => (string) ($admin['password'] ?? ''),
            'locale' => $this->string('locale'),
            'version' => $this->string('version'),
        ];
    }

    private function string(string $key): ?string
    {
        return self::trimmed($this->fields[$key] ?? null);
    }

    private function authString(string $key): ?string
    {
        $auth = $this->fields['auth'] ?? null;

        return self::trimmed(is_array($auth) ? ($auth[$key] ?? null) : null);
    }

    private static function trimmed(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
