<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Node, versioned by `engines.node`, then `.nvmrc`, then `.node-version`.
 *
 * `DEFAULT_MAJOR = 20` is an older LTS on purpose: a project that says nothing
 * usually has stale dependencies (native bindings, old webpack) that newer
 * Node breaks. A project gets `bookworm` (not `-slim`) when it compiles a
 * dependency, because node-gyp needs python3, make and g++.
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

    /** The major a project that states no version gets. Older LTS on purpose. */
    public const DEFAULT_MAJOR = 20;

    /** The slim variant, which carries no C toolchain. */
    public const IMAGE_VARIANT = 'bookworm-slim';

    /**
     * What a JS recipe writes when it has no better answer for `start`.
     *
     * Matched literally so that only a recipe's own default is corrected — see
     * {@see withEntryPointDefault()}.
     */
    public const FALLBACK_START = 'node index.js';

    /**
     * The variant a project that has to compile a dependency gets: the same
     * Debian release and Node, without `-slim`. `node:*-bookworm` carries
     * python3, make and g++, all three of which `node-gyp rebuild` needs.
     *
     * Only the variant is swapped, never the major: the major is what
     * `engines.node` asked for.
     */
    public const TOOLCHAIN_VARIANT = 'bookworm';

    /**
     * Packages that run `git` during the build.
     *
     * Each stamps a commit revision into a generated artifact — a service
     * worker, a sitemap, a content index — by shelling out to `git rev-parse`
     * or `git log`, and fails the build where the binary is absent. Read by
     * {@see needsGitBinary()}.
     *
     * Named, not matched on `/git/i`: evidence that a package *invokes* git,
     * not a guess from its name.
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
     * Build-config stems whose *contents* might run git.
     *
     * Read, not matched by name: fluidd's git call is in
     * `vite.config.inject-version.ts`, a name indistinguishable from an
     * ordinary Vite config. A name-based rule would match every `vite.config.*`
     * (putting the full image on every Vite project) or none.
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
     * Packages whose install compiles a C/C++ addon with `node-gyp`.
     *
     * Not the packages that merely *support* a native build: `node-gyp`,
     * `node-gyp-build`, `node-pre-gyp`, `node-addon-api` and `nan` sit in half
     * the mainstream trees and all ship prebuilt linux-x64 binaries, so
     * reading for them would put a ~1.2GB image load on projects that install
     * on the slim image fine.
     *
     * Evidence, not authority: a name in it still has to be declared by the
     * project or recorded by npm as having an install script, and it is read
     * by {@see hasNativeDependency()}.
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
    ];

    /**
     * Lockfiles whose text is worth scanning, in the order they are read.
     *
     * The npm and pnpm lockfiles spell the tree out in JSON or YAML; the Yarn
     * file spells it in its own format. `bun.lockb` is binary and absent — a
     * bun project needing a native build is missed, not misread.
     *
     * @var list<string>
     */
    private const LOCKFILES = ['package-lock.json', 'npm-shrinkwrap.json', 'pnpm-lock.yaml', 'yarn.lock'];

    /**
     * The sections of package.json {@see mentionsNodeGyp()} reads: the four
     * dependency maps and `scripts`, which is where a project writes its own
     * `node-gyp rebuild`.
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
    public const IMAGE = 'node:' . self::DEFAULT_MAJOR . '-' . self::IMAGE_VARIANT;

    /**
     * Above this a declared major is a typo or a misread constraint:
     * `engines.node: "30"` names a tag that does not exist, so the pull 404s
     * and the deploy falls back having wasted the round trip.
     *
     * No floor: an ancient `engines.node: 4` resolves to the oldest shipped
     * major, which is closer to what it asked for than the default.
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

        return $spec?->from ?? 'node:' . $major . '-' . self::IMAGE_VARIANT;
    }

    /**
     * The one entry point for "which Node does this project get".
     *
     * A second copy of this answered differently for any major outside the
     * shipped set — a project pinning `engines.node: 10` got node:18 from the
     * recipe and node:20 from the deploy, so the account seeded one image and
     * ran another.
     *
     * @param array<string, mixed> $package an already-parsed package.json,
     *        when the caller has one
     */
    public static function imageFor(string $projectDir, array $package = []): string
    {
        $projectDir = rtrim($projectDir, '/');
        if ($projectDir === '') {
            return self::defaultImage();
        }

        $context = ProjectContext::at($projectDir);
        [$raw] = self::declaredVersion($context, $package);
        $major = self::resolveMajor($raw) ?? self::defaultMajor();

        return self::withToolchain(self::imageTag($major), $context, $package);
    }

    /**
     * $tag, or its full variant when this project has to compile something.
     *
     * Its own entry point because the tag does not always come from
     * {@see imageFor()}: a generated Dockerfile builds FROM the image the
     * detection decision froze, which resolves only the *major* and never saw
     * the project. Applying the swap there too keeps the image a Dockerfile
     * builds in and the image a host compile runs in the same tag.
     *
     * @param array<string, mixed> $package an already-parsed package.json
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
     * Does this project's *build* shell out to `git`?
     *
     * `node.stub` and `static-nginx.stub` run `RUN {{ git_install }}` for every
     * project (the slim base images ship no git), but the *host compile* path
     * runs the same slim image with no way to add a package — so a project that
     * needs git fails there and only there. `vite-plugin-pwa` in the tree is
     * enough: it stamps a revision into the service worker with `git rev-parse`
     * and the build dies on `/bin/sh: 1: git: not found` after a successful
     * `npm install`.
     *
     * The signal is a *direct* declaration in the manifest, the standard
     * `hasNativeDependency()` settled on: a transitive dependency puts the name
     * in many trees that never invoke it.
     *
     * Being wrong costs less here than for the native list — the full variant
     * is already pulled for this major by every native build, and the swap only
     * names it, so nothing extra is fetched.
     *
     * @param array<string, mixed> $package an already-parsed package.json, if
     *        the caller has one
     */
    public static function needsGitBinary(ProjectContext $context, array $package = []): bool
    {
        $dependencies = $package === [] ? ($context->package() ?? []) : $package;

        // 1. The project drives git itself, in a script.
        foreach ($context->scripts() as $script) {
            if (self::invokesGit((string) $script)) {
                return true;
            }
        }

        // 2. A build config that does -- where it usually lives; the file name
        //    says nothing.
        //
        //    A context with no directory is legitimate: `ProjectContext::at('')`
        //    is how callers with only a package.json build one, and
        //    `firstConfigContents()` calls `scandir('')`, which throws.
        if ($context->projectDir !== '') {
            foreach (self::GIT_AT_BUILD_CONFIGS as $stem) {
                $contents = $context->configContents($stem);
                if ($contents !== null && self::invokesGit($contents)) {
                    return true;
                }
            }
        }

        // 3. A package that does, declared by the project.
        foreach (self::GIT_AT_BUILD_DEPENDENCIES as $name) {
            foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'] as $section) {
                if (isset(($dependencies[$section] ?? [])[$name])) {
                    return true;
                }
            }
        }

        // 4. A dependency npm has to clone before anything of the project's
        //    own runs.
        return self::installsFromGit($context, $dependencies);
    }

    /**
     * Does this text run git as a command?
     *
     * Matched on an invocation, not the bare word, so `husky install` and
     * `digit-check.js` do not count while `git rev-parse --short HEAD` and
     * `execSync('git log -1')` do.
     *
     * Only *literal* invocations: a path built at runtime is not visible here,
     * and guessing would put the full image on projects that never call git.
     */
    private static function invokesGit(string $text): bool
    {
        // The word git, then any global options, then a subcommand: the
        // subcommand anchor keeps `digit-check` and `.gitignore` out.
        //
        // The options are not optional detail. `git -C /app submodule update`
        // and `git -c safe.directory=/app submodule update` are the engine's
        // own idiom -- System/Project/Git.php spells both -- so a recipe
        // author who copies how the engine invokes git wrote exactly the form
        // this could not see. The build then ran on node:*-slim, which has no
        // git, and died as `npm error syscall spawn git`: a message naming
        // neither the missing binary nor the image choice.
        return preg_match(
            '/(?:^|[\s\'"&|;(=\[`])git(?:\s+' . self::GIT_GLOBAL_OPTION . ')*'
            . '\s+(?:rev-parse|log|describe|show|status|diff|branch|tag|config|ls-files|archive|submodule)\b/',
            $text
        ) === 1;
    }

    /**
     * A git option that may sit between `git` and its subcommand.
     *
     * `-c` takes `key=value` and `-C` a path, with or without a space between
     * flag and value, so `\s*\S+` covers both spellings of each.
     */
    private const GIT_GLOBAL_OPTION =
        '(?:-[cC]\s*\S+|--(?:git-dir|work-tree|exec-path|namespace)=\S+|--no-pager|--bare|-[pP])';

    /**
     * Does any declared dependency resolve over git?
     *
     * A different route to the same failure, and the one tine arrived by: ten
     * of its dependencies are `git+ssh://git@github.com/...` in
     * npm-shrinkwrap.json, so `npm install` itself shells out to git before a
     * single script runs. No script mentions git, so the checks above see
     * nothing, and the install fails with `git dep preparation failed`.
     */
    private static function installsFromGit(ProjectContext $context, array $dependencies): bool
    {
        foreach (['dependencies', 'devDependencies', 'optionalDependencies'] as $section) {
            foreach ((array) ($dependencies[$section] ?? []) as $spec) {
                if (is_string($spec) && preg_match('#^(?:git\+|git://|github:|bitbucket:|gitlab:)#', $spec) === 1) {
                    return true;
                }
            }
        }

        // The specs above are what the manifest says; a lockfile is what the
        // install will actually fetch, and a transitive git dependency only
        // appears there. Read whole -- they are large, and this runs once per
        // build decision.
        foreach (self::LOCKFILES as $lockfile) {
            $contents = $context->contents($lockfile);
            if ($contents !== null && str_contains($contents, 'git+')) {
                return true;
            }
        }

        return false;
    }


    /**
     * $tag without `-slim`: the full Debian image, the one that can run
     * `node-gyp`.
     *
     * Takes the resolved tag, not a major, so a host that re-pointed
     * `node` at a custom registry keeps it. A tag not ending in the slim
     * variant — a `panelalpha/node` derivative, a project pinning its own
     * image — is returned untouched.
     */
    public static function toolchainImage(string $tag): string
    {
        $suffix = '-' . self::IMAGE_VARIANT;

        return str_ends_with($tag, $suffix)
            ? substr($tag, 0, -strlen($suffix)) . '-' . self::TOOLCHAIN_VARIANT
            : $tag;
    }

    /**
     * Does installing this project have to compile something?
     *
     * The slim Node images have no Python, no make and no C++ compiler, so
     * `node-gyp rebuild` fails outright.
     *
     * Three signals: `binding.gyp` in the project root (this *is* a native
     * addon); `node-gyp` or a name from {@see NATIVE_DEPENDENCIES} declared in
     * the manifest itself; or a name from that list the lockfile marks
     * `hasInstallScript: true`, which is npm's own record that the install runs
     * something.
     *
     * Not checked: whether the lockfile merely *resolves* a name from the list.
     * On eleven real lockfiles — the six this was written for and five
     * mainstream ones — that test fired on every one, because a transitive
     * dependency is enough to put the name in the file, and it would have put a
     * ~1.2GB image load on the five that were fine. The cost of the narrower
     * answer is a rare false negative.
     *
     * @param array<string, mixed> $package an already-parsed package.json, if
     *        the caller has one
     */
    public static function hasNativeDependency(ProjectContext $context, array $package = []): bool
    {
        if ($context->hasFile('binding.gyp')) {
            return true;
        }

        $dependencies = $package === [] ? ($context->package() ?? []) : $package;

        // The project says it compiles with gyp, or names one of the native
        // packages itself: the application's own decision, not an inherited one.
        if (self::mentionsNodeGyp($dependencies)) {
            return true;
        }
        if (self::declaresNativeDependency($dependencies)) {
            return true;
        }

        $lock = self::lockfileText($context);

        return $lock !== '' && self::lockMarksNative($lock);
    }

    /**
     * Does the parsed package.json name node-gyp anywhere — as a dependency,
     * or in a script that runs it?
     *
     * @param array<string, mixed> $package
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
     * @param array<string, mixed> $package
     */
    private static function declaresNativeDependency(array $package): bool
    {
        $mapSections = ['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'];
        foreach ($mapSections as $section) {
            $declared = $package[$section] ?? null;
            if (!is_array($declared)) {
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
     * Does the lockfile have what it takes to say "this will compile"?
     *
     * One thing: npm's own `hasInstallScript` flag on a package this list
     * recognises. See {@see hasNativeDependency()}.
     */
    private static function lockMarksNative(string $lock): bool
    {
        foreach (self::NATIVE_DEPENDENCIES as $name) {
            $path = 'node_modules/' . preg_quote($name, '/');
            if (preg_match('~"?[^"\s]*' . $path . '"?[^}]*hasinstallscript~', $lock) === 1) {
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
     * Does the map — a dependency map, or `scripts` — mention $needle in one
     * of its keys or values?
     *
     * Both halves: in a dependency map the package is the key and the value is
     * a version range, while in `scripts` the body is the value and carries a
     * project's own `node-gyp rebuild`.
     */
    private static function mentions(mixed $map, string $needle): bool
    {
        if (!is_array($map)) {
            return false;
        }
        $pattern = '/(?<![a-z0-9_.-])' . preg_quote($needle, '/') . '(?![a-z0-9_.-])/';
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
     * The first lockfile under the project root, lowercased.
     *
     * Lowercased because {@see ProjectContext::listRootFiles()} lowercases
     * every name it lists, while `contents()` is case-exact.
     *
     * `\/` is unescaped first: JSON escapes the forward slash, so npm's package
     * paths arrive as `node_modules\/argon2` and every path searched for here
     * would miss. A package name cannot contain a backslash.
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
     * The major a raw constraint resolves to: the first shipped major at or
     * above it, or the constraint itself when it is plausible but newer than
     * anything shipped.
     *
     * An open lower bound (`>=14`) is a floor, not a pin: it gets the default
     * major when the floor allows it, and `>=16 <21` the newest shipped major
     * in range.
     *
     * Null means the constraint could not be believed — unparseable, `lts/*`,
     * or implausibly high. Null, not the default, so a project that pinned
     * exactly the default is still credited with having said so.
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

    public function resolve(ProjectContext $context): ?Requirement
    {
        if (!$context->hasFile('package.json')) {
            return null;
        }

        [$raw, $source] = self::declaredVersion($context);
        if ($raw === '') {
            return new Requirement('node', self::defaultMajor(), '', 'engine default');
        }

        // Reported as the engine's when unresolved: crediting package.json for
        // a version it did not produce makes the deploy log a liar.
        $resolved = self::resolveMajor($raw);

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
     * @param array<string, mixed> $package an already-parsed package.json
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
     * `[floor, ceiling]` majors of a `>=N` / `>N` constraint, with an optional
     * `<M` / `<=M` ceiling; null for anything else (`^18`, `18.x`, `a || b`).
     *
     * @return array{0: int, 1: ?int}|null
     */
    private static function openRange(string $constraint): ?array
    {
        $pattern = '/^>(=)?\s*v?(\d+)(?:\.(\d+|x|\*))?(?:\.(\d+|x|\*))?'
            . '(?:\s*,?\s*<(=)?\s*v?(\d+)(?:\.(\d+))?(?:\.(\d+))?)?$/i';
        if (preg_match($pattern, trim($constraint), $m) !== 1) {
            return null;
        }

        // `>14` is `>=15`; `>14.2` still allows 14.
        $floor = (int) $m[2];
        if (($m[1] ?? '') === '' && ($m[3] ?? '') === '') {
            $floor++;
        }

        $ceiling = null;
        if (($m[6] ?? '') !== '') {
            $ceiling = (int) $m[6];
            // `<21` and `<21.0.0` exclude 21; `<21.5` does not.
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
     * Adapt a manifest's declared defaults to what this particular project
     * uses.
     *
     * A manifest cannot know that this repo has a pnpm lockfile, a `build-only`
     * script that must be preferred over `build`, or a turbo filter naming the
     * workspace — which is why a manifest writes `{{js.build:npx next build}}`
     * instead of a literal.
     *
     * @param array<string, mixed> $partial the manifest's defaults
     * @param array<string, true> $files
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
     * Point a recipe's `node index.js` default at the entry point the project
     * actually declares.
     *
     * For a project with no `start` script and no `index.js` it is wrong:
     * Node's own convention is `package.json`'s `main`, and a runner that
     * ignores it restarts for ever on `Cannot find module '/app/index.js'`.
     * CNCjs (`main: ./dist/cncjs/server-cli.js`) is that shape.
     *
     * Only a default the recipe supplied is replaced; a project's own `start`
     * script is left alone.
     *
     * @param array<string, mixed> $resolved the decision `finalizeWith` produced
     * @param array<string, mixed> $package parsed package.json
     */
    private static function withEntryPointDefault(
        array $resolved,
        array $package,
        ProjectContext $context
    ): array {
        if (($resolved['start_command'] ?? '') !== self::FALLBACK_START) {
            return $resolved;
        }

        // `node index.js` is only wrong when there is no index.js to run: an
        // express or fastify app that has one and also carries a stale
        // library-shaped `main` ("main": "lib/index.js") booted before this and
        // got MODULE_NOT_FOUND after. A file that is really there beats a field
        // that merely says something.
        if ($context->isFile('index.js')) {
            return $resolved;
        }

        $main = self::entryPoint($package, $context);
        if ($main !== null) {
            $resolved['start_command'] = 'node ' . $main;
        }

        return $resolved;
    }

    /**
     * The file Node itself would run for this package: `bin`, else `main`,
     * else a root `server.js`, else nothing.
     *
     * `bin` first because it means "this package is a program you run", which
     * is what a service recipe is doing: CNCjs's `main` exports `launchServer`
     * and does nothing when executed, while `bin/cncjs` starts the server.
     * Either field may name a file the *build* produces, so both are taken as
     * declared.
     *
     * Only the shape is checked — empty, absolute or escaping the application
     * is not a path `node` should be handed. `server.js` is a fallback, so that
     * one has to be there.
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
     * The path a package's `bin` declares for the package itself, in either
     * spelling: a map of name to path, or the single-string form.
     *
     * The entry named after the package comes first: `{"foo-cli": "./cli.js",
     * "foo": "./server.js"}` would otherwise start the CLI and never the
     * server, purely because of JSON key order. Falls back to the first entry
     * for the single-bin case (CNCjs declares only `cnc`).
     *
     * @param array<string, mixed> $package
     */
    private static function firstBin(array $package): ?string
    {
        $bin = $package['bin'] ?? null;
        if (is_string($bin)) {
            return $bin;
        }
        if (!is_array($bin)) {
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
            && !str_starts_with($candidate, '/')
            && !str_contains($candidate, '..');
    }

    /**
     * Fill a recipe's partial in from the project's package manager and
     * scripts: an author's own `build`/`start` script beats the recipe's
     * default, which is only there for projects that ship neither.
     *
     * @param array<string, mixed> $partial
     * @param array<string, mixed> $scripts
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
        $build = '';
        if ($runtime === 'nginx') {
            $build = self::nginxAssetBuildCommand($pm, $scripts, $partial);
        } else {
            $build = JsPackageManager::resolveLifecycleCommand($pm, $scripts, 'build', $partial);
        }

        $start = '';
        if ($runtime === 'node') {
            $start = JsPackageManager::resolveLifecycleCommand($pm, $scripts, 'start', $partial);
        }

        $port = (int) $partial['port_hint'];
        $env = [
            'HOST' => '0.0.0.0',
            'HOSTNAME' => '0.0.0.0',
            'PORT' => (string) $port,
            // keep husky quiet at runtime too
            'HUSKY' => '0',
            'CI' => '1',
        ];
        if (!empty($partial['env']) && is_array($partial['env'])) {
            $env = array_merge($env, $partial['env']);
        }

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
     * Static HTML for nginx: skip typecheck-only prefixes (`astro check &&`,
     * `vue-tsc &&`, `tsc &&`) and prefer `build-only` when the project has it.
     * Later steps (`astro build && node process-html.mjs`) are kept.
     *
     * @param array<string, mixed> $scripts
     * @param array<string, mixed> $partial
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
                    return 'PATH=/app/node_modules/.bin:$PATH ' . $stripped;
                }
            }

            return JsPackageManager::scriptCommand($pm, 'build');
        }
        if (!empty($partial['default_build'])) {
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
            . '(?:vue-tsc|tsc|astro\s+check|svelte-check|ng\s+lint|eslint|'
            . 'npm\s+run\s+typecheck|pnpm\s+run\s+typecheck|yarn\s+typecheck)'
            . '(?:\s+[^&|;]*)?\s*&&\s*/i';
        $guard = 0;
        while ($guard++ < 8 && preg_match($pattern, $script) === 1) {
            $script = trim((string) preg_replace($pattern, '', $script, 1));
        }

        return $script;
    }

    /**
     * A build output directory that cannot escape the project.
     *
     * The value comes from the project (angular.json, a recipe default) and
     * ends up in a bind mount and an nginx root, where `../../etc` is a path
     * traversal out of the account.
     */
    public static function safeOutputDir(mixed $dir, string $fallback = 'dist'): string
    {
        if (!is_string($dir) || $dir === '') {
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
