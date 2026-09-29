<?php

namespace App\Mcp\Tools\Api;

/**
 * How a tool returns a file: one window of it per call.
 *
 * A file is sent inside a JSON tool result that the model reads, so a whole
 * log or archive is both too big for the client and useless to the model.
 * Tools whose endpoint answers with a file take `offset` and `length` instead
 * -- tool-only arguments, never sent to the API -- and page through it with
 * `next_offset` while `more` is true.
 */
final class DownloadWindow
{
    /** Largest window, in bytes of the file. Base64 makes it ~64 KB on the wire. */
    public const MAX_BYTES = 49152;

    /** @return array<int, string> */
    public static function names(): array
    {
        return ['offset', 'length'];
    }

    /**
     * Schema entries for the generator: name, OpenAPI-shaped definition,
     * required, description.
     *
     * @return array<int, array{0: string, 1: array<string, mixed>, 2: bool, 3: string}>
     */
    public static function describe(): array
    {
        return [
            ['offset', ['type' => 'integer', 'minimum' => 0], false,
                'Byte to start reading at. Default 0; pass the previous result\'s next_offset to continue.'],
            ['length', ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_BYTES], false,
                'Bytes to read, at most ' . self::MAX_BYTES . ' (the default). The result says whether there is `more`.'],
        ];
    }

    /** @return array<string, string> */
    public static function rules(): array
    {
        return [
            'offset' => 'sometimes|integer|min:0',
            'length' => 'sometimes|integer|min:1|max:' . self::MAX_BYTES,
        ];
    }

    /**
     * Read one window. Text comes back as text, cut back to a whole UTF-8
     * character so the next window starts on one; anything else as base64.
     *
     * @param resource $handle
     * @return array{offset: int, length: int, next_offset: int|null, more: bool, encoding: string, content: string}|null
     *         null when the stream cannot be positioned
     */
    public static function read($handle, int $offset, int $length): ?array
    {
        $length = max(1, min($length, self::MAX_BYTES));

        if ($offset > 0 && fseek($handle, $offset) !== 0) {
            return null;
        }

        // One byte past the window says whether there is more without a stat.
        $bytes = stream_get_contents($handle, $length + 1);
        if ($bytes === false) {
            return null;
        }

        $more = strlen($bytes) > $length;
        $bytes = substr($bytes, 0, $length);

        $text = self::wholeUtf8($bytes, $more);
        if ($text !== null) {
            $bytes = $text;
        }

        $next = $offset + strlen($bytes);

        return [
            'offset' => $offset,
            'length' => strlen($bytes),
            'next_offset' => $more ? $next : null,
            'more' => $more,
            'encoding' => $text !== null ? 'utf-8' : 'base64',
            'content' => $text ?? base64_encode($bytes),
        ];
    }

    /**
     * The window as text, or null when it is not text. A window that stops
     * inside a multibyte character loses those trailing bytes to the next one.
     */
    private static function wholeUtf8(string $bytes, bool $more): ?string
    {
        if (str_contains($bytes, "\0")) {
            return null;
        }

        for ($cut = 0; $cut <= ($more ? 3 : 0) && $cut < strlen($bytes); $cut++) {
            $candidate = $cut === 0 ? $bytes : substr($bytes, 0, -$cut);
            if (mb_check_encoding($candidate, 'UTF-8')) {
                return $candidate;
            }
        }

        return $bytes === '' ? '' : null;
    }
}
