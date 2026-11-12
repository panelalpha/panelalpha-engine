<?php

namespace App\Lib\Deploy\Telemetry;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Source\GitUrl;

/**
 * What the checkout on disk, not the account record, says about its repository: only facts that change a build.
 * {@see DeployReport::repo()} decides what is sent; a fact that cannot be read is absent, never an exception.
 */
final class CheckoutFacts
{
    /** Longest branch name kept. Longer than any branch anybody types. */
    public const MAX_BRANCH_BYTES = 120;

    /** What `rev-parse --abbrev-ref HEAD` prints when nothing is checked out. */
    private const DETACHED = 'HEAD';

    /**
     * Read one checkout.
     *
     * @param  callable(list<string>): ?string  $run  runs a git argv and returns its stdout, or null when it failed
     * @return array{
     *   present: bool,
     *   remote?: ?string,
     *   branch?: ?string,
     *   detached?: bool,
     *   commit?: ?string,
     *   committed_at?: ?int,
     *   shallow?: bool,
     *   submodules?: bool,
     *   lfs?: bool
     * }
     */
    public static function read(string $dir, callable $run): array
    {
        $dir = rtrim(trim($dir), '/');
        if ($dir === '') {
            return ['present' => false];
        }

        $git = self::gitIn($dir, $run);

        if ($git(['rev-parse', '--is-inside-work-tree']) !== 'true') {
            return ['present' => false];
        }

        [$commit, $committedAt] = self::head($git);
        $branch = self::branch($git);

        return [
            'present' => true,
            'remote' => self::remote($git),
            'branch' => $branch,
            'detached' => $branch === null && $commit !== null,
            'commit' => $commit,
            'committed_at' => $committedAt,
            'shallow' => $git(['rev-parse', '--is-shallow-repository']) === 'true',
            'submodules' => self::isFile($dir.'/.gitmodules'),
            'lfs' => self::usesLfs($dir.'/.gitattributes'),
        ];
    }

    /**
     * safe.directory, as the tree's top directory may not be the runner's own.
     *
     * @param  callable(list<string>): ?string  $run
     * @return \Closure(list<string>): ?string
     */
    private static function gitIn(string $dir, callable $run): \Closure
    {
        return static function (array $args) use ($run, $dir): ?string {
            $out = $run(['git', '-c', 'safe.directory='.$dir, '-C', $dir, ...$args]);

            return is_string($out) ? trim($out) : null;
        };
    }

    /**
     * The remote, with any embedded credential stripped: an uploaded repository may have
     * `https://user:token@host/...` written into it, and that string must not survive being read.
     *
     * @param  callable(list<string>): ?string  $git
     */
    private static function remote(callable $git): ?string
    {
        $url = $git(['config', '--get', 'remote.origin.url']);
        if ($url === null || $url === '') {
            return null;
        }

        return GitUrl::sanitize($url);
    }

    /**
     * HEAD's commit and the second it was committed, in one call: an unborn HEAD (a `git init` nothing
     * was committed into) fails the whole command rather than half of it.
     *
     * @param  callable(list<string>): ?string  $git
     * @return array{0: ?string, 1: ?int}
     */
    private static function head(callable $git): array
    {
        $line = $git(['log', '-1', '--format=%H %ct']);
        if ($line === null || preg_match('/^([0-9a-f]{40}) (\d+)$/', $line, $m) !== 1) {
            return [null, null];
        }

        return [$m[1], (int) $m[2]];
    }

    /**
     * The checked-out branch, or null when HEAD is detached, as a deploy of a tag or a commit is; `detached`
     * tells that normal case apart from a branch nobody could read.
     *
     * @param  callable(list<string>): ?string  $git
     */
    private static function branch(callable $git): ?string
    {
        $branch = $git(['rev-parse', '--abbrev-ref', 'HEAD']);
        if ($branch === null || $branch === '' || $branch === self::DETACHED) {
            return null;
        }

        return strlen($branch) > self::MAX_BRANCH_BYTES
            ? substr($branch, 0, self::MAX_BRANCH_BYTES)
            : $branch;
    }

    /**
     * Whether the tree resolves anything through git-lfs, read from `.gitattributes`: the answer matters when lfs
     * is not installed, and a clone of 130-byte pointer stubs serves broken assets nothing else explains.
     */
    private static function usesLfs(string $path): bool
    {
        if (! self::isFile($path)) {
            return false;
        }

        $contents = AccountHomeRead::contents($path, 64 * 1024);

        return is_string($contents) && str_contains($contents, 'filter=lfs');
    }

    private static function isFile(string $path): bool
    {
        return @is_file($path);
    }
}
