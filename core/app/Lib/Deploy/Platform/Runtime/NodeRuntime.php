<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;
use Symfony\Component\Yaml\Yaml;

/**
 * Node, versioned by `engines.node`, then `.nvmrc`, then `.node-version`; the default is the oldest maintained LTS.
 * A project compiling a dependency gets `bookworm`, not `-slim`: node-gyp needs python3, make and g++.
 */
final class NodeRuntime implements Runtime
{
    /**
     * Majors the engine seeds into accounts, oldest first — the compiled-in
     * fallback for the catalogue's `versions`.
     *
     * @var list<int>
     */
    public const MAJORS = [18, 20, 22, 24];

    /** The major a project that states no version gets: the oldest maintained LTS. */
    public const DEFAULT_MAJOR = 22;

    /** The slim variant, which carries no C toolchain. */
    public const IMAGE_VARIANT = 'bookworm-slim';

    /**
     * What a JS recipe writes when it has no better answer for `start`, matched literally so that only
     * a recipe's own default is corrected ({@see withEntryPointDefault()}).
     */
    public const FALLBACK_START = 'node index.js';

    /**
     * What `npm run` would have put on PATH, for a build script run without
     * it (see {@see nginxAssetBuildCommand()}).
     */
    public const NODE_MODULES_BIN_PATH = 'export PATH=/app/node_modules/.bin:$PATH';

    /**
     * The variant a project compiling a dependency gets: the same Debian release and major, without `-slim`,
     * carrying the python3, make and g++ `node-gyp rebuild` needs.
     */
    public const TOOLCHAIN_VARIANT = 'bookworm';

    /**
     * Packages that run `git` during the build to stamp a revision into an artifact, and fail without it.
     * Named, not matched on `/git/i`: evidence that a package invokes git, not a guess from its name.
     *
     * @var list<string>
     */
    private const GIT_AT_BUILD_DEPENDENCIES = [
        'vite-plugin-pwa',
        'next-sitemap',
        '@content-collections/core',
        '@content-collections/cli',
        '@content-collections/vite',
        'git-revision-webpack-plugin',
        'child-process-git-revision',
        'git-describe',
        'git-commit-info',
        'last-commit-log',
        'rollup-plugin-git-version',
    ];

    /**
     * Build-config stems whose contents might run git; read, since a name says nothing (fluidd's git call
     * is in `vite.config.inject-version.ts`).
     *
     * @var list<string>
     */
    private const GIT_AT_BUILD_CONFIGS = [
        'vite.config',
        'vitest.config',
        'webpack.config',
        'rollup.config',
        'next.config',
        'nuxt.config',
        'astro.config',
        'svelte.config',
        'quasar.config',
        'electron.vite.config',
    ];

    /**
     * Packages whose install compiles a C/C++ addon (node-pty 1.x has no linux-x64 prebuild), not the helpers
     * like `nan` that ship prebuilds; still only evidence for {@see hasNativeDependency()}.
     *
     * @var list<string>
     */
    private const NATIVE_DEPENDENCIES = [
        'better-sqlite3',
        'sqlite3',
        'bcrypt',
        'argon2',
        'canvas',
        'node-canvas',
        'skia-canvas',
        'heapdump',
        'node-sass',
        'cytubefilters',
        'robotjs',
        'serialport',
        'usb',
        'ffi',
        'ffi-napi',
        'oracledb',
        'odbc',
        'pg-native',
        're2',
        'leveldown',
        'rocksdb',
        'sodium-native',
        'libsodium-wrappers-sumo',
        'kerberos',
        'node-rdkafka',
        'duckdb',
        'node-pty',
    ];

    /**
     * Build toolchains that bundle webpack 4 below the given major. Webpack 4
     * hashes with MD4, which OpenSSL 3 (Node 17+) refuses.
     *
     * @var array<string, int>
     */
    private const WEBPACK4_TOOLCHAINS = [
        'webpack' => 5,
        'react-scripts' => 5,
        '@vue/cli-service' => 5,
        'laravel-mix' => 6,
        'nuxt' => 3,
    ];

    /**
     * Shell that adds `--openssl-legacy-provider` to NODE_OPTIONS when the
     * running Node is 17 or newer; older Node rejects the flag.
     */
    public const LEGACY_OPENSSL_ENV = '{ case "$(node -v 2>/dev/null)" in v1[7-9].*|v[2-9][0-9].*) '
        .'export NODE_OPTIONS="${NODE_OPTIONS:+$NODE_OPTIONS }--openssl-legacy-provider";; esac; }';

