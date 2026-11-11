<?php

namespace App\Lib\Upload;

use App\Lib\Project\Create\CreateShape;

/**
 * Where an archive deploy reads its archive from: a file in the project home, an upload or a URL. An upload or a URL
 * is put into the project for that one deploy and removed after it.
 */
final class ArchiveSource
{
    public const PATH = 'path';

    public const UPLOAD = 'upload';

    public const URL = 'url';

    private function __construct(public readonly string $kind, public readonly string $value) {}

    public static function path(string $zipPath): self
    {
        return new self(self::PATH, $zipPath);
    }

    public static function upload(string $uploadId): self
    {
        return new self(self::UPLOAD, $uploadId);
    }

    public static function url(string $url): self
    {
        return new self(self::URL, $url);
    }

    /** @param ?array<string, mixed> $data */
    public static function fromArray(?array $data): ?self
    {
        $kind = $data['kind'] ?? null;
        $value = $data['value'] ?? null;
        if (! in_array($kind, [self::PATH, self::UPLOAD, self::URL], true) || ! is_string($value)) {
            return null;
        }

        return new self($kind, $value);
    }

    /** @return array{kind: string, value: string} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'value' => $this->value];
    }

    /** Put into the project for this deploy alone, so removed after it. */
    public function isDropped(): bool
    {
        return $this->kind !== self::PATH;
    }

    /**
     * What the project records as its source, for GET /projects/{name}: the one key it came by.
     *
     * @return array<string, string>
     */
    public function recorded(): array
    {
        return ['type' => CreateShape::SOURCE_ARCHIVE] + match ($this->kind) {
            self::UPLOAD => ['upload_id' => $this->value],
            self::URL => ['url' => self::shownUrl($this->value)],
            default => ['path' => $this->value],
        };
    }

    /**
     * What the deploy task shows of it, beside its `zip_path`.
     *
     * @return array<string, string>
     */
    public function taskDetails(): array
    {
        return match ($this->kind) {
            self::UPLOAD => ['upload_id' => $this->value],
            self::URL => ['archive_url' => self::shownUrl($this->value)],
            default => [],
        };
    }

    /** An archive URL as it is recorded and shown: no credentials, query or fragment, where a presigned URL carries its signature. */
    public static function shownUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return (string) preg_replace('/[?#].*\z/s', '', $url);
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].$port.($parts['path'] ?? '');
    }

    /** $text with every URL in it as {@see shownUrl()} shows it: a failed download names the URL, or where it redirected. */
    public static function withShownUrls(string $text): string
    {
        return (string) preg_replace_callback(
            '#\b[a-z][a-z0-9+.-]*://[^\s\'"`<>]+#i',
            static fn (array $url): string => self::shownUrl($url[0]),
            $text,
        );
    }
}
