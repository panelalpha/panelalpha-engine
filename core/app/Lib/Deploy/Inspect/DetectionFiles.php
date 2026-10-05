<?php

namespace App\Lib\Deploy\Inspect;

/**
 * The files whose contents detection reads, picked from a repository's file
 * list so an inspection can fetch just those instead of cloning.
 *
 * Everything else is written as an empty file: detection reads an empty file
 * as "no match", the same as a missing one, and existence checks still see it.
 * The list was measured by logging every file AppInspector opens on real
 * clones; DetectionFilesTest pins the manifests' own content rules to it.
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

    /** Read wherever they sit within MAX_DEPTH. */
    private const NAMES = [
        // Django projects are found up to three levels down, by manage.py and wsgi.py.
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
     * Decisive files first, then the ones that only refine a build command,
     * then the symbolic links within reach of detection, each as a path
     * relative to the repository root.
     *
     * @param list<array{path: string, type: string, mode?: string, size?: ?int}> $entries a recursive tree
     * @param ?string $subdirectory where inspection starts, relative to the repository root
     * @return array{decisive: list<string>, refining: list<string>, links: list<string>}
     */
    public static function select(array $entries, ?string $subdirectory = null): array
    {
        $prefix = trim((string) $subdirectory, '/');
        $prefix = $prefix === '' ? '' : $prefix . '/';

        $decisive = [];
        $links = [];
        $goByDir = [];
        foreach ($entries as $entry) {
            if (($entry['type'] ?? '') !== 'blob') {
                continue;
            }
            $path = $entry['path'];
            if ($prefix !== '' && !str_starts_with($path, $prefix)) {
                continue;
            }
            $segments = explode('/', substr($path, strlen($prefix)));
            $name = strtolower((string) array_pop($segments));
            $depth = count($segments);

            if ($depth > 0 && in_array(strtolower($segments[0]), self::READ_WHOLE, true)) {
                $decisive[$path] = $depth;
                continue;
            }
            if ($depth > self::MAX_DEPTH || !self::searchable($segments)) {
                continue;
            }
            // A link's blob is its target. Written back as a link, it reads as
            // whatever it points at, as in a checkout.
            if (($entry['mode'] ?? '') === self::SYMLINK_MODE) {
                $links[] = $path;
                continue;
            }
            if (self::matches($name, self::NAMES, self::PATTERNS)
                || (self::matches($name, self::MANIFESTS, self::MANIFEST_PATTERNS) && self::manifestDepth($segments))
                // Python's start command reads the root scripts for a __main__ guard.
                || ($depth === 0 && str_ends_with($name, '.py'))
            ) {
                $decisive[$path] = $depth;
                continue;
            }
            if ($depth <= 2 && str_ends_with($name, '.go') && !str_ends_with($name, '_test.go')) {
                $dir = implode('/', $segments);
                // One file says which package a directory is; main.go says it best.
                if (!isset($goByDir[$dir]) || $name === 'main.go') {
                    $goByDir[$dir] = $path;
                }
            }
        }

        // Shallow first: the root decides more than anything below it.
        $byDepth = static fn (array $depths): \Closure =>
            static fn (string $a, string $b): int => [$depths[$a], $a] <=> [$depths[$b], $b];
        uksort($decisive, $byDepth($decisive));
        $goDepths = array_map(static fn (string $p): int => substr_count($p, '/'), $goByDir);
        $goFiles = array_combine(array_values($goByDir), array_values($goDepths));
        uksort($goFiles, $byDepth($goFiles));

        return [
            'decisive' => array_keys($decisive),
            // Go sources only say which package to build, and only beside a go.mod.
            'refining' => isset($decisive[$prefix . 'go.mod']) ? array_keys($goFiles) : [],
            'links' => $links,
        ];
    }

    /**
     * @param list<string> $names
     * @param list<string> $patterns
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
     * @param list<string> $segments
     */
    private static function manifestDepth(array $segments): bool
    {
        return count($segments) <= 1
            || (count($segments) === 2 && in_array(strtolower($segments[0]), self::WORKSPACE_GROUPS, true));
    }

    /**
     * @param list<string> $segments the directories above a file
     */
    private static function searchable(array $segments): bool
    {
        foreach ($segments as $segment) {
            $lower = strtolower($segment);
            if (in_array($lower, self::SKIP_DIRS, true)) {
                return false;
            }
            if (str_starts_with($segment, '.') && !in_array($lower, self::DOT_DIRS, true)) {
                return false;
            }
        }

        return true;
    }
}
