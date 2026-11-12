<?php

namespace App\System\Privileged;

/** File commands as the account, so a planted link reaches only the account's own files. Root is refused. */
final class AccountFiles
{
    /** The engine opens $1 as itself and the account's command reads it on stdin. */
    private const string FROM_FILE = 'exec "${@:2}" < "$1"';

    private readonly string $uid;

    private readonly string $gid;

    public function __construct(int $uid, int $gid)
    {
        $this->uid = self::id('uid', $uid);
        $this->gid = self::id('gid', $gid);
    }

    /**
     * Replaces $path with stdin.
     *
     * @return list<string>
     */
    public function write(string $path, string $mode): array
    {
        return $this->asAccount(__FUNCTION__, [Arguments::path($path), Arguments::mode($mode)]);
    }

    /**
     * write() fed from $source, which the engine reads as itself.
     *
     * @return list<string>
     */
    public function writeFrom(string $source, string $path, string $mode): array
    {
        return ['bash', '-c', self::FROM_FILE, 'bash', Arguments::path($source), ...$this->write($path, $mode)];
    }

    /** @return list<string> */
    public function makeDirectories(string ...$paths): array
    {
        return $this->onPaths(__FUNCTION__, $paths);
    }

    /** @return list<string> */
    public function removeIfExists(string ...$paths): array
    {
        return $this->onPaths(__FUNCTION__, $paths);
    }

    /** @return list<string> */
    public function changeMode(string $mode, string ...$paths): array
    {
        return $this->asAccount(__FUNCTION__, [Arguments::mode($mode), ...Arguments::paths($paths)]);
    }

    /** @return list<string> */
    public function changeModeRecursively(string $mode, string ...$paths): array
    {
        return $this->asAccount(__FUNCTION__, [Arguments::mode($mode), ...Arguments::paths($paths)]);
    }

    /** @return list<string> */
    public function moveOverwriting(string $from, string $to): array
    {
        return $this->onPaths(__FUNCTION__, [$from, $to]);
    }

    /**
     * Removes $to, then copies $from with its mode and times, a link as a link.
     *
     * @return list<string>
     */
    public function replaceWithPreservedCopy(string $from, string $to): array
    {
        return $this->onPaths(__FUNCTION__, [$from, $to]);
    }

    /** @return list<string> */
    public function touch(string ...$paths): array
    {
        return $this->onPaths(__FUNCTION__, $paths);
    }

    /** @return list<string> */
    public function read(string $path): array
    {
        return $this->onPaths(__FUNCTION__, [$path]);
    }

    /**
     * One name per line, dot entries included.
     *
     * @return list<string>
     */
    public function listAllNames(string $dir): array
    {
        return $this->onPaths(__FUNCTION__, [$dir]);
    }

    /** @return list<string> */
    public function isSymlink(string $path): array
    {
        return $this->onPaths(__FUNCTION__, [$path]);
    }

    /**
     * A symlink to a directory is not one.
     *
     * @return list<string>
     */
    public function isRealDirectory(string $path): array
    {
        return $this->onPaths(__FUNCTION__, [$path]);
    }

    /** @return list<string> */
    public function diskUsageInMegabytes(string $path, string ...$excluded): array
    {
        return $this->onPaths(__FUNCTION__, [$path, ...array_values($excluded)]);
    }

    /**
     * `<type letter> <name>` per entry, NUL-terminated.
     *
     * @return list<string>
     */
    public function listEntryTypes(string $dir): array
    {
        return $this->onPaths(__FUNCTION__, [$dir]);
    }

    /**
     * Nitro's `.output/server/index.{mjs,js}` files, skipping node_modules and .git.
     *
     * @return list<string>
     */
    public function nitroServerEntries(string $projectDir, int $maxDepth): array
    {
        return $this->asAccount(__FUNCTION__, [Arguments::path($projectDir), HostValues::count($maxDepth, 'depth')]);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function onPaths(string $action, array $paths): array
    {
        return $this->asAccount($action, Arguments::paths($paths));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function asAccount(string $action, array $values): array
    {
        return Root::action('account', $action, [$this->uid, $this->gid, ...$values]);
    }

    private static function id(string $kind, int $id): string
    {
        if ($id <= 0) {
            throw InvalidArgument::of($kind, (string) $id);
        }

        return (string) $id;
    }
}
