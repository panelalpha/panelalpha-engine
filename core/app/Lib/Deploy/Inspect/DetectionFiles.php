<?php

namespace App\Lib\Deploy\Inspect;

use App\Lib\Deploy\Platform\Runtime\DotnetRuntime;

/**
 * The files whose contents detection reads, picked from a repository's file list so an inspection fetches
 * those instead of cloning; everything else is written empty, which detection reads as "no match".
 */
final class DetectionFiles
{
    /** More than this many decisive files is a clone's job. */
    public const MAX_FILES = 60;

    /** A decisive file larger than this is a clone's job too. */
    public const MAX_BYTES = 1048576;

    /** Git's file mode for a symbolic link. */
    public const SYMLINK_MODE = '120000';

    /** Directory levels below the inspected root that are searched. */
    private const MAX_DEPTH = 3;

    /** Never read by detection, and where most of a big tree lives. */
    private const SKIP_DIRS = ['node_modules', 'vendor', 'bower_components', '.git'];

    /** The dot-directories detection does look into. */
    private const DOT_DIRS = ['.docker'];

    /** Read whole: the repository's own app config, and Cargo's linker settings. */
    private const READ_WHOLE = ['.panelalpha', '.cargo'];

    /**
     * Where a monorepo keeps its packages. NextWorkspaceProbe reads each one's
     * package.json and next.config.
     */
    private const WORKSPACE_GROUPS = ['apps', 'packages', 'clients', 'web'];

    /**
     * Read at the root, in a first-level app_root, and in a workspace package;
     * no deeper, where every package of a monorepo repeats them.
     */
    private const MANIFESTS = [
        'package.json', 'composer.json', 'composer.lock', 'pyproject.toml', 'requirements.txt',
        'pipfile', 'setup.py', 'setup.cfg', 'gemfile', 'gemfile.lock', 'go.mod', 'cargo.toml',
        'cargo.lock', 'pom.xml', 'build.gradle', 'build.gradle.kts', 'settings.gradle',
        'settings.gradle.kts', 'deno.json', 'deno.jsonc', 'mix.exs', 'angular.json', 'nest-cli.json',
        'package-lock.json', 'npm-shrinkwrap.json', 'yarn.lock', 'pnpm-lock.yaml',
        'pnpm-workspace.yaml', '.yarnrc.yml', '.npmrc',
        '.nvmrc', '.node-version', '.python-version', '.ruby-version', '.tool-versions',
        'runtime.txt', '.java-version', 'procfile',
    ];

    /** fnmatch() patterns that belong with MANIFESTS. */
    private const MANIFEST_PATTERNS = [
        'requirements*.txt',
        'next.config.*', 'nuxt.config.*', 'svelte.config.*', 'vite.config.*', 'vitest.config.*',
        'astro.config.*', 'remix.config.*', 'webpack.config.*', 'rollup.config.*',
        'quasar.config.*', 'electron.vite.config.*', 'gulpfile.*', 'gruntfile.*',
    ];

    /** Read wherever they sit within MAX_DEPTH, which is how a Django project's manage.py and wsgi.py are found. */
    private const NAMES = [
        'manage.py', 'app.py', 'main.py', 'server.py', 'wsgi.py', 'asgi.py',
        'global.json', '.dockerignore', '.env', 'panelalpha.yaml', 'panelalpha.md',
    ];

    /** fnmatch() patterns that belong with NAMES. */
    private const PATTERNS = [
        'dockerfile', 'dockerfile.*', 'dockerfile-*', 'dockerfile_*', '*.dockerfile',
        'containerfile', 'containerfile.*',
        'compose.yml', 'compose.yaml', 'compose.*.yml', 'compose.*.yaml', 'compose.yml.*',
        'docker-compose.yml', 'docker-compose.yaml', 'docker-compose.*', 'docker-compose-*.y*ml',
        '.env.*', '*.env',
        '*.csproj', '*.fsproj', '*.vbproj', '*.sln', '*.slnx', '*.props', '*.targets',
    ];

