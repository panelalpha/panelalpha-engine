<?php

namespace App\System\Privileged;

/** File commands as root, in the engine container or in the host's namespaces. */
final class RootFiles
{
    private const FIND_OPERATORS = ['(', ')', '!', ','];

    public function __construct(private readonly bool $inHostNamespaces = false) {}

    /** @return list<string> */
    public function remove(string ...$paths): array
    {
        return $this->onPaths(__FUNCTION__, $paths);
    }

    /** @return list<string> */
    public function removeIfExists(string ...$paths): array
    {
        return $this->onPaths(__FUNCTION__, $paths);
    }

    /** @return list<string> */
    public function removeTree(string ...$paths): array
    {
        return $this->onPaths(__FUNCTION__, $paths);
    }

    /** @return list<string> */
    public function removeEmptyDirectory(string ...$paths): array
    {
        return $this->onPaths(__FUNCTION__, $paths);
    }

    /** @return list<string> */
    public function makeDirectory(string ...$paths): array
    {
        return $this->onPaths(__FUNCTION__, $paths);
    }

    /** @return list<string> */
    public function makeDirectoryWithMode(string $mode, string $path): array
    {
        return $this->onPaths(__FUNCTION__, [$path], [Arguments::mode($mode)]);
    }

    /** @return list<string> */
    public function makeDirectories(string ...$paths): array
    {
        return $this->onPaths(__FUNCTION__, $paths);
    }

    /** @return list<string> */
    public function makeDirectoriesWithMode(string $mode, string ...$paths): array
    {
        return $this->onPaths(__FUNCTION__, $paths, [Arguments::mode($mode)]);
    }

    /** @return list<string> */
    public function move(string $from, string $to): array
    {
        return $this->onPaths(__FUNCTION__, [$from, $to]);
    }

    /** @return list<string> */
    public function moveOverwriting(string $from, string $to): array
    {
        return $this->onPaths(__FUNCTION__, [$from, $to]);
    }

    /**
     * Never moves $from into a directory $to.
     *
     * @return list<string>
     */
    public function rename(string $from, string $to): array
    {
        return $this->onPaths(__FUNCTION__, [$from, $to]);
    }

    /**
     * Copies onto the file $to itself, never into a directory.
     *
     * @return list<string>
     */
    public function copy(string $from, string $to): array
    {
        return $this->onPaths(__FUNCTION__, [$from, $to]);
    }

    /**
     * Removes $to, then copies $from with its mode, owner and times, a link as a link.
     *
     * @return list<string>
     */
    public function replaceWithPreservedCopy(string $from, string $to): array
    {
        return $this->onPaths(__FUNCTION__, [$from, $to]);
    }

    /** @return list<string> */
    public function copyTree(string $from, string $to): array
    {
        return $this->onPaths(__FUNCTION__, [$from, $to]);
    }

    /** @return list<string> */
    public function install(string $mode, string $from, string $to): array
    {
        return $this->onPaths(__FUNCTION__, [$from, $to], [Arguments::mode($mode)]);
    }

    /**
     * The mode goes after `--` too, since a symbolic mode such as `-w` reads as an option.
     *
     * @return list<string>
     */
    public function changeMode(string $mode, string ...$paths): array
    {
        return $this->onPaths(__FUNCTION__, $paths, [Arguments::mode($mode)]);
    }

    /** @return list<string> */
    public function changeModeRecursively(string $mode, string ...$paths): array
    {
        return $this->onPaths(__FUNCTION__, $paths, [Arguments::mode($mode)]);
    }

    /** @return list<string> */
    public function changeOwner(string $owner, string ...$paths): array
    {
        return $this->onPaths(__FUNCTION__, $paths, [Arguments::owner($owner)]);
    }

    /** @return list<string> */
    public function changeOwnerRecursively(string $owner, string ...$paths): array
    {
        return $this->onPaths(__FUNCTION__, $paths, [Arguments::owner($owner)]);
    }

