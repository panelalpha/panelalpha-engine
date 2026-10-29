<?php

namespace App\Lib\Deploy\Telemetry;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Source\GitUrl;

/**
 * What the checkout on disk says about the repository it came from.
 *
 * Until this existed a report could only repeat what the *account record*
 * remembered: the `git_repo` a project was created with, and the commit the
 * deploy pipeline happened to freeze next to it. That covers exactly one
 * case -- a project created from a git URL by this engine -- and says nothing
 * about the two that break most often: an archive upload that turns out to
 * carry a `.git`, and a site connected to a remote after the fact through
 * `POST /git/connect`. Both deploy from a repository and both reported
 * `{"present": false}`, so every failure they caused was filed under "no
 * repository" and became uncountable.
 *
 * So this reads the tree instead of the record. The facts are the ones that
 * change what a build does -- where it came from, what is checked out,
 * whether the clone is shallow, whether there are submodules or LFS pointers
 * to resolve -- and nothing else: no file names, no author, no message, no
 * diff. {@see DeployReport::repo()} decides which of them may be transmitted
 * at which tier, the same split {@see AppFacts} makes for package metadata.
 *
 * Every read is best-effort. This runs at the end of a deploy that may have
 * failed halfway through a clone, against a directory that is about to be
 * rolled back; a fact that cannot be read is absent, never an exception.
 *
 * No Laravel dependencies -- unit-testable with any runner.
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
     * @param  callable(list<string>): ?string  $run  runs a git argv and returns
     *                                                its stdout, or null when the command failed. Injected rather than
     *                                                called directly so this class stays free of the process layer.
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

        $git = static function (array $args) use ($run, $dir): ?string {
            // safe.directory because the tree belongs to the account user and
            // this does not run as them; -C rather than a chdir so nothing
            // about the caller's working directory matters.
            $out = $run(['git', '-c', 'safe.directory='.$dir, '-C', $dir, ...$args]);

            return is_string($out) ? trim($out) : null;
        };

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
     * The remote, with any embedded credential stripped.
     *
     * A checkout this engine cloned keeps a clean URL in `.git/config`,
     * because it clones through GIT_ASKPASS rather than by writing the token
     * into the remote. A repository somebody uploaded may well have
     * `https://user:token@host/...` written into it, and that string must not
     * survive being read.
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
     * HEAD's commit and the second it was committed.
     *
     * One call for both: they are read together everywhere they are used, and
     * an unborn HEAD -- a `git init` no deploy ever committed into -- fails
     * the whole command rather than half of it.
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
     * The checked-out branch, or null when HEAD is detached.
     *
     * A deploy that resolved a tag or a commit rather than a branch is the
     * detached case, and it is worth telling apart from a branch nobody could
     * read: the first is normal, the second means the checkout is broken.
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
     * Whether the tree resolves anything through git-lfs.
     *
     * Read from `.gitattributes` rather than by asking git, because the answer
     * is wanted precisely when lfs is *not* installed -- a clone whose large
     * files arrived as 130-byte pointer stubs builds, deploys and serves
     * broken assets, and nothing else in a report explains it.
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