    /**
     * Decisive files, the Go sources that refine a build command (only beside a go.mod, as they only say which
     * package to build) and the links within reach of detection, each relative to the repository root.
     *
     * @param  list<array{path: string, type: string, mode?: string, size?: ?int}>  $entries  a recursive tree
     * @param  ?string  $subdirectory  where inspection starts, relative to the repository root
     * @return array{decisive: list<string>, refining: list<string>, links: list<string>}
     */
    public static function select(array $entries, ?string $subdirectory = null): array
    {
        $prefix = self::prefix($subdirectory);
        [$decisive, $links, $goByDir] = self::scan($entries, $prefix);

        $goDepths = array_map(static fn (string $p): int => substr_count($p, '/'), $goByDir);
        $goFiles = array_combine(array_values($goByDir), array_values($goDepths));
        $decisiveFirst = self::shallowFirst($decisive);
        $goFirst = self::shallowFirst($goFiles);

        return [
            'decisive' => $decisiveFirst,
            'refining' => isset($decisive[$prefix.'go.mod']) ? $goFirst : [],
            'links' => $links,
        ];
    }

    /**
     * The projects a root solution lists, with each one's Directory.Build.props, that select() left out as too deep or
     * skipped: detection reads every one, so they are fetched in a second round once the solutions are in.
     *
     * @param  list<array{path: string, type: string, mode?: string, size?: ?int}>  $entries  a recursive tree
     * @param  array{decisive: list<string>, refining: list<string>, links: list<string>}  $files  what select() picked
     * @param  \Closure(string): ?string  $contents  a decisive file's contents, by its path
     * @return list<string> relative to the repository root
     */
    public static function solutionListed(array $entries, array $files, ?string $subdirectory, \Closure $contents): array
    {
        $prefix = self::prefix($subdirectory);
        $picked = array_flip([...$files['decisive'], ...$files['links']]);
        $plain = self::plainFiles($entries);

        $listed = [];
        foreach (self::rootSolutions($files['decisive'], $prefix) as $solution) {
            foreach (DotnetRuntime::projectsListedIn($solution, (string) $contents($prefix.$solution)) as $project) {
                if (! isset($plain[$prefix.$project])) {
                    continue;
                }
                foreach ([$project, ...self::buildPropsAbove($project)] as $relative) {
                    if (isset($plain[$prefix.$relative]) && ! isset($picked[$prefix.$relative])) {
                        $listed[$prefix.$relative] = true;
                    }
                }
            }
        }
        $paths = array_keys($listed);
        sort($paths);

        return $paths;
    }

    /**
     * Every Directory.Build.props from a project's directory up to the root, the chain detection walks for a framework.
     *
     * @return list<string>
     */
    private static function buildPropsAbove(string $project): array
    {
        $props = [];
        for ($dir = dirname($project); $dir !== '.' && $dir !== ''; $dir = dirname($dir)) {
            $props[] = $dir.'/Directory.Build.props';
        }
        $props[] = 'Directory.Build.props';

        return $props;
    }

    private static function prefix(?string $subdirectory): string
    {
        $prefix = trim((string) $subdirectory, '/');

        return $prefix === '' ? '' : $prefix.'/';
    }

    /**
     * The solutions at the inspected root, relative to it.
     *
     * @param  list<string>  $decisive
     * @return list<string>
     */
    private static function rootSolutions(array $decisive, string $prefix): array
    {
        $solutions = [];
        foreach ($decisive as $path) {
            $relative = substr($path, strlen($prefix));
            if (! str_contains($relative, '/') && DotnetRuntime::isSolution($relative)) {
                $solutions[] = $relative;
            }
        }

        return $solutions;
    }

    /**
     * Every file of the tree that is not a link, as a set: a link is read as whatever it points at.
     *
     * @param  list<array{path: string, type: string, mode?: string, size?: ?int}>  $entries
     * @return array<string, true>
     */
    private static function plainFiles(array $entries): array
    {
        $plain = [];
        foreach ($entries as $entry) {
            if ($entry['type'] === 'blob' && ($entry['mode'] ?? '') !== self::SYMLINK_MODE) {
                $plain[$entry['path']] = true;
            }
        }

        return $plain;
    }

