<?php

namespace App\Lib\Deploy\Source;

/**
 * Validate archive contents before extraction into a user home: names, member types, symlink targets
 * and declared size. The caller stages an immutable copy first (Dind::importProjectArchive()).
 */
class ArchiveSafety
{
    private const MAX_ENTRIES = 100000;

    private const NAME_UNZIP_RENAMES = '/[\x00-\x1f\x7f\xff]|\^[@-_]|;\d*\z/';

    /** An `unzip -Z` member made on MS-DOS: `-rw-a--  2.0 fat  30 b- stor 80-Jan-01 00:00 name`. */
    private const DOS_MEMBER_LINE = '/^\S+\s+\d+\.\d+ fat +\d+ \S+ \S+ \S+ \S+ (.*)$/';

    private const BACKSLASH_MESSAGE = 'Archive uses backslashes in file names, which would be extracted as part of each name rather than as folders; re-create it with forward slashes.';

    /** A member line of `tar -tv` or `unzip -Z` with a Unix mode: `-rw-r--r-- user/group 12 …`, `-rw-r--r--  3.0 unx 12 tx …`. */
    private const UNIX_MODE_LINE = '/^([bcdlpsD?-])[rwxsStT-]{9}[.+@]?\s+(.*)$/';

    /**
     * An `unzip -Z` member line with the attributes zipinfo prints for MS-DOS and the systems sharing them (ntf, hpf, vft):
     * `-rw-a--  2.0 fat  30 b- stor …`. A file, a folder or a volume label, which unzip skips; never a link or a device.
     */
    private const DOS_ATTRIBUTES_LINE = '/^([-dV])r[-w][-x][-a][-h][-s] +\d+\.\d+ [a-z]{3} +(\d+) [tTbB][-xXlL] /';

    /** Any `unzip -Z` member line, whatever its attributes look like: VMS's `RWED,RWED,RWED`, an Amiga's `?hsparwed`. */
    private const ZIP_MEMBER_LINE = '/^[^\s:]+ +(?:\d+\.\d+ +)?\S+ +\d+ [tTbB][-xXlL] \S+ \S+ \S+ /';

    /** `?` (no type bits) extracts as a regular file and unzip skips a volume label (`V`); links are assertLinksStayInside()'s. */
    private const HOSTED_TYPES = ['-', 'd', '?', 'l', 'V'];

    private const UNREADABLE_MESSAGE = 'Archive lists a member whose type or size cannot be read, as a zip made on VMS or an Amiga does; re-create it on Windows, macOS or Linux.';

    private const TAR_ESCAPES = [
        '\\' => '\\', 'a' => "\x07", 'b' => "\x08", 'f' => "\f", 'n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v",
    ];

    /** A backstop against a zip bomb, not a quota: the per-account limit is the filesystem's job. */
    public const MAX_UNCOMPRESSED_BYTES = 5 * 1024 * 1024 * 1024;

    public static function assertSafeListing(string $listing): void
    {
        self::assertSafeNames(self::names($listing));
    }

    /**
     * @param  list<string>  $names  as the extractor writes them ({@see unescapeTarNames()} for tar); only zip uses `\`
     */
    public static function assertSafeNames(array $names, bool $backslashSeparates = true): void
    {
        if (count($names) > self::MAX_ENTRIES) {
            throw new \InvalidArgumentException('Archive contains too many entries.');
        }

        foreach ($names as $name) {
            self::assertSafePath($name, $backslashSeparates);
        }
    }

    /**
     * unzip writes a name without its control bytes, 0x7f, 0xff and a trailing `;<digits>` (a VMS
     * version), which `unzip -Z1` all lists. It lists a control byte as `^X`, so a literal `^X` goes too.
     *
     * @param  list<string>  $names  as `unzip -Z1` lists them
     */
    public static function assertUnzipKeepsNames(array $names): void
    {
        foreach ($names as $name) {
            if (preg_match(self::NAME_UNZIP_RENAMES, $name) === 1) {
                throw new \InvalidArgumentException('Archive contains a file name unzip would rename on extraction, such as one with a control character, byte 0xff or a trailing ";1".');
            }
        }
    }

