<?php

namespace App\Lib\Deploy\Platform\Dockerfile;

/**
 * Whether an image build may read `.git`, so the engine must leave it in the
 * build context and accept that `COPY . .` misses the cache on every deploy.
 *
 * Deliberately generous: a wrongly kept `.git` costs a slower build, a wrongly
 * dropped one breaks it. A project can always decide for itself with `!.git`
 * in its `.dockerignore` or its own `<Dockerfile>.dockerignore`.
 */
final class GitHistoryUse
{
    /** Manifest files read at the context root, and what in each means "reads git". */
    private const MANIFEST_SIGNALS = [
        // setuptools-scm and friends derive the version from tags.
        'pyproject.toml' => '/setuptools[_-]scm|hatch-vcs|versioningit|dunamai|poetry-dynamic-versioning/i',
        'setup.cfg' => '/setuptools[_-]scm|versioningit|\bpbr\b/i',
        'setup.py' => '/setuptools[_-]scm|use_scm_version|versioningit|\bpbr\s*=/i',
        'Cargo.toml' => '/^\s*(vergen(-git2|-gitcl|-gix)?|git-version|shadow-rs|built)\s*=/m',
        'package.json' => '/"(git-rev-sync|git-revision-webpack-plugin|git-repo-info|git-describe|git-last-commit)"\s*:/',
        // A gemspec's `git ls-files` lists the gem's files.
        'Gemfile' => '/^\s*gemspec\b/m',
        'Makefile' => self::HISTORY_COMMAND,
        // Mage targets run git from Go: Vikunja's falls back to
        // `exec.Command("git", ...)` with "describe" when no version is given.
        'magefile.go' => self::GO_GIT_CALL,
    ];

    /** A git history command spelled out, or `"git"` and a history subcommand as Go string arguments. */
    private const GO_GIT_CALL = '/(?<![\w-])git(\s+-[cC]\s+\S+)*\s+(' . self::HISTORY_SUBCOMMANDS . ')\b'
        . '|"git"(?=[\s\S]*"(?:' . self::HISTORY_SUBCOMMANDS . ')")'
        . '|"(?:' . self::HISTORY_SUBCOMMANDS . ')"(?=[\s\S]*"git")/';

    private const HISTORY_SUBCOMMANDS
        = 'describe|rev-parse|rev-list|log|show|tag|status|diff|symbolic-ref|branch|submodule|lfs|ls-files|name-rev';

    /** `git [-C dir | -c k=v]... <subcommand>` for a subcommand that reads the local repository. */
    private const HISTORY_COMMAND = '/(?<![\w-])git(\s+-[cC]\s+\S+)*\s+(' . self::HISTORY_SUBCOMMANDS . ')\b/';

    /** @return list<string> */
    public static function manifestFiles(): array
    {
        return array_keys(self::MANIFEST_SIGNALS);
    }

    /**
     * Why this build may read `.git`, or null when nothing suggests it does.
     *
     * @param string|null               $dockerfile    the project's own Dockerfile; null for one the engine generated
     * @param string|null               $projectIgnore the project's `.dockerignore`
     * @param array<string, string|null> $manifests    contents by file name, from {@see manifestFiles()}
     */
    public static function reason(?string $dockerfile, ?string $projectIgnore, array $manifests): ?string
    {
        if ($projectIgnore !== null && self::reincludesGit($projectIgnore)) {
            return 'its .dockerignore re-includes .git';
        }
        if ($dockerfile !== null && self::dockerfileMentionsGit($dockerfile)) {
            return 'its Dockerfile uses git';
        }
        foreach (self::MANIFEST_SIGNALS as $file => $pattern) {
            $contents = $manifests[$file] ?? null;
            if ($contents !== null && preg_match($pattern, $contents) === 1) {
                return $file . ' reads the version from git';
            }
        }

        return null;
    }

    private static function reincludesGit(string $ignore): bool
    {
        return preg_match('#^\s*!\s*/?\.git(/.*)?\s*$#m', $ignore) === 1;
    }

    /**
     * A `.git` path (copied, mounted) or a git command that reads the
     * checkout's history, outside comments. Installing git does not count:
     * builds install it to fetch dependencies, and `go build` only stamps VCS
     * details when `.git` is there.
     */
    private static function dockerfileMentionsGit(string $dockerfile): bool
    {
        foreach (preg_split('/\R/', $dockerfile) ?: [] as $line) {
            if (preg_match('/^\s*#/', $line) === 1) {
                continue;
            }
            if (preg_match('#(?<![\w.-])\.git(?![\w.-])#', $line) === 1
                || preg_match(self::HISTORY_COMMAND, $line) === 1) {
                return true;
            }
        }

        return false;
    }
}
