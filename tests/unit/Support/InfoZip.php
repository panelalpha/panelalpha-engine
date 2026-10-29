<?php

namespace Tests\Support;

/**
 * A zip of stored members, as Info-ZIP's `zip` writes a non-ASCII name or a symlink on Linux or macOS.
 */
final class InfoZip
{
    private const FILE = 0100644;

    private const LINK = 0120777;

    /** The raw name in the headers and a name again in a Unicode Path extra field. */
    public static function withUnicodePath(string $path, string $headerName, string $unicodeName): void
    {
        $extra = pack('vvCV', 0x7075, 5 + strlen($unicodeName), 1, crc32($headerName)) . $unicodeName;
        self::write($path, [[$headerName, 'x', self::FILE, $extra, 0]]);
    }

    /** The name flagged as UTF-8 (bit 11), beside the usual timestamp extra field. */
    public static function utf8Flagged(string $path, string $name): void
    {
        self::write($path, [[$name, 'x', self::FILE, pack('vvCV', 0x5455, 5, 1, 0), 0x800]]);
    }

    /**
     * Symlinks as `zip -y` stores them, the target as the member's contents, after the plain files.
     *
     * @param array<string, string> $links name => target
     * @param array<string, string> $files name => contents
     */
    public static function withLinks(string $path, array $links, array $files = []): void
    {
        $members = [];
        foreach ($files as $name => $contents) {
            $members[] = [(string) $name, $contents, self::FILE, '', 0];
        }
        foreach ($links as $name => $target) {
            $members[] = [(string) $name, $target, self::LINK, '', 0];
        }
        self::write($path, $members);
    }

    /** @param list<array{string, string, int, string, int}> $members name, contents, mode, extra, flags */
    private static function write(string $path, array $members): void
    {
        $local = $central = '';
        foreach ($members as [$name, $data, $mode, $extra, $flags]) {
            $sizes = [crc32($data), strlen($data), strlen($data), strlen($name), strlen($extra)];
            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50, 0x031e, 10, $flags, 0, 0, 0x21, ...$sizes, ...[0, 0, 0, $mode << 16, strlen($local)]
            ) . $name . $extra;
            $local .= pack('VvvvvvVVVvv', 0x04034b50, 10, $flags, 0, 0, 0x21, ...$sizes) . $name . $extra . $data;
        }
        $count = count($members);
        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($local), 0);
        file_put_contents($path, $local . $central . $end);
    }
}