    /**
     * Lockfiles whose text is worth scanning, in the order they are read. `bun.lockb` is binary and absent:
     * a bun project needing a native build is missed, not misread.
     *
     * @var list<string>
     */
    private const LOCKFILES = ['package-lock.json', 'npm-shrinkwrap.json', 'pnpm-lock.yaml', 'yarn.lock'];

    /**
     * The sections of package.json {@see mentionsNodeGyp()} reads: the dependency maps, and `scripts`,
     * where a project writes its own `node-gyp rebuild`.
     *
     * @var list<string>
     */
    private const MANIFEST_SECTIONS = [
        'dependencies',
        'devDependencies',
        'optionalDependencies',
        'peerDependencies',
        'scripts',
    ];

    /**
     * A const expression, so it cannot read the catalogue: for callers needing
     * a compile-time constant. {@see defaultImage()} honours a configured one.
     */
    public const IMAGE = 'node:'.self::DEFAULT_MAJOR.'-'.self::IMAGE_VARIANT;

    /**
     * Above this a declared major is a typo whose pull would 404. No floor: an ancient `engines.node: 4`
     * resolves to the oldest shipped major, closer than the default.
     */
    public const MAX_PLAUSIBLE_MAJOR = 28;

    /**
     * The majors a project may resolve to, oldest first.
     *
     * @return list<string>
     */
    public static function majors(): array
    {
        $configured = RuntimeImageCatalog::versions('node');
        if ($configured !== []) {
            return $configured;
        }

        return array_map(static fn (int $major): string => (string) $major, self::MAJORS);
    }

    public static function defaultMajor(): string
    {
        return RuntimeImageCatalog::defaultVersion('node') ?? (string) self::DEFAULT_MAJOR;
    }

    public static function defaultImage(): string
    {
        return self::imageTag(self::defaultMajor());
    }

    public static function imageTag(string $major): string
    {
        $spec = RuntimeImageCatalog::spec('node', $major);

        return $spec?->from ?? 'node:'.$major.'-'.self::IMAGE_VARIANT;
    }

    /**
     * The one entry point for "which Node does this project get": a second copy once seeded one image and ran
     * another.
     *
     * @param  array<string, mixed>  $package  an already-parsed package.json, when the caller has one
     */
    public static function imageFor(string $projectDir, array $package = []): string
    {
        $projectDir = rtrim($projectDir, '/');
        if ($projectDir === '') {
            return self::defaultImage();
        }

        $context = ProjectContext::at($projectDir);
        [, , $major] = self::selectedVersion($context, $package);

        return self::withToolchain(self::imageTag($major ?? self::defaultMajor()), $context, $package);
    }

    /**
     * $tag, or its full variant when this project has to compile something; applied to a frozen decision's tag
     * too, so a Dockerfile and a host compile use one image.
     *
     * @param  array<string, mixed>  $package  an already-parsed package.json
     */
    public static function withToolchain(
        string $tag,
        ProjectContext $context,
        array $package = []
    ): string {
        return self::hasNativeDependency($context, $package)
            || self::needsGitBinary($context, $package)
            ? self::toolchainImage($tag)
            : $tag;
    }

    /**
     * Does the build run `git`, which the host compile's slim image lacks? Only direct declarations count:
     * a script, a build config, a declared package, or a dependency fetched over git.
     *
     * @param  array<string, mixed>  $package  an already-parsed package.json, if the caller has one
     */
    public static function needsGitBinary(ProjectContext $context, array $package = []): bool
    {
        $dependencies = $package === [] ? ($context->package() ?? []) : $package;

        return self::scriptsInvokeGit($context)
            || self::buildConfigInvokesGit($context)
            || self::declaresGitAtBuildDependency($dependencies)
            || self::installsFromGit($context, $dependencies);
    }

    private static function scriptsInvokeGit(ProjectContext $context): bool
    {
        foreach ($context->scripts() as $script) {
            if (self::invokesGit((string) $script)) {
                return true;
            }
        }

        return false;
    }