    /**
     * unzip reads `\` as a folder separator only in a name made on MS-DOS that holds no `/`, as Windows tools write
     * them; anywhere else it stays a character of the file name. {@see assertSafeNames()} reads every `\` as a separator.
     *
     * @param  string  $listing  `unzip -Z`
     * @param  list<string>  $names  as `unzip -Z1` lists them
     */
    public static function assertBackslashesSeparateFolders(string $listing, array $names): void
    {
        $madeOnDos = self::membersMadeOnDos($listing);
        foreach (array_count_values($names) as $name => $members) {
            if (self::unzipKeepsBackslashesIn((string) $name, $members, $madeOnDos)) {
                throw new \InvalidArgumentException(self::BACKSLASH_MESSAGE);
            }
        }
    }

    /** @param  array<string, int>  $madeOnDos */
    private static function unzipKeepsBackslashesIn(string $name, int $members, array $madeOnDos): bool
    {
        return str_contains($name, '\\') && (str_contains($name, '/') || ($madeOnDos[$name] ?? 0) !== $members);
    }

    /** @return array<string, int> name => how many members of that name were made on MS-DOS */
    private static function membersMadeOnDos(string $listing): array
    {
        $made = [];
        foreach (preg_split('/\r?\n/', $listing) ?: [] as $line) {
            if (preg_match(self::DOS_MEMBER_LINE, $line, $match) === 1) {
                $made[$match[1]] = ($made[$match[1]] ?? 0) + 1;
            }
        }

        return $made;
    }

    /**
     * GNU tar prints names in its `escape` quoting style (`\\`, C escapes, `\ooo` for non-ASCII bytes);
     * the extractor writes the raw bytes, so the checks must too.
     *
     * @param  list<string>  $names  lines of `tar --quoting-style=escape -t`
     * @return list<string>
     */
    public static function unescapeTarNames(array $names): array
    {
        return array_map(self::unescapeTarName(...), $names);
    }

    private static function unescapeTarName(string $name): string
    {
        return (string) preg_replace_callback(
            '/\\\\([0-3][0-7]{2}|.?)/s',
            static fn (array $escape): string => self::tarEscaped($escape[1]),
            $name
        );
    }

    private static function tarEscaped(string $code): string
    {
        if (strlen($code) === 3) {
            return chr((int) octdec($code));
        }

        return self::TAR_ESCAPES[$code] ?? throw new \InvalidArgumentException('Archive contains an invalid path.');
    }

    private const LINK_MESSAGE = 'Archive contains a symbolic link, which could redirect extraction outside the project directory.';

    /** Reject members that are not regular files, directories or symlinks, from the leading mode or attributes column alone. */
    public static function assertRegularMembersOnly(string $listing): void
    {
        foreach (self::lines($listing) as $line) {
            $member = self::member($line);
            if ($member !== null && ! in_array($member['type'], self::HOSTED_TYPES, true)) {
                throw new \InvalidArgumentException('Archive contains a special file, which cannot be hosted.');
            }
        }
    }

    /**
     * A member line's type and declared size, null for a line that lists none, such as unzip's header and totals.
     * A zip member listed in neither the Unix nor the MS-DOS form refuses: it would go unchecked.
     *
     * @return array{type: string, size: ?int}|null
     */
    private static function member(string $line): ?array
    {
        if (preg_match(self::UNIX_MODE_LINE, $line, $match) === 1) {
            return ['type' => $match[1], 'size' => self::firstNumericField($match[2])];
        }
        if (preg_match(self::DOS_ATTRIBUTES_LINE, $line, $match) === 1) {
            return ['type' => $match[1], 'size' => (int) $match[2]];
        }
        if (preg_match(self::ZIP_MEMBER_LINE, $line) === 1) {
            throw new \InvalidArgumentException(self::UNREADABLE_MESSAGE);
        }

        return null;
    }

    /** @return list<string> */
    private static function lines(string $listing): array
    {
        $lines = preg_split('/\r?\n/', $listing);
        if ($lines === false) {
            throw new \InvalidArgumentException('Could not inspect archive contents.');
        }

        return $lines;
    }

