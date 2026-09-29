<?php

namespace App\Mcp\Tools\Api;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * How a file reaches a multipart endpoint through a tool call.
 *
 * MCP arguments are JSON, so a `format: binary` property cannot be passed as
 * itself. For a property `file` the tool exposes four arguments instead:
 * `file_name`, `file_contents` (base64, or text when `file_encoding` is
 * `text`) and `file_url`, which the engine downloads so a large archive never
 * has to travel through the conversation. Exactly one of contents and url is
 * given; the result is an UploadedFile in test mode, which the endpoint's own
 * validation and FileManager accept like a browser upload.
 */
final class UploadArguments
{
    /** Largest download accepted from a URL, in bytes. */
    public const MAX_URL_BYTES = 2147483648;

    /**
     * The tool arguments that stand in for one binary property.
     *
     * @return array<int, string>
     */
    public static function virtualNames(string $param): array
    {
        return ["{$param}_name", "{$param}_contents", "{$param}_encoding", "{$param}_url"];
    }

    /**
     * Schema entries for the generator: name, OpenAPI-shaped definition,
     * required, description.
     *
     * @return array<int, array{0: string, 1: array<string, mixed>, 2: bool, 3: string}>
     */
    public static function describe(string $param): array
    {
        return [
            ["{$param}_name", ['type' => 'string'], false,
                "File name, no directories. Required with {$param}_contents; defaults to the URL's last segment."],
            ["{$param}_contents", ['type' => 'string'], false,
                "The bytes, base64 unless {$param}_encoding is text. Small files only."],
            ["{$param}_encoding", ['type' => 'string', 'enum' => ['base64', 'text']], false,
                "Default base64."],
            ["{$param}_url", ['type' => 'string'], false,
                "http(s) URL the engine downloads instead, e.g. a repository's archive/refs/heads/main.zip."],
        ];
    }

    /**
     * Turn the virtual arguments into UploadedFiles and strip them from the
     * input, so what remains is exactly the endpoint's own fields.
     *
     * @param array<string, mixed> $input
     * @param array<int, string> $fileParams
     * @param null|callable(string, string): void $fetch download a URL into a path; the HTTP client by default
     * @return array{0: array<string, mixed>, 1: array<string, UploadedFile>}
     */
    public static function resolve(array $input, array $fileParams, ?callable $fetch = null): array
    {
        $files = [];

        foreach ($fileParams as $param) {
            $name = self::stringOrNull($input["{$param}_name"] ?? null);
            $contents = self::stringOrNull($input["{$param}_contents"] ?? null);
            $encoding = self::stringOrNull($input["{$param}_encoding"] ?? null) ?? 'base64';
            $url = self::stringOrNull($input["{$param}_url"] ?? null);

            foreach (self::virtualNames($param) as $virtual) {
                unset($input[$virtual]);
            }

            if (($contents === null) === ($url === null)) {
                throw new InvalidArgumentException("Pass exactly one of {$param}_contents or {$param}_url.");
            }

            $tmp = tempnam(sys_get_temp_dir(), 'pa-mcp-upload-');
            if ($tmp === false) {
                throw new RuntimeException('Could not create a temporary file for the upload.');
            }

            try {
                if ($url !== null) {
                    self::assertFetchable($url);
                    $name ??= self::nameFromUrl($url);
                    ($fetch ?? self::download(...))($url, $tmp);
                } else {
                    $bytes = match ($encoding) {
                        'text' => $contents,
                        'base64' => base64_decode($contents, true),
                        default => throw new InvalidArgumentException("{$param}_encoding must be base64 or text."),
                    };
                    if ($bytes === false) {
                        throw new InvalidArgumentException("{$param}_contents is not valid base64; pass {$param}_encoding: text for plain text.");
                    }
                    if (file_put_contents($tmp, $bytes) === false) {
                        throw new RuntimeException('Could not write the upload to a temporary file.');
                    }
                }

                $files[$param] = new UploadedFile($tmp, self::validName($param, $name), null, null, true);
            } catch (\Throwable $e) {
                @unlink($tmp);
                throw $e;
            }
        }

        return [$input, $files];
    }

    /**
     * @param array<string, UploadedFile> $files
     */
    public static function discard(array $files): void
    {
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private static function download(string $url, string $into): void
    {
        $response = Http::timeout(600)
            ->withOptions(['sink' => $into, 'allow_redirects' => ['max' => 5]])
            ->get($url);

        if (!$response->successful()) {
            throw new RuntimeException("Downloading {$url} answered HTTP {$response->status()}.");
        }

        clearstatcache(true, $into);
        if ((int) filesize($into) > self::MAX_URL_BYTES) {
            throw new InvalidArgumentException("The file at {$url} is larger than the " . (self::MAX_URL_BYTES / 1048576) . " MB limit.");
        }
    }

    private static function assertFetchable(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException('The URL must be http:// or https:// with a host.');
        }
    }

    private static function nameFromUrl(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $last = basename(rawurldecode($path));

        return $last === '' || $last === '/' ? null : $last;
    }

    private static function validName(string $param, ?string $name): string
    {
        if ($name === null || $name === '' || $name === '.' || $name === '..'
            || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new InvalidArgumentException("{$param}_name must be a plain file name such as app.zip.");
        }

        return $name;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = is_scalar($value) ? (string) $value : '';

        return $value === '' ? null : $value;
    }
}