    /**
     * Skips `docker/` with $keepDocker.
     *
     * @return list<string>
     */
    public function handTreeToAccount(string $root, int $uid, int $gid, bool $keepDocker): array
    {
        if ($uid < 1 || $gid < 1) {
            throw InvalidArgument::of('account', $uid.':'.$gid);
        }

        return $this->find(__FUNCTION__, $root, [(string) $uid, (string) $gid, $keepDocker ? '1' : '0']);
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

    /** @return list<string> */
    public function lastLines(int $count, string $path): array
    {
        return $this->onPaths(__FUNCTION__, [$path], [HostValues::count($count, 'line count')]);
    }

    /** @return list<string> */
    public function exists(string $path): array
    {
        return $this->onPaths(__FUNCTION__, [$path]);
    }

    /** @return list<string> */
    public function isFile(string $path): array
    {
        return $this->onPaths(__FUNCTION__, [$path]);
    }

    /** @return list<string> */
    public function isDirectory(string $path): array
    {
        return $this->onPaths(__FUNCTION__, [$path]);
    }

    /** @return list<string> */
    public function isSymlink(string $path): array
    {
        return $this->onPaths(__FUNCTION__, [$path]);
    }

    /** @return list<string> */
    public function deviceNumbers(string ...$paths): array
    {
        return $this->onPaths(__FUNCTION__, $paths);
    }

    /** @return list<string> */
    public function modificationTime(string $path): array
    {
        return $this->onPaths(__FUNCTION__, [$path]);
    }

    /** @return list<string> */
    public function permissionBits(string $path): array
    {
        return $this->onPaths(__FUNCTION__, [$path]);
    }

    /**
     * Fails unless every path exists. Accepts `..`.
     *
     * @return list<string>
     */
    public function existingRealPaths(string ...$paths): array
    {
        return $this->root(__FUNCTION__, array_map(self::pathToResolve(...), array_values($paths)));
    }

    /**
     * One name per line, dot entries left out.
     *
     * @return list<string>
     */
    public function listNames(string $dir): array
    {
        return $this->onPaths(__FUNCTION__, [$dir]);
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
    public function mirror(string $source, string $target): array
    {
        return $this->onPaths(__FUNCTION__, [$source, $target]);
    }

    /**
     * Compares by content and keeps $target's times on a changed file.
     *
     * @return list<string>
     */
    public function mirrorByContent(string $source, string $target): array
    {
        return $this->onPaths(__FUNCTION__, [$source, $target]);
    }

    /**
     * @param  list<string>  $excluded
     * @return list<string>
     */
    public function mirrorExcluding(string $source, string $target, array $excluded): array
    {
        return $this->root(__FUNCTION__, [...Arguments::paths([$source, $target]), ...self::excluded($excluded)]);
    }

    /**
     * @param  array<int, string>  $excluded
     * @return list<string>
     */
    public function createArchive(string $dir, string $archive, string $entry, array $excluded = []): array
    {
        return $this->root(__FUNCTION__, [
            ...Arguments::paths([$dir, $archive]), Arguments::archiveEntry($entry), ...array_map(Arguments::archivePattern(...), array_values($excluded)),
        ]);
    }

    /**
     * Unpacks a gzipped tar.
     *
     * @return list<string>
     */
    public function extractArchive(string $archive, string $dir, int $stripComponents = 0): array
    {
        if ($stripComponents < 0) {
            throw InvalidArgument::of('strip components', (string) $stripComponents);
        }

        return $this->root(__FUNCTION__, [...Arguments::paths([$archive, $dir]), (string) $stripComponents]);
    }

    /** @return list<string> */
    public function diskUsageInMegabytes(string $path): array
    {
        return $this->onPaths(__FUNCTION__, [$path]);
    }

    /** @return list<string> */
    public function diskUsageInBytes(string $path): array
    {
        return $this->onPaths(__FUNCTION__, [$path]);
    }

    /**
     * POSIX `df` columns.
     *
     * @return list<string>
     */
    public function diskFree(string $path): array
    {
        return $this->onPaths(__FUNCTION__, [$path]);
    }

    /**
     * `<type letter> <name>` per entry, NUL-terminated.
     *
     * @return list<string>
     */
    public function listEntryTypes(string $dir): array
    {
        return $this->find(__FUNCTION__, $dir);
    }

    /**
     * Paths relative to $dir, one per line.
     *
     * @return list<string>
     */
    public function relativeFilePaths(string $dir): array
    {
        return $this->find(__FUNCTION__, $dir);
    }

    /**
     * Prints nothing when $dir is empty.
     *
     * @return list<string>
     */
    public function firstEntry(string $dir): array
    {
        return $this->find(__FUNCTION__, $dir);
    }

    /** @return list<string> */
    public function symlinksIn(string $dir): array
    {
        return $this->find(__FUNCTION__, $dir);
    }

    /** @return list<string> */
    public function findInDirectoryIgnoringCase(string $dir, string $name): array
    {
        return $this->find(__FUNCTION__, $dir, [self::namePattern($name)]);
    }

    /** @return list<string> */
    public function emptyDirectory(string $dir): array
    {
        return $this->find(__FUNCTION__, $dir);
    }

    /** @return list<string> */
    public function removeEntriesNamed(string $dir, string $pattern): array
    {
        return $this->find(__FUNCTION__, $dir, [self::namePattern($pattern)]);
    }

    /** @return list<string> */
    public function removeEntriesOlderThan(string $dir, int $minutes): array
    {
        return $this->find(__FUNCTION__, $dir, [HostValues::count($minutes, 'minutes')]);
    }

    /** @return list<string> */
    public function moveEntriesInto(string $from, string $to): array
    {
        return $this->find(__FUNCTION__, $from, [Arguments::path($to)]);
    }

    /** @return list<string> */
    public function deleteFilesTwoLevelsDownOlderThan(string $dir, int $minutes): array
    {
        return $this->find(__FUNCTION__, $dir, [HostValues::count($minutes, 'minutes')]);
    }

    /**
     * Directories directly in $dir, one per line.
     *
     * @return list<string>
     */
    public function staleDirectoriesStartingWith(string $dir, string $prefix, int $minutes): array
    {
        self::namePattern($prefix.'*');

        return $this->find(__FUNCTION__, $dir, [$prefix, HostValues::count($minutes, 'minutes')]);
    }

    /**
     * NUL-terminated. Without $maxDepth the walk stays on $root's filesystem.
     *
     * @return list<string>
     */
    public function staleFilesStartingWith(string $root, ?int $maxDepth, int $minutes, string ...$prefixes): array
    {
        foreach ($prefixes as $prefix) {
            self::namePattern($prefix.'*');
        }

        return $this->find(__FUNCTION__, $root, [
            $maxDepth === null ? '' : HostValues::count($maxDepth, 'depth'), HostValues::count($minutes, 'minutes'), ...array_values($prefixes),
        ]);
    }

    /**
     * Sizes in bytes of Docker JSON logs two levels down, one per line.
     *
     * @return list<string>
     */
    public function containerLogSizes(string $dir): array
    {
        return $this->find(__FUNCTION__, $dir);
    }

    /**
     * One per line.
     *
     * @return list<string>
     */
    public function parentsOfDirectoriesTwoLevelsDownNamed(string $dir, string $name): array
    {
        return $this->find(__FUNCTION__, $dir, [self::namePattern($name)]);
    }

    /**
     * Nitro's `.output/server/index.{mjs,js}` files, skipping node_modules and .git.
     *
     * @return list<string>
     */
    public function nitroServerEntries(string $projectDir, int $maxDepth): array
    {
        return $this->find(__FUNCTION__, $projectDir, [HostValues::count($maxDepth, 'depth')]);
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $leadingValues
     * @return list<string>
     */
    private function onPaths(string $action, array $paths, array $leadingValues = []): array
    {
        return $this->root($action, [...$leadingValues, ...Arguments::paths($paths)]);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function find(string $action, string $dir, array $values = []): array
    {
        return $this->root($action, [Arguments::path($dir), ...$values]);
    }

    /**
     * @param  array<int, string>  $patterns
     * @return list<string>
     */
    private static function excluded(array $patterns): array
    {
        return array_map(Arguments::operand(...), array_values($patterns));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function root(string $action, array $values): array
    {
        return Root::action('files', $action, $values, $this->inHostNamespaces);
    }

    /** A `-name` pattern that find cannot read as an option or operator. */
    private static function namePattern(string $pattern): string
    {
        if (in_array($pattern, self::FIND_OPERATORS, true)) {
            throw InvalidArgument::of('name pattern', $pattern);
        }

        return Arguments::operand($pattern);
    }

    /** An absolute path that may hold `..`. */
    private static function pathToResolve(string $path): string
    {
        if (! str_starts_with($path, '/') || preg_match('~[\x00\n\r]~', $path) === 1) {
            throw InvalidArgument::of('path', $path);
        }

        return $path;
    }
}