    /**
     * The symlink members of a `tar -tvzf` listing (`lrwxrwxrwx owner/group size date time name -> target`),
     * unescaped; a link line that cannot be read unambiguously refuses.
     *
     * @param  list<string>  $names  {@see names()} of the same archive, still escaped
     * @return array<string, string>
     */
    public static function tarLinks(string $listing, array $names): array
    {
        $links = [];
        foreach (self::linkLines($listing) as $line) {
            if (preg_match('/^l\S{9}[.+@]?\s+\S+\s+\d+\s+\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(?::\d{2})?\s(.*)$/', $line, $match) !== 1) {
                throw new \InvalidArgumentException(self::LINK_MESSAGE);
            }
            $found = array_values(array_filter($names, static fn (string $name): bool => str_starts_with($match[1], $name.' -> ')));
            if (count($found) !== 1) {
                throw new \InvalidArgumentException(self::LINK_MESSAGE);
            }
            $links[self::unescapeTarName($found[0])] = self::unescapeTarName(substr($match[1], strlen($found[0]) + 4));
        }

        return $links;
    }

    /**
     * The symlink members of an `unzip -Z` listing, by name; the caller reads each target from its contents.
     *
     * @param  list<string>  $names  {@see names()} of the same archive
     * @return list<string>
     */
    public static function zipLinkNames(string $listing, array $names): array
    {
        $links = [];
        foreach (self::linkLines($listing) as $line) {
            $found = array_values(array_filter($names, static fn (string $name): bool => str_ends_with($line, ' '.$name)));
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
     * Refuse a symlink that could lead out of the archive: an absolute or empty target, a `..` above the root,
     * a path through another symlink, or any member stored below one. Walking real directories only, lexical is exact.
     *
     * @param  array<string, string>  $links  link member => target
     * @param  list<string>  $names  every member
     */
    public static function assertLinksStayInside(array $links, array $names): void
    {
        if ($links === []) {
            return;
        }
        $linkPaths = self::linkPaths($links);
        self::assertNothingBelowLinks($names, $linkPaths);

        foreach ($links as $link => $target) {
            self::assertTargetStaysInside((string) $link, $target, $linkPaths);
        }
    }

    /**
     * A `\` in a link's name or target is refused: whether it separates decides how far `..` climbs.
     *
     * @param  array<string, string>  $links
     * @return array<string, true>
     */
    private static function linkPaths(array $links): array
    {
        $linkPaths = [];
        foreach ($links as $link => $target) {
            if (str_contains((string) $link, '\\') || str_contains($target, '\\')) {
                throw new \InvalidArgumentException(self::LINK_MESSAGE);
            }
            $linkPaths[implode('/', self::components((string) $link))] = true;
        }

        return $linkPaths;
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, true>  $linkPaths
     */
    private static function assertNothingBelowLinks(array $names, array $linkPaths): void
    {
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
    }

    /**
     * The last component may be a link itself: it is checked on its own.
     *
     * @param  array<string, true>  $linkPaths
     */
    private static function assertTargetStaysInside(string $link, mixed $target, array $linkPaths): void
    {
        if ($target === '' || str_contains($target, "\0") || str_starts_with($target, '/')) {
            throw new \InvalidArgumentException(self::LINK_MESSAGE);
        }
        $at = self::components($link);
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
            if ($i < count($parts) - 1 && isset($linkPaths[implode('/', $at)])) {
                throw new \InvalidArgumentException(self::LINK_MESSAGE);
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
     * A path's components without `.` and empty ones; `..` only when asked for ({@see assertSafePath()}).
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
     * Reject an archive that declares more than $maxBytes unpacked, or a member whose size cannot be read. After a Unix
     * mode the size is the first all-digit field. A self-declared number; {@see ArchiveUnpackedSize} counts the real bytes.
     */
    public static function assertUncompressedSizeWithin(
        string $listing,
        int $maxBytes = self::MAX_UNCOMPRESSED_BYTES
    ): void {
        $total = 0;
        foreach (self::lines($listing) as $line) {
            $member = self::member($line);
            if ($member === null) {
                continue;
            }

            $total += $member['size'] ?? throw new \InvalidArgumentException(self::UNREADABLE_MESSAGE);
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

    public static function assertSafePath(string $path, bool $backslashSeparates = true): void
    {
        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Archive contains an invalid path.');
        }

        if ($backslashSeparates) {
            $path = str_replace('\\', '/', $path);
        }
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
