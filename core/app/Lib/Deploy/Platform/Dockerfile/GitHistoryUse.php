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

    /** The same, or `spawn('git', ['rev-parse', ...])` style arguments in JS. */
    private const JS_GIT_CALL = '/(?<![\w-])git(\s+-[cC]\s+\S+)*\s+(' . self::HISTORY_SUBCOMMANDS . ')\b'
        . '|[\'"`]git[\'"`]\s*,\s*\[\s*[\'"`](?:' . self::HISTORY_SUBCOMMANDS . ')[\'"`]/';

    /** Bundler configs that often bake the commit in: Stremio Web's runs `git rev-parse HEAD`. */
    private const JS_BUILD_CONFIGS = [
        'webpack.config.js', 'webpack.config.cjs', 'webpack.config.mjs', 'webpack.config.ts',
        'vite.config.js', 'vite.config.mjs', 'vite.config.ts', 'vite.config.mts',
        'rollup.config.js', 'rollup.config.mjs', 'next.config.js', 'next.config.mjs', 'next.config.ts',
        'svelte.config.js', 'nuxt.config.ts', 'astro.config.mjs', 'vue.config.js',
    ];

    /** package.json scripts an image build runs without naming them. */
    private const JS_IMPLIED_SCRIPTS = ['preinstall', 'install', 'postinstall', 'prepare', 'build'];

    /** Script files read while following package.json scripts and their relative imports. */
    private const JS_FILE_BUDGET = 20;

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
     * @param (callable(string): ?string)|null $read  a file by path relative to the context; enables the JS checks
     */
    public static function reason(?string $dockerfile, ?string $projectIgnore, array $manifests, ?callable $read = null): ?string
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
        $package = $manifests['package.json'] ?? null;
        if ($read !== null && $package !== null) {
            return self::jsBuildReason($package, $dockerfile, $read);
        }

        return null;
    }

    /**
     * A bundler config, a package.json script the build runs, or a script file
     * one of those runs (and what it imports) calling git. Companion's
     * postinstall runs tools/build_writefile.mts, whose ./lib.mts runs
     * `git rev-parse`.
     *
     * @param callable(string): ?string $read
     */
    private static function jsBuildReason(string $packageJson, ?string $dockerfile, callable $read): ?string
    {
        foreach (self::JS_BUILD_CONFIGS as $config) {
            $contents = $read($config);
            if ($contents !== null && preg_match(self::JS_GIT_CALL, $contents) === 1) {
                return $config . ' reads the version from git';
            }
        }

        $package = json_decode($packageJson, true);
        $scripts = is_array($package['scripts'] ?? null) ? $package['scripts'] : [];
        $names = self::JS_IMPLIED_SCRIPTS;
        if ($dockerfile !== null
            && preg_match_all('/\b(?:npm|pnpm|yarn|bun)\s+(?:run(?:-script)?\s+)?([\w:.-]+)/', $dockerfile, $m) > 0) {
            $names = array_merge($names, $m[1]);
        }

        $pending = [];
        $seen = [];
        foreach ($names as $name) {
            foreach (["pre{$name}", $name, "post{$name}"] as $script) {
                if (!isset($seen[$script]) && is_string($scripts[$script] ?? null)) {
                    $seen[$script] = true;
                    $pending[] = $script;
                }
            }
        }

        $files = [];
        while ($pending !== []) {
            $name = array_shift($pending);
            $body = $scripts[$name];
            if (preg_match(self::HISTORY_COMMAND, $body) === 1) {
                return "package.json script \"{$name}\" reads the version from git";
            }
            // `npm run x`, `yarn x`, and Yarn 2+'s bare `run x` inside a script.
            preg_match_all('/(?:^|[\s;&|(])(?:(?:npm|pnpm|bun)\s+run(?:-script)?|yarn(?:\s+run)?|run)\s+([\w:.-]+)/', $body, $m);
            foreach ($m[1] as $next) {
                foreach (["pre{$next}", $next, "post{$next}"] as $script) {
                    if (!isset($seen[$script]) && is_string($scripts[$script] ?? null)) {
                        $seen[$script] = true;
                        $pending[] = $script;
                    }
                }
            }
            preg_match_all('#(?:^|[\s;&|(])(?:node|bash|sh|zsh|dash|bun|deno|ts-node|tsx|zx)(?:\s+-[-\w=:.]+)*\s+'
                . '(?:\./)?((?:[\w.-]+/)*[\w.-]+\.(?:cjs|mjs|js|cts|mts|ts|sh))\b#', $body, $m);
            foreach ($m[1] as $file) {
                $files[] = $file;
            }
        }

        $checked = [];
        while ($files !== [] && count($checked) < self::JS_FILE_BUDGET) {
            $file = self::normalisePath(array_shift($files));
            if ($file === null || isset($checked[$file]) || str_starts_with($file, 'node_modules/')) {
                continue;
            }
            $checked[$file] = true;
            $contents = $read($file);
            if ($contents === null) {
                continue;
            }
            if (preg_match(self::JS_GIT_CALL, $contents) === 1) {
                return $file . ', run by a package.json script, reads the version from git';
            }
            preg_match_all('/(?:\bfrom|\bimport|\brequire\s*\()\s*[\'"](\.\.?\/[^\'"]+\.(?:cjs|mjs|js|cts|mts|ts))[\'"]/', $contents, $m);
            $dir = str_contains($file, '/') ? dirname($file) . '/' : '';
            foreach ($m[1] as $import) {
                $files[] = $dir . $import;
            }
        }

        return null;
    }

    /** `a/./b/../c` as `a/c`; null when it climbs out of the context. */
    private static function normalisePath(string $path): ?string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return $parts === [] ? null : implode('/', $parts);
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
