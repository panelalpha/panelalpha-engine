<?php

namespace App\Lib\Deploy\Source;

/**
 * Validate archive contents before extraction into a user home.
 *
 * Two independent checks, deliberately fed from two different listings so
 * neither has to parse the other's format:
 *
 *  - {@see assertSafeListing()} takes a names-only listing (`unzip -Z1`,
 *    `tar -tzf`) and rejects absolute paths and `..` traversal. Names-only
 *    means filenames containing spaces survive intact.
 *  - {@see assertRegularMembersOnly()} takes a verbose listing (`unzip -Z`,
 *    `tar -tvzf`), reads nothing but the leading mode column, and rejects
 *    anything that is not a regular file, a directory or a symlink.
 *  - {@see assertLinksStayInside()} takes each symlink's target and refuses
 *    one that could leave the archive: a `link -> /etc` member followed by
 *    `link/passwd` passes every name-based check and still writes outside
 *    the extraction directory. A relative link to another member
 *    (`CLAUDE.md -> AGENTS.md`) is allowed.
 *  - {@see assertUncompressedSizeWithin()} reads the same verbose listing a
 *    second time, for the size column this time, and refuses an archive that
 *    *says* it unpacks to more than the caller allows. Counting members is not
 *    enough: a handful of entries can still unpack to terabytes, and an account
 *    whose disk limit is the default `-1` will happily take them. This is an
 *    early reject on a self-declared number, though, and an archive is free to
 *    lie -- {@see ArchiveUnpackedSize} is what actually decides.
 *
 * The caller must also make the archive immutable before listing it — see
 * Dind::importProjectArchive(), which stages a root-owned copy. Otherwise the
 * account user can swap the file between the check and the extraction.
 *
 * No Laravel dependencies — unit-testable.
 */
class ArchiveSafety
{
    private const MAX_ENTRIES = 100000;

    /**
     * A backstop against a zip bomb, not a quota. It sits well above any real
     * application upload so it never becomes the thing that rejects a customer's
     * site; the per-account limit is the filesystem's job.
     */
    public const MAX_UNCOMPRESSED_BYTES = 5 * 1024 * 1024 * 1024;

    public static function assertSafeListing(string $listing): void
    {
        $entries = preg_split('/\r?\n/', $listing);
        if ($entries === false) {
            throw new \InvalidArgumentException('Could not inspect archive contents.');
        }

        $count = 0;
        foreach ($entries as $entry) {
            $entry = rtrim($entry, "\r");
            if ($entry === '') {
                continue;
            }
            $count++;
            if ($count > self::MAX_ENTRIES) {
                throw new \InvalidArgumentException('Archive contains too many entries.');
            }

            self::assertSafePath($entry);
        }
    }

    private const LINK_MESSAGE = 'Archive contains a symbolic link, which could redirect extraction outside the project directory.';

    /**
     * Reject members that are not regular files, directories or symlinks.
     *
     * Reads only the mode column at the start of a line, which both `unzip -Z`
     * and `tar -tvzf` emit in the same `-rw-r--r--` / `lrwxrwxrwx` shape. Lines
     * that do not start with a mode column are archive headers and summaries,
     * and are skipped.
     */
    public static function assertRegularMembersOnly(string $listing): void
    {
        $lines = preg_split('/\r?\n/', $listing);
        if ($lines === false) {
            throw new \InvalidArgumentException('Could not inspect archive contents.');
        }

        foreach ($lines as $line) {
            if (preg_match('/^([bcdlpsD?-])[rwxsStT-]{9}[.+@]?\s/', $line, $match) !== 1) {
                continue;
            }
            // `?` is a member with no type bits (Python's zipfile.writestr()); unzip
            // and GNU tar both extract it as a regular file.
            // Symlinks are checked by assertLinksStayInside().
            if ($match[1] === '-' || $match[1] === 'd' || $match[1] === '?' || $match[1] === 'l') {
                continue;
            }

            throw new \InvalidArgumentException('Archive contains a special file, which cannot be hosted.');
        }
    }

    /**
     * The symlink members of a `tar -tvzf` listing, name => target. The name
     * is matched against the names-only listing, so neither may hold spaces
     * or ` -> ` ambiguously; a link line that cannot be read refuses.
     *
     * @param list<string> $names {@see names()} of the same archive
     * @return array<string, string>
     */
    public static function tarLinks(string $listing, array $names): array
    {
        $links = [];
        foreach (self::linkLines($listing) as $line) {
            // `lrwxrwxrwx owner/group size date time name -> target`
            if (preg_match('/^l\S{9}[.+@]?\s+\S+\s+\d+\s+\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(?::\d{2})?\s(.*)$/', $line, $match) !== 1) {
                throw new \InvalidArgumentException(self::LINK_MESSAGE);
            }
            $found = array_values(array_filter($names, static fn (string $name): bool => str_starts_with($match[1], $name . ' -> ')));
            if (count($found) !== 1) {
                throw new \InvalidArgumentException(self::LINK_MESSAGE);
            }
            $links[$found[0]] = substr($match[1], strlen($found[0]) + 4);
        }

        return $links;
    }