    /** Skipped without a directory (`ProjectContext::at('')`): `firstConfigContents()` would `scandir('')` and throw. */
    private static function buildConfigInvokesGit(ProjectContext $context): bool
    {
        if ($context->projectDir !== '') {
            foreach (self::GIT_AT_BUILD_CONFIGS as $stem) {
                $contents = $context->configContents($stem);
                if ($contents !== null && self::invokesGit($contents)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $dependencies */
    private static function declaresGitAtBuildDependency(array $dependencies): bool
    {
        foreach (self::GIT_AT_BUILD_DEPENDENCIES as $name) {
            foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'] as $section) {
                if (isset(($dependencies[$section] ?? [])[$name])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Does this text literally run git: the word, any global options (`-C /app`, the engine's own idiom),
     * then a subcommand, so `husky install`, `digit-check` and `.gitignore` do not count?
     */
    private static function invokesGit(string $text): bool
    {
        return preg_match(
            '/(?:^|[\s\'"&|;(=\[`])git(?:\s+'.self::GIT_GLOBAL_OPTION.')*'
            .'\s+(?:rev-parse|log|describe|show|status|diff|branch|tag|config|ls-files|archive|submodule)\b/',
            $text
        ) === 1;
    }

    /**
     * A git option that may sit between `git` and its subcommand; `\s*\S+` covers `-c key=value` and `-C path`
     * with or without a space.
     */
    private const GIT_GLOBAL_OPTION =
        '(?:-[cC]\s*\S+|--(?:git-dir|work-tree|exec-path|namespace)=\S+|--no-pager|--bare|-[pP])';

    /**
     * Does a dependency resolve over git, so `npm install` clones before any script runs? tine's
     * `git+ssh://` dependencies failed with `git dep preparation failed`.
     */
    private static function installsFromGit(ProjectContext $context, array $dependencies): bool
    {
        foreach (['dependencies', 'devDependencies', 'optionalDependencies'] as $section) {
            foreach ((array) ($dependencies[$section] ?? []) as $spec) {
                if (is_string($spec) && self::isGitSpec($spec)) {
                    return true;
                }
            }
        }

        return self::lockfileInstallsFromGit($context);
    }

    /** A transitive git dependency appears only in a lockfile, read whole, once per build decision. */
    private static function lockfileInstallsFromGit(ProjectContext $context): bool
    {
        foreach (self::LOCKFILES as $lockfile) {
            $contents = $context->contents($lockfile);
            if ($contents !== null && preg_match(self::GIT_IN_LOCKFILE, $contents) === 1) {
                return true;
            }
        }

        return false;
    }

    /** A git source as a lockfile records it: npm's `git+ssh://`, yarn's `….git#<sha>` (grocy), pnpm's `type: git`. */
    private const GIT_IN_LOCKFILE = '~git\+|git://|\.git#|\btype: git\b~';

    /**
     * A dependency value npm or yarn fetches by cloning: a git URL or host prefix, an https URL to a `.git`
     * repository (grocy's), or the bare `owner/repo` GitHub shorthand.
     */
    private static function isGitSpec(string $spec): bool
    {
        $spec = trim($spec);

        return preg_match('#^(?:git\+|git://|github:|bitbucket:|gitlab:)#', $spec) === 1
            || preg_match('#^https?://[^\s\#]+\.git(?:\#|$)#', $spec) === 1
            || preg_match('#^[A-Za-z0-9][\w.-]*/[\w.-]+(?:\#\S*)?$#', $spec) === 1;
    }

    /**
     * $tag without `-slim`, the full image that can run `node-gyp`; from the resolved tag, so a custom registry
     * is kept. A tag not ending in the slim variant is returned untouched.
     */
    public static function toolchainImage(string $tag): string
    {
        $suffix = '-'.self::IMAGE_VARIANT;

        return str_ends_with($tag, $suffix)
            ? substr($tag, 0, -strlen($suffix)).'-'.self::TOOLCHAIN_VARIANT
            : $tag;
    }

    /**
     * Does installing this project compile something the slim image cannot? `binding.gyp`, a declared native
     * package or node-gyp, or one the lock marks `hasInstallScript`; merely resolving one fires on every tree.
     *
     * @param  array<string, mixed>  $package  an already-parsed package.json, if the caller has one
     */
    public static function hasNativeDependency(ProjectContext $context, array $package = []): bool
    {
        if ($context->hasFile('binding.gyp')) {
            return true;
        }

        $dependencies = $package === [] ? ($context->package() ?? []) : $package;

        if (self::mentionsNodeGyp($dependencies)) {
            return true;
        }
        if (self::declaresNativeDependency($dependencies)) {
            return true;
        }
        if (self::pnpmAllowsNativeBuild($context, $dependencies)) {
            return true;
        }

        $lock = self::lockfileText($context);

        return $lock !== '' && self::lockMarksNative($lock);
    }

    /**
     * Does this project build with webpack 4? A declared toolchain decides; without one, a lockfile that
     * resolves webpack 4 and no webpack 5 does.
     *
     * @param  array<string, mixed>  $package
     */
    public static function needsLegacyOpenssl(ProjectContext $context, array $package = []): bool
    {
        $package = $package === [] ? ($context->package() ?? []) : $package;
        foreach (['dependencies', 'devDependencies'] as $section) {
            $declared = is_array($package[$section] ?? null) ? $package[$section] : [];
            foreach (self::WEBPACK4_TOOLCHAINS as $name => $fixedIn) {
                $constraint = $declared[$name] ?? null;
                if (! is_string($constraint) || preg_match('/^[\s^~=v<>]*\d/', $constraint) !== 1) {
                    continue;
                }
                $major = self::parseMajor($constraint);
                if ($major !== null) {
                    return $major < $fixedIn;
                }
            }
        }

        $lock = self::lockfileText($context);
        if ($lock === '') {
            return false;
        }
        $resolves = static fn (string $major): bool => preg_match(
            '~(?:"node_modules/webpack"|"webpack"):\s*\{\s*"version":\s*"'.$major.'\.'
                .'|^"?webpack@[^\n]*:\n\s+version:?\s+"?'.$major.'\.'
                .'|^\s+/?webpack@'.$major.'\.~m',
            $lock
        ) === 1;

        return $resolves('4') && ! $resolves('5');
    }

    /**
     * $build, run with OpenSSL's legacy provider when the project in
     * $projectDir builds with webpack 4.
     */
    public static function withLegacyOpenssl(string $build, string $projectDir): string
    {
        if (trim($build) === '' || $projectDir === '' || ! self::needsLegacyOpenssl(ProjectContext::at($projectDir))) {
            return $build;
        }

        return self::LEGACY_OPENSSL_ENV.' && '.$build;
    }

    /**
     * Does the parsed package.json name node-gyp anywhere — as a dependency,
     * or in a script that runs it?
     *
     * @param  array<string, mixed>  $package
     */
    private static function mentionsNodeGyp(array $package): bool
    {
        foreach (self::MANIFEST_SECTIONS as $section) {
            if (self::mentions($package[$section] ?? null, 'node-gyp')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the parsed package.json name one of {@see NATIVE_DEPENDENCIES}?
     *
     * @param  array<string, mixed>  $package
     */
    private static function declaresNativeDependency(array $package): bool
    {
        $mapSections = ['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'];
        foreach ($mapSections as $section) {
            $declared = $package[$section] ?? null;
            if (! is_array($declared)) {
                continue;
            }
            foreach (array_keys($declared) as $name) {
                if (is_string($name) && self::isNativeName(strtolower($name))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Does the pnpm build allowlist name a native dependency? pnpm's lock records no install scripts.
     * Read from package.json `pnpm` and pnpm-workspace.yaml; an unreadable one is no evidence either way.
     *
     * @param  array<string, mixed>  $package
     */
    private static function pnpmAllowsNativeBuild(ProjectContext $context, array $package): bool
    {
        $sources = [is_array($package['pnpm'] ?? null) ? $package['pnpm'] : []];
        if ($context->hasFile('pnpm-workspace.yaml')) {
            try {
                $workspace = Yaml::parse((string) $context->contents('pnpm-workspace.yaml'));
                if (is_array($workspace)) {
                    $sources[] = $workspace;
                }
            } catch (\Throwable) {
            }
        }

        foreach ($sources as $policy) {
            $names = is_array($policy['onlyBuiltDependencies'] ?? null) ? $policy['onlyBuiltDependencies'] : [];
            foreach ((is_array($policy['allowBuilds'] ?? null) ? $policy['allowBuilds'] : []) as $name => $allowed) {
                if ($allowed === true) {
                    $names[] = $name;
                }
            }
            foreach ($names as $name) {
                if (is_string($name) && self::isNativeName(strtolower($name))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Does the lockfile say "this will compile": npm's `hasInstallScript` on a package this list recognises?
     * See {@see hasNativeDependency()}.
     */
    private static function lockMarksNative(string $lock): bool
    {
        foreach (self::NATIVE_DEPENDENCIES as $name) {
            $path = 'node_modules/'.preg_quote($name, '/');
            if (preg_match('~"?[^"\s]*'.$path.'"?[^}]*hasinstallscript~', $lock) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is $token exactly one of {@see NATIVE_DEPENDENCIES}?
     */
    private static function isNativeName(string $token): bool
    {
        foreach (self::NATIVE_DEPENDENCIES as $name) {
            if ($token === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the map mention $needle in a key (a dependency map's package) or a value (a `scripts` body)?
     */
    private static function mentions(mixed $map, string $needle): bool
    {
        if (! is_array($map)) {
            return false;
        }
        $pattern = '/(?<![a-z0-9_.-])'.preg_quote($needle, '/').'(?![a-z0-9_.-])/';
        foreach ($map as $key => $value) {
            foreach ([$key, $value] as $candidate) {
                if (is_string($candidate) && preg_match($pattern, strtolower($candidate)) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The first lockfile under the project root, lowercased as {@see ProjectContext::listRootFiles()} lists names.
     * JSON's `\/` is unescaped first, or `node_modules\/argon2` would miss every path searched for.
     */
    private static function lockfileText(ProjectContext $context): string
    {
        foreach (self::LOCKFILES as $name) {
            if (! $context->hasFile($name)) {
                continue;
            }
            $raw = $context->contents($name);
            if (is_string($raw) && $raw !== '') {
                return strtolower(str_replace('\\/', '/', $raw));
            }
        }

        return '';
    }

    /**
     * The first shipped major at or above a constraint (an open floor gets the default, a range its newest), or a
     * plausible newer one; null, not the default, when unbelievable, so pinning the default is still credited.
     */
    private static function resolveMajor(string $raw): ?string
    {
        $major = self::parseMajor($raw);
        if ($major === null) {
            return null;
        }

        $range = self::openRange($raw);
        if ($range !== null) {
            [$floor, $ceiling] = $range;
            $inRange = array_values(array_filter(
                self::majors(),
                static fn (string $known): bool => (int) $known >= $floor
                    && ($ceiling === null || (int) $known <= $ceiling)
            ));
            if ($ceiling !== null && $inRange !== []) {
                return end($inRange);
            }
            if ($ceiling === null) {
                $major = max($floor, (int) self::defaultMajor());
            }
        }

        foreach (self::majors() as $known) {
            if ((int) $known >= $major) {
                return $known;
            }
        }

        return $major <= self::MAX_PLAUSIBLE_MAJOR ? (string) $major : null;
    }

    public function id(): string
    {
        return 'node';
    }

    /** Reported as the engine's when unresolved: crediting package.json for a version it did not produce misleads. */
    public function resolve(ProjectContext $context): ?Requirement
    {
        if (! $context->hasFile('package.json')) {
            return null;
        }

        [$raw, $source, $resolved] = self::selectedVersion($context);
        if ($raw === '') {
            return new Requirement('node', self::defaultMajor(), '', 'engine default');
        }

        return new Requirement(
            'node',
            $resolved ?? self::defaultMajor(),
            $raw,
            $resolved === null ? 'engine default' : $source
        );
    }

    public function image(Requirement $requirement): string
    {
        return self::imageTag($requirement->version);
    }

    /**
     * The declared version, raised to the oldest major the toolchain runs on: always a pinned pnpm 11's,
     * a direct dependency's `engines.node` only when the project declared nothing (Angular 22).
     *
     * @param  array<string, mixed>  $package  an already-parsed package.json
     * @return array{0: string, 1: string, 2: ?string} raw constraint, source, resolved major
     */
    private static function selectedVersion(ProjectContext $context, array $package = []): array
    {
        $package = $package !== [] ? $package : ($context->package() ?? []);
        [$raw, $source] = self::declaredVersion($context, $package);
        $major = self::resolveMajor($raw);

        $floors = [self::packageManagerFloor($package)];
        if ($major === null) {
            $floors[] = self::dependencyFloor($context, $package);
        }
        foreach ($floors as $floor) {
            if ($floor === null) {
                continue;
            }
            $floorMajor = self::resolveMajor($floor[0]);
            if ($floorMajor !== null && (int) $floorMajor > (int) ($major ?? self::defaultMajor())) {
                [$raw, $source, $major] = [$floor[0], $floor[1], $floorMajor];
            }
        }

        return [$raw, $source, $major];
    }

    /**
     * Node a pinned package manager needs, from its own `engines.node`.
     * Only majors with a floor above what the images have always shipped.
     *
     * @param  array<string, mixed>  $package
     * @return array{0: string, 1: string}|null
     */
    private static function packageManagerFloor(array $package): ?array
    {
        $field = $package['packageManager'] ?? null;
        if (! is_string($field) || preg_match('/^pnpm@(\d+)/', trim($field), $m) !== 1 || (int) $m[1] < 11) {
            return null;
        }

        return ['>=22.13', 'package.json packageManager '.trim($field)];
    }

    /**
     * The highest `engines.node` floor among the root's direct dependencies,
     * as the lockfile recorded it. Yarn and Bun lockfiles carry no engines.
     *
     * @param  array<string, mixed>  $package
     * @return array{0: string, 1: string}|null
     */
    private static function dependencyFloor(ProjectContext $context, array $package): ?array
    {
        $names = [];
        foreach (['dependencies', 'devDependencies'] as $section) {
            foreach (array_keys(is_array($package[$section] ?? null) ? $package[$section] : []) as $name) {
                $names[] = (string) $name;
            }
        }
        if ($names === [] || $context->projectDir === '') {
            return null;
        }

        foreach (['package-lock.json', 'npm-shrinkwrap.json', 'pnpm-lock.yaml'] as $lockfile) {
            if (! $context->hasFile($lockfile)) {
                continue;
            }
            $engines = $lockfile === 'pnpm-lock.yaml'
                ? self::pnpmLockEngines((string) $context->contents($lockfile))
                : self::npmLockEngines($context->json($lockfile) ?? []);

            $best = null;
            foreach ($names as $name) {
                $constraint = $engines[$name] ?? null;
                $major = $constraint === null ? null : self::parseMajor($constraint);
                if ($major !== null && ($best === null || $major > $best[2])) {
                    $best = [$constraint, $name.' engines.node in '.$lockfile, $major];
                }
            }

            return $best === null ? null : [$best[0], $best[1]];
        }

        return null;
    }

    /**
     * `engines.node` per top-level package in an npm lockfile (v2/v3).
     *
     * @param  array<string, mixed>  $lock
     * @return array<string, string>
     */
    private static function npmLockEngines(array $lock): array
    {
        $engines = [];
        foreach ((array) ($lock['packages'] ?? []) as $path => $entry) {
            if (! is_string($path) || ! str_starts_with($path, 'node_modules/') || ! is_array($entry)) {
                continue;
            }
            $name = substr($path, strlen('node_modules/'));
            $node = $entry['engines']['node'] ?? null;
            if (! str_contains($name, '/node_modules/') && is_string($node)) {
                $engines[$name] = $node;
            }
        }

        return $engines;
    }

    /**
     * `engines.node` per package in a pnpm lockfile, read line by line from `packages:` headers, v9's
     * `'@scope/name@1.2.3':` or v6's `/@scope/name@1.2.3:`.
     *
     * @return array<string, string>
     */
    private static function pnpmLockEngines(string $lock): array
    {
        $engines = [];
        $current = null;
        $inPackages = false;
        foreach (preg_split('/\R/', $lock) ?: [] as $line) {
            if ($line !== '' && $line[0] !== ' ') {
                $inPackages = rtrim($line) === 'packages:';
                $current = null;

                continue;
            }
            if (! $inPackages) {
                continue;
            }
            if (preg_match("#^  '?/?((?:@[^/\\s']+/)?[^@\\s'/]+)@\\S*:\\s*$#", $line, $m) === 1) {
                $current = $m[1];

                continue;
            }
            if ($current !== null && preg_match("/^    engines: \\{node: '?([^',}]+)'?/", $line, $m) === 1) {
                $engines[$current] ??= trim($m[1]);
            }
        }

        return $engines;
    }

    /**
     * @param  array<string, mixed>  $package  an already-parsed package.json
     * @return array{0: string, 1: string} the raw constraint and where it came from
     */
    private static function declaredVersion(ProjectContext $context, array $package = []): array
    {
        $package = $package !== [] ? $package : ($context->package() ?? []);
        $engines = $package['engines'] ?? null;
        if (is_array($engines) && isset($engines['node']) && is_string($engines['node'])) {
            return [$engines['node'], 'package.json engines.node'];
        }

        foreach (['.nvmrc', '.node-version'] as $name) {
            $contents = $context->contents($name);
            if (is_string($contents) && trim($contents) !== '') {
                return [trim($contents), $name];
            }
        }

        return ['', ''];
    }

    /**
     * `[floor, ceiling]` majors of `>=N`/`>N` with an optional `<M`/`<=M`; null for anything else.
     * `>14` is `>=15` but `>14.2` allows 14; `<21` and `<21.0.0` exclude 21, `<21.5` does not.
     *
     * @return array{0: int, 1: ?int}|null
     */
    private static function openRange(string $constraint): ?array
    {
        $pattern = '/^>(=)?\s*v?(\d+)(?:\.(\d+|x|\*))?(?:\.(\d+|x|\*))?'
            .'(?:\s*,?\s*<(=)?\s*v?(\d+)(?:\.(\d+))?(?:\.(\d+))?)?$/i';
        if (preg_match($pattern, trim($constraint), $m) !== 1) {
            return null;
        }

        $floor = (int) $m[2];
        if (($m[1] ?? '') === '' && ($m[3] ?? '') === '') {
            $floor++;
        }

        $ceiling = null;
        if (($m[6] ?? '') !== '') {
            $ceiling = (int) $m[6];
            $lowerParts = (int) ($m[7] ?? 0) + (int) ($m[8] ?? 0);
            if (($m[5] ?? '') === '' && $lowerParts === 0) {
                $ceiling--;
            }
        }

        return [$floor, $ceiling];
    }

    /**
     * Lowest major a constraint allows: `>=18`, `^20.1.0`, `18.x`, `lts/*`.
     */
    private static function parseMajor(string $constraint): ?int
    {
        $constraint = trim($constraint);
        if ($constraint === '' || str_starts_with($constraint, 'lts')) {
            return null;
        }
        if (preg_match('/(\d+)/', $constraint, $matches) !== 1) {
            return null;
        }
        $major = (int) $matches[1];

        return $major > 0 ? $major : null;
    }

    /**
     * A manifest's defaults adapted to this project's lockfile, scripts and workspace, which the manifest
     * cannot know; hence `{{js.build:npx next build}}` instead of a literal.
     *
     * @param  array<string, mixed>  $partial  the manifest's defaults
     * @param  array<string, true>  $files
     * @return array<string, mixed>
     */
    public static function finalizeProject(array $partial, string $projectDir, array $files): array
    {
        $context = ProjectContext::make($projectDir, $files);
        $package = $context->package() ?? [];
        $pm = JsPackageManager::detectPackageManager($files, $package);
        $install = JsPackageManager::installCommand($pm, $files, $package, $context->projectDir);

        return self::withEntryPointDefault(
            self::finalizeWith($partial, $pm, $install, $context->scripts()),
            $package,
            $context
        );
    }

    /**
     * A recipe's `node index.js` default pointed at the declared entry point (CNCjs), but only when no index.js
     * exists: a stale library `main` beside a real index.js got MODULE_NOT_FOUND.
     *
     * @param  array<string, mixed>  $resolved  the decision `finalizeWith` produced
     * @param  array<string, mixed>  $package  parsed package.json
     */
    private static function withEntryPointDefault(
        array $resolved,
        array $package,
        ProjectContext $context
    ): array {
        if (($resolved['start_command'] ?? '') !== self::FALLBACK_START) {
            return $resolved;
        }

        if ($context->isFile('index.js')) {
            return $resolved;
        }

        $main = self::entryPoint($package, $context);
        if ($main !== null) {
            $resolved['start_command'] = 'node '.$main;
        }

        return $resolved;
    }

    /**
     * `bin` (a program you run; CNCjs's `main` only exports), else `main`, both possibly built later, so only
     * their shape is checked; else a root `server.js` that exists.
     */
    private static function entryPoint(array $package, ProjectContext $context): ?string
    {
        foreach ([self::firstBin($package), $package['main'] ?? null] as $declared) {
            if (is_string($declared) && self::isRelativeEntryPoint($declared)) {
                return preg_replace('#^\./#', '', ltrim(trim($declared), '/')) ?? trim($declared);
            }
        }

        return $context->isFile('server.js') ? 'server.js' : null;
    }

    /**
     * The `bin` path for the package itself, string or map: the entry named after the package, not the first
     * by JSON key order, else the first (CNCjs declares only `cnc`).
     *
     * @param  array<string, mixed>  $package
     */
    private static function firstBin(array $package): ?string
    {
        $bin = $package['bin'] ?? null;
        if (is_string($bin)) {
            return $bin;
        }
        if (! is_array($bin)) {
            return null;
        }

        $name = $package['name'] ?? null;
        if (is_string($name) && is_string($bin[$name] ?? null) && $bin[$name] !== '') {
            return $bin[$name];
        }

        foreach ($bin as $path) {
            if (is_string($path) && $path !== '') {
                return $path;
            }
        }

        return null;
    }

    /** A relative path inside the application, and nothing else. */
    private static function isRelativeEntryPoint(string $candidate): bool
    {
        $candidate = trim($candidate);

        return $candidate !== ''
            && ! str_starts_with($candidate, '/')
            && ! str_contains($candidate, '..');
    }

    /**
     * A recipe's partial filled in from the project's package manager and scripts: an author's own
     * `build`/`start` script beats the recipe's default, there only for projects that ship neither.
     *
     * @param  array<string, mixed>  $partial
     * @param  array<string, mixed>  $scripts
     * @return array{
     *   strategy: string,
     *   label: string,
     *   runtime: string,
     *   port_hint: int,
     *   output_directory: string,
     *   package_manager: string,
     *   install_command: string,
     *   build_command: string,
     *   start_command: string,
     *   env: array<string, string>,
     * }
     */
    private static function finalizeWith(
        array $partial,
        string $pm,
        string $install,
        array $scripts
    ): array {
        $runtime = $partial['runtime'];
        $build = self::buildCommandFor($runtime, $pm, $scripts, $partial);

        $start = '';
        if ($runtime === 'node') {
            $start = JsPackageManager::resolveLifecycleCommand($pm, $scripts, 'start', $partial);
        }

        $port = (int) $partial['port_hint'];
        $env = self::runtimeEnvironment($port, $partial);

        return [
            'strategy' => $partial['strategy'],
            'label' => $partial['label'],
            'runtime' => $runtime,
            'port_hint' => $port,
            'output_directory' => $partial['output_directory'] ?? 'dist',
            'package_manager' => $pm,
            'install_command' => $install,
            'build_command' => $build,
            'start_command' => $start,
            'setup_command' => JsPackageManager::hasScript($scripts, 'setup')
                ? JsPackageManager::scriptCommand($pm, 'setup')
                : null,
            'env' => $env,
        ];
    }

    /**
     * A workspace app (a Next export under apps/) builds through its own `build:<slug>` or a filtered turbo.
     *
     * @param  array<string, mixed>  $scripts
     * @param  array<string, mixed>  $partial
     */
    private static function buildCommandFor(mixed $runtime, string $pm, array $scripts, array $partial): string
    {
        if ($runtime === 'nginx' && trim((string) ($partial['workspace_relative'] ?? ''), '/') === '') {
            return self::nginxAssetBuildCommand($pm, $scripts, $partial);
        }

        return JsPackageManager::resolveLifecycleCommand($pm, $scripts, 'build', $partial);
    }

    /**
     * Listening on every interface at the port hint, husky quiet at runtime too, then the manifest's own env.
     *
     * @param  array<string, mixed>  $partial
     * @return array<string, string>
     */
    private static function runtimeEnvironment(int $port, array $partial): array
    {
        $env = [
            'HOST' => '0.0.0.0',
            'HOSTNAME' => '0.0.0.0',
            'PORT' => (string) $port,
            'HUSKY' => '0',
            'CI' => '1',
        ];
        if (! empty($partial['env']) && is_array($partial['env'])) {
            $env = array_merge($env, $partial['env']);
        }

        return $env;
    }

    /**
     * Static HTML for nginx: `build-only`, else `build`, whose typecheck prefixes are dropped and the rest run with
     * node_modules/.bin exported, since a `PATH=… cmd` prefix reaches only the first command (reveal.js).
     *
     * @param  array<string, mixed>  $scripts
     * @param  array<string, mixed>  $partial
     */
    public static function nginxAssetBuildCommand(string $pm, array $scripts, array $partial = []): string
    {
        if (JsPackageManager::hasScript($scripts, 'build-only')) {
            return JsPackageManager::scriptCommand($pm, 'build-only');
        }
        if (JsPackageManager::hasScript($scripts, 'build')) {
            $raw = $scripts['build'];
            if (is_string($raw)) {
                $stripped = self::stripTypecheckPrefix($raw);
                if ($stripped !== '' && $stripped !== trim($raw)) {
                    return self::NODE_MODULES_BIN_PATH.' && '.$stripped;
                }
            }

            return JsPackageManager::scriptCommand($pm, 'build');
        }
        if (! empty($partial['default_build'])) {
            return (string) $partial['default_build'];
        }

        return '';
    }

    /**
     * Drop leading typechecker commands from a `build` script body.
     */
    public static function stripTypecheckPrefix(string $script): string
    {
        $script = trim($script);
        $pattern = '/^(?:npx\s+|pnpm\s+(?:exec|dlx)\s+|yarn\s+(?:run\s+|exec\s+)?)?'
            .'(?:vue-tsc|tsc|astro\s+check|svelte-check|ng\s+lint|eslint|'
            .'npm\s+run\s+typecheck|pnpm\s+run\s+typecheck|yarn\s+typecheck)'
            .'(?:\s+[^&|;]*)?\s*&&\s*/i';
        $guard = 0;
        while ($guard++ < 8 && preg_match($pattern, $script) === 1) {
            $script = trim((string) preg_replace($pattern, '', $script, 1));
        }

        return $script;
    }

    /**
     * A build output directory that cannot escape the project: it comes from the project and ends up in a bind
     * mount and an nginx root.
     */
    public static function safeOutputDir(mixed $dir, string $fallback = 'dist'): string
    {
        if (! is_string($dir) || $dir === '') {
            return $fallback;
        }
        $dir = trim(str_replace('\\', '/', $dir), '/');
        if ($dir === '' || str_contains($dir, '..')) {
            return $fallback;
        }

        return $dir;
    }

    public function defaultRequirement(): Requirement
    {
        return new Requirement('node', self::defaultMajor(), '', 'engine default');
    }

    /**
     * @return list<string>
     */
    public function supportedVersions(): array
    {
        return self::majors();
    }
}
