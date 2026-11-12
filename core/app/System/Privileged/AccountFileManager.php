<?php

namespace App\System\Privileged;

/** File manager commands as the account. They follow links, and {@see confine()} checks where a path resolves. */
final class AccountFileManager
{
    /** One find row per entry: name, type, size, mtime, mode, uid, gid, link target, target type. */
    public const ENTRY_FIELDS = '%f\0%y\0%s\0%T@\0%m\0%U\0%G\0%l\0%Y\0';

    public function __construct(private readonly int $uid, private readonly int $gid) {}

    /** @return list<string> */
    public function makeFolder(string $path, bool $parents): array
    {
        return $this->asAccount(__FUNCTION__, [$path, self::flag($parents)]);
    }

    /** @return list<string> */
    public function writeCopy(string $source, string $target): array
    {
        return $this->asAccount(__FUNCTION__, [$source, $target]);
    }

    /** @return list<string> */
    public function exists(string $path): array
    {
        return $this->asAccount(__FUNCTION__, [$path]);
    }

    /** @return list<string> */
    public function isFile(string $path): array
    {
        return $this->asAccount(__FUNCTION__, [$path]);
    }

    /** @return list<string> */
    public function resolvedFile(string $path): array
    {
        return $this->asAccount(__FUNCTION__, [$path]);
    }

    /** @return list<string> */
    public function entryFields(string $path): array
    {
        return $this->asAccount(__FUNCTION__, [$path]);
    }

    /** @return list<string> */
    public function listPage(string $directory, int $limit, bool $showHidden): array
    {
        return $this->asAccount(__FUNCTION__, [$directory, (string) $limit, self::flag($showHidden)]);
    }

    /** @return list<string> */
    public function subdirectoryParents(string $directory): array
    {
        return $this->asAccount(__FUNCTION__, [$directory]);
    }

    /** @return list<string> */
    public function moveEntry(string $from, string $to): array
    {
        return $this->asAccount(__FUNCTION__, [$from, $to]);
    }

    /** @return list<string> */
    public function copyEntry(string $from, string $to): array
    {
        return $this->asAccount(__FUNCTION__, [$from, $to]);
    }

    /** @return list<string> */
    public function zip(string $zipPath, string $path, ?int $compressionLevel, ?string $fromDate): array
    {
        return $this->asAccount(__FUNCTION__, [$zipPath, $path, ...self::zipValues($compressionLevel, $fromDate)]);
    }

    /** @return list<string> */
    public function zipFrom(string $workdir, string $zipPath, string $name, ?int $compressionLevel, ?string $fromDate): array
    {
        return $this->asAccount(__FUNCTION__, [$workdir, $zipPath, $name, ...self::zipValues($compressionLevel, $fromDate)]);
    }

    /** @return list<string> */
    public function copyArchiveToStage(string $archive, string $staged): array
    {
        return $this->asAccount(__FUNCTION__, [$archive, $staged]);
    }

    /** @return list<string> */
    public function unzipInto(string $staged, string $directory): array
    {
        return $this->asAccount(__FUNCTION__, [$staged, $directory]);
    }

    /** @return list<string> */
    public function untarInto(string $staged, string $directory): array
    {
        return $this->asAccount(__FUNCTION__, [$staged, $directory]);
    }

    /** @return list<string> */
    public function gunzipTo(string $staged, string $target): array
    {
        return $this->asAccount(__FUNCTION__, [$staged, $target]);
    }

    /**
     * $destination is entered once and checked by its real path against $home.
     *
     * @return list<string>
     */
    public function moveUnpackedInto(string $unpacked, string $home, string $destination): array
    {
        return $this->asAccount(__FUNCTION__, [$unpacked, $home, $destination]);
    }

    /** @return list<string> */
    public function removeEntry(string $path, bool $recursive): array
    {
        return $this->asAccount(__FUNCTION__, [$path, self::flag($recursive)]);
    }

    /** @return list<string> */
    public function diskUsageExcluding(string $path, string $excluded): array
    {
        return $this->asAccount(__FUNCTION__, [$path, $excluded]);
    }

    /**
     * Without $override an entry already in $to is kept.
     *
     * @return list<string>
     */
    public function moveEntriesInto(string $from, string $to, bool $override): array
    {
        return $this->asAccount(__FUNCTION__, [$from, $to, self::flag($override)]);
    }

    /** @return list<string> */
    public function renameOver(string $from, string $to): array
    {
        return $this->asAccount(__FUNCTION__, [$from, $to]);
    }

    /** @return list<string> */
    public function removeFile(string $path): array
    {
        return $this->asAccount(__FUNCTION__, [$path]);
    }

    /**
     * Prints the HTTP status. $pin is curl's `--resolve` entry.
     *
     * @return list<string>
     */
    public function download(string $url, string $destination, int $maxBytes, ?string $pin): array
    {
        return $this->asAccount(__FUNCTION__, [$url, $destination, (string) $maxBytes, $pin ?? '']);
    }

    /** @return list<string> */
    public function redirectLocation(string $url, ?string $pin): array
    {
        return $this->asAccount(__FUNCTION__, [$url, $pin ?? '']);
    }

    /** @return list<string> */
    public function setMode(string $path, string $mode): array
    {
        return $this->asAccount(__FUNCTION__, [$path, $mode]);
    }

    /**
     * Refused, exit 3, when any of $tagged resolves outside $home.
     *
     * @param  array<int, string>  $tagged  `F:<path>`, every link followed, or `E:<path>`, the last component the entry itself
     * @return list<string>
     */
    public function confine(string $home, array $tagged): array
    {
        return $this->asAccount(__FUNCTION__, [$home, ...array_values($tagged)]);
    }

    /**
     * `missing`, `dangling`, `dir`, `dir-link` or `file`.
     *
     * @return list<string>
     */
    public function entryType(string $path): array
    {
        return $this->asAccount(__FUNCTION__, [$path]);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function asAccount(string $action, array $values): array
    {
        return Root::action('account', $action, [self::id($this->uid), self::id($this->gid), ...$values]);
    }

    private static function id(int $id): string
    {
        if ($id < 0) {
            throw InvalidArgument::of('uid or gid', (string) $id);
        }

        return (string) $id;
    }

    /** @return list<string> */
    private static function zipValues(?int $compressionLevel, ?string $fromDate): array
    {
        return [$compressionLevel === null ? '' : (string) $compressionLevel, $fromDate ?? ''];
    }

    private static function flag(bool $on): string
    {
        return $on ? '1' : '0';
    }
}