    /**
     * The symlink members of an `unzip -Z` listing, by name. Zip keeps the
     * target as the member's contents, so the caller reads it from there.
     *
     * @param list<string> $names {@see names()} of the same archive
     * @return list<string>
     */
    public static function zipLinkNames(string $listing, array $names): array
    {
        $links = [];
        foreach (self::linkLines($listing) as $line) {
            $found = array_values(array_filter($names, static fn (string $name): bool => str_ends_with($line, ' ' . $name)));
            usort($found, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            if ($found === []) {
                throw new \InvalidArgumentException(self::LINK_MESSAGE);
            }
            $links[] = $found[0];
        }

        return $links;
    }

    /**
     * Members of a names-only listing ({@see assertSafeListing()}).
     *
     * @return list<string>
     */
    public static function names(string $listing): array
    {
        return array_values(array_filter(
            array_map(static fn (string $line): string => rtrim($line, "\r"), preg_split('/\n/', $listing) ?: []),
            static fn (string $line): bool => $line !== ''
        ));
    }

    /**
     * Refuse a symlink that could lead out of the archive: an absolute or
     * empty target, one whose `..` climbs above the archive root, one that
     * passes through another symlink on the way, and any member stored
     * below a symlink. Walking the target over real directories only, the
     * lexical answer is the one the filesystem gives.
     *
     * @param array<string, string> $links link member => target
     * @param list<string> $names every member
     */
    public static function assertLinksStayInside(array $links, array $names): void
    {
        if ($links === []) {
            return;
        }
        $linkPaths = [];
        foreach (array_keys($links) as $link) {
            $linkPaths[implode('/', self::components((string) $link))] = true;
        }

        foreach ($names as $name) {
            $parents = self::components($name);
            array_pop($parents);
            $path = [];
            foreach ($parents as $part) {
                $path[] = $part;
                if (isset($linkPaths[implode('/', $path)])) {
                    throw new \InvalidArgumentException(self::LINK_MESSAGE);
                }
            }
        }

        foreach ($links as $link => $target) {
            if ($target === '' || str_contains($target, "\0") || str_starts_with($target, '/')) {
                throw new \InvalidArgumentException(self::LINK_MESSAGE);
            }
            $at = self::components((string) $link);
            array_pop($at);
            $parts = self::components($target, keepDotDot: true);
            foreach ($parts as $i => $part) {
                if ($part === '..') {
                    if ($at === []) {
                        throw new \InvalidArgumentException(self::LINK_MESSAGE);
                    }
                    array_pop($at);
                    continue;
                }
                $at[] = $part;
                // The last component may be a link: it is checked on its own.
                if ($i < count($parts) - 1 && isset($linkPaths[implode('/', $at)])) {
                    throw new \InvalidArgumentException(self::LINK_MESSAGE);
                }
            }
        }
    }

    /** @return list<string> */
    private static function linkLines(string $listing): array
    {
        return array_values(array_filter(
            preg_split('/\r?\n/', $listing) ?: [],
            static fn (string $line): bool => preg_match('/^l[rwxsStT-]{9}[.+@]?\s/', $line) === 1
        ));
    }

    /**
     * A path's components without `.` and empty ones; `..` is kept only
     * when asked for, since member names never hold one ({@see assertSafePath()}).
     *
     * @return list<string>
     */
    private static function components(string $path, bool $keepDotDot = false): array
    {
        return array_values(array_filter(
            explode('/', str_replace('\\', '/', $path)),
            static fn (string $part): bool => $part !== '' && $part !== '.' && ($keepDotDot || $part !== '..')
        ));
    }

    /**
     * Reject an archive that declares more than $maxBytes unpacked.
     *
     * Both listings put the size after the mode column, in a different position
     * and with a different neighbour -- `unzip -Z` writes `3.0 unx <size>` and
     * `tar -tvzf` writes `<owner>/<group> <size>` -- so the size is found as the
     * first all-digit field after the mode rather than by counting columns.
     * Lines with no mode column are headers and summaries, and are skipped.
     *
     * These numbers come out of the archive's own headers, so they are a cheap
     * way to turn an oversized upload away before anything is decompressed --
     * and no kind of guarantee. An archive that under-declares passes here and
     * is caught by {@see ArchiveUnpackedSize}, which counts the real bytes.
     */
    public static function assertUncompressedSizeWithin(
        string $listing,
        int $maxBytes = self::MAX_UNCOMPRESSED_BYTES
    ): void {
        $lines = preg_split('/\r?\n/', $listing);
        if ($lines === false) {
            throw new \InvalidArgumentException('Could not inspect archive contents.');
        }

        $total = 0;
        foreach ($lines as $line) {
            if (preg_match('/^[bcdlpsD?-][rwxsStT-]{9}[.+@]?\s+(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $size = self::firstNumericField($match[1]);
            if ($size === null) {
                continue;
            }

            $total += $size;
            if ($total > $maxBytes) {
                throw new \InvalidArgumentException(sprintf(
                    'Archive unpacks to more than %d MB, which is more than this server accepts.',
                    intdiv($maxBytes, 1024 * 1024)
                ));
            }
        }
    }

    private static function firstNumericField(string $rest): ?int
    {
        $fields = preg_split('/\s+/', trim($rest)) ?: [];
        foreach ($fields as $field) {
            if ($field !== '' && ctype_digit($field)) {
                return (int) $field;
            }
        }

        return null;
    }

    public static function assertSafePath(string $path): void
    {
        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Archive contains an invalid path.');
        }

        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:\//', $path) === 1) {
            throw new \InvalidArgumentException('Archive contains an absolute path.');
        }

        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                throw new \InvalidArgumentException('Archive contains a path outside the project directory.');
            }
        }
    }
}