    /**
     * @param  list<array{path: string, type: string, mode?: string, size?: ?int}>  $entries
     * @return array{0: array<string, int>, 1: list<string>, 2: array<string, string>} decisive path => depth, links, Go source by directory
     */
    private static function scan(array $entries, string $prefix): array
    {
        $decisive = [];
        $links = [];
        $goByDir = [];
        foreach ($entries as $entry) {
            $located = self::locate($entry, $prefix);
            if ($located === null) {
                continue;
            }
            [$path, $segments, $name, $depth] = $located;
            $role = self::roleOf($entry, $segments, $name, $depth);
            if ($role === 'decisive') {
                $decisive[$path] = $depth;
            } elseif ($role === 'link') {
                $links[] = $path;
            } elseif ($role === 'go') {
                $goByDir = self::withGoSource($goByDir, implode('/', $segments), $name, $path);
            }
        }

        return [$decisive, $links, $goByDir];
    }

    /**
     * @param  array{path: string, type: string, mode?: string, size?: ?int}  $entry
     * @return array{0: string, 1: list<string>, 2: string, 3: int}|null path, directories, lowercase name, depth
     */
    private static function locate(array $entry, string $prefix): ?array
    {
        if (($entry['type'] ?? '') !== 'blob') {
            return null;
        }
        $path = $entry['path'];
        if ($prefix !== '' && ! str_starts_with($path, $prefix)) {
            return null;
        }
        $segments = explode('/', substr($path, strlen($prefix)));
        $name = strtolower((string) array_pop($segments));

        return [$path, $segments, $name, count($segments)];
    }

    /**
     * `decisive`, `link`, `go` or null. A link's blob is its target: written back as a link, it reads as
     * whatever it points at, as in a checkout.
     *
     * @param  array{path: string, type: string, mode?: string, size?: ?int}  $entry
     * @param  list<string>  $segments
     */
    private static function roleOf(array $entry, array $segments, string $name, int $depth): ?string
    {
        if ($depth > 0 && in_array(strtolower($segments[0]), self::READ_WHOLE, true)) {
            return 'decisive';
        }
        if ($depth > self::MAX_DEPTH || ! self::searchable($segments)) {
            return null;
        }
        if (($entry['mode'] ?? '') === self::SYMLINK_MODE) {
            return 'link';
        }
        if (self::isDecisive($name, $segments, $depth)) {
            return 'decisive';
        }

        return $depth <= 2 && str_ends_with($name, '.go') && ! str_ends_with($name, '_test.go') ? 'go' : null;
    }

    /**
     * Python's start command reads the root scripts for a __main__ guard.
     *
     * @param  list<string>  $segments
     */
    private static function isDecisive(string $name, array $segments, int $depth): bool
    {
        return self::matches($name, self::NAMES, self::PATTERNS)
            || (self::matches($name, self::MANIFESTS, self::MANIFEST_PATTERNS) && self::manifestDepth($segments))
            || ($depth === 0 && str_ends_with($name, '.py'));
    }

    /**
     * One file says which package a directory is; main.go says it best.
     *
     * @param  array<string, string>  $goByDir
     * @return array<string, string>
     */
    private static function withGoSource(array $goByDir, string $dir, string $name, string $path): array
    {
        if (! isset($goByDir[$dir]) || $name === 'main.go') {
            $goByDir[$dir] = $path;
        }

        return $goByDir;
    }

    /**
     * Shallow first, then by path: the root decides more than anything below it.
     *
     * @param  array<string, int>  $depths  path => depth
     * @return list<string>
     */
    private static function shallowFirst(array $depths): array
    {
        uksort($depths, static fn (string $a, string $b): int => [$depths[$a], $a] <=> [$depths[$b], $b]);

        return array_keys($depths);
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $patterns
     */
    private static function matches(string $name, array $names, array $patterns): bool
    {
        if (in_array($name, $names, true)) {
            return true;
        }
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $segments
     */
    private static function manifestDepth(array $segments): bool
    {
        return count($segments) <= 1
            || (count($segments) === 2 && in_array(strtolower($segments[0]), self::WORKSPACE_GROUPS, true));
    }

    /**
     * @param  list<string>  $segments  the directories above a file
     */
    private static function searchable(array $segments): bool
    {
        foreach ($segments as $segment) {
            $lower = strtolower($segment);
            if (in_array($lower, self::SKIP_DIRS, true)) {
                return false;
            }
            if (str_starts_with($segment, '.') && ! in_array($lower, self::DOT_DIRS, true)) {
                return false;
            }
        }

        return true;
    }
}
