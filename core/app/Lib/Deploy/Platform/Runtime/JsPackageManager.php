<?php

namespace App\Lib\Deploy\Platform\Runtime;

/**
 * npm/yarn/pnpm/bun mechanics shared by every JS recipe: which manager a repo
 * uses, the install/build/start command for each, and the install-time quirks
 * (frozen lockfiles, corepack pinning, git-hook scripts unrunnable in a build).
 */
class JsPackageManager
{
    /**
     * Lockfile first, in this order: `bun.lock`/`bun.lockb`, `pnpm-lock.yaml`,
     * `yarn.lock`, `package-lock.json`/`npm-shrinkwrap.json`. Then the
     * `packageManager` field of package.json; then npm.
     *
     * @param array<string, true> $files
     * @param array<string, mixed> $package
     */
    public static function detectPackageManager(array $files, array $package = []): string
    {
        if (isset($files['bun.lock']) || isset($files['bun.lockb'])) {
            return 'bun';
        }
        if (isset($files['pnpm-lock.yaml'])) {
            return 'pnpm';
        }
        if (isset($files['yarn.lock'])) {
            return 'yarn';
        }
        if (isset($files['package-lock.json']) || isset($files['npm-shrinkwrap.json'])) {
            return 'npm';
        }

        $field = $package['packageManager'] ?? '';
        if (is_string($field) && $field !== '') {
            if (str_starts_with($field, 'pnpm')) {
                return 'pnpm';
            }
            if (str_starts_with($field, 'yarn')) {
                return 'yarn';
            }
            if (str_starts_with($field, 'bun')) {
                return 'bun';
            }
        }

        return 'npm';
    }

    /** Each manager's spelling of a script: `yarn <script>`, `pnpm run <script>`, `bun run <script>`. */
    public static function scriptCommand(string $packageManager, string $script): string
    {
        if ($packageManager === 'yarn') {
            return 'yarn ' . $script;
        }
        if ($packageManager === 'pnpm') {
            return $script === 'start' ? 'pnpm start' : 'pnpm run ' . $script;
        }
        if ($packageManager === 'bun') {
            return 'bun run ' . $script;
        }

        return $script === 'start' ? 'npm start' : 'npm run ' . $script;
    }

    /**
     * The lockfile this manager reads, or null when the project ships none.
     * `bun.lock` outranks `bun.lockb`; `package-lock.json` outranks
     * `npm-shrinkwrap.json`.
     *
     * @param array<string, true> $files
     */
    public static function lockfileName(string $packageManager, array $files): ?string
    {
        if ($packageManager === 'bun') {
            if (isset($files['bun.lock'])) {
                return 'bun.lock';
            }
            if (isset($files['bun.lockb'])) {
                return 'bun.lockb';
            }

            return null;
        }
        if ($packageManager === 'pnpm') {
            return isset($files['pnpm-lock.yaml']) ? 'pnpm-lock.yaml' : null;
        }
        if ($packageManager === 'yarn') {
            return isset($files['yarn.lock']) ? 'yarn.lock' : null;
        }
        if (isset($files['package-lock.json'])) {
            return 'package-lock.json';
        }
        if (isset($files['npm-shrinkwrap.json'])) {
            return 'npm-shrinkwrap.json';
        }

        return null;
    }

    /**
     * Install corepack where the image lacks it: Node 25+ no longer bundles
     * it (Misskey pins `.node-version` 26.4.0 and died on `corepack: not
     * found`), while npm ships in every Node image. Its own `sh -c`, so the
     * `||` cannot swallow a failure earlier in an `&&` chain.
     */
    public const ENSURE_COREPACK = "sh -c 'command -v corepack >/dev/null 2>&1 || npm install -g corepack'";

    /**
     * The install line per manager: `bun install`; pnpm via corepack with
     * `--frozen-lockfile` when a lockfile exists; `corepack enable && yarn
     * install`; `npm ci` with a lockfile, `npm install` without.
     *
     * @param array<string, true> $files
     * @param array<string, mixed> $package
     */
    public static function installCommand(
        string $pm,
        array $files,
        array $package = [],
        ?string $projectDir = null
    ): string {
        $lock = self::lockfileName($pm, $files);
        if ($pm === 'bun') {
            // Never --frozen-lockfile: lockfiles committed under an older Bun
            // disagree with the oven/bun:1 image.
            return self::withCiInstallEnv('bun install');
        }
        if ($pm === 'pnpm') {
            return self::withCiInstallEnv(self::pnpmInstallCommand($lock !== null, $package, $projectDir));
        }
        if ($pm === 'yarn') {
            $flags = $lock !== null
                ? self::yarnLockfileFlag($files, $package, $projectDir)
                : '';
            $flags = trim($flags . ' ' . self::yarnEnginesFlag($files, $package, $projectDir));

            return self::withCiInstallEnv(
                self::ENSURE_COREPACK . ' && corepack enable && yarn install' . ($flags === '' ? '' : ' ' . $flags)
            );
        }

        return self::withCiInstallEnv(
            $lock !== null
                ? 'npm ci --no-audit --no-fund'
                : 'npm install --no-audit --no-fund'
        );
    }

    /**
     * husky / lefthook `prepare` hooks fail in Docker (no .git, no git binary).
     * CI=1 also skips some interactive postinstall prompts.
     *
     * Idempotent: a command already carrying HUSKY=0 is returned unchanged.
     */
    public static function withCiInstallEnv(string $command): string
    {
        $command = trim($command);
        if ($command === '' || preg_match('/\bHUSKY=0\b/', $command) === 1) {
            return $command;
        }

        return 'HUSKY=0 LEFTHOOK=0 CI=1 ' . $command;
    }

    /**
     * Root `prepare`/`postinstall` that cannot run before install here: a
     * git-hook tool (lefthook, husky, …) never can, since an image build has no
     * .git and no git binary.
     *
     * A script running a file *from the repository* is only unrunnable when the
     * source is not there yet, which `$sourcePresent` says.
     */
    public static function isGitHookInstallerScript(string $script, bool $sourcePresent = false): bool
    {
        return self::referencesGitHookTool($script)
            || (!$sourcePresent && self::referencesLocalScript($script));
    }

    private static function referencesGitHookTool(string $script): bool
    {
        $script = strtolower(trim($script));
        if ($script === '') {
            return false;
        }

        return preg_match('/\b(husky|lefthook|simple-git-hooks|yorkie|pre-commit)\b/', $script) === 1;
    }

    /**
     * A lifecycle script that runs a file from the repository, e.g.
     * `./backend/scripts/check-supported-node.cjs`. The dependencies layer
     * copies only package.json and the lockfile, so the file arrives one layer
     * later, on `COPY . .`, and the install fails.
     *
     * Paths under `node_modules/` are excluded — they exist by then, and
     * stripping them would break a package that builds itself on install. So is
     * an inline `node -e '…'`: the token after the runner must look like a
     * script file.
     *
     * Only true where the source is genuinely absent, which is the manifests-first
     * layer alone; `$sourcePresent` says so.
     */
    public static function referencesLocalScript(string $script): bool
    {
        $script = trim($script);
        if ($script === '') {
            return false;
        }

        $pattern = '#(?:^|[\s;&|(])(?:node|bash|sh|zsh|dash|bun|deno|ts-node|tsx)\s+'
            . '(?:\./)?((?:[\w.-]+/)*[\w.-]+\.(?:cjs|mjs|js|ts|sh))\b#i';

        if (preg_match_all($pattern, $script, $matches) === 0) {
            return false;
        }

        foreach ($matches[1] as $path) {
            if (!str_starts_with(strtolower($path), 'node_modules/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $package
     * @return array<string, mixed>
     */
    public static function stripGitHookInstallerScripts(array $package, bool $sourcePresent = false): array
    {
        if (!isset($package['scripts']) || !is_array($package['scripts'])) {
            return $package;
        }
        foreach (['prepare', 'postinstall', 'preinstall'] as $name) {
            $script = $package['scripts'][$name] ?? null;
            if (is_string($script) && self::isGitHookInstallerScript($script, $sourcePresent)) {
                unset($package['scripts'][$name]);
            }
        }

        return $package;
    }

    /** Seconds before the strip script is killed; `bun -e` can hang on it. */
    private const STRIP_TIMEOUT_SECONDS = 30;

    public static function dockerfileStripGitHookScriptsCommand(string $pm, bool $sourcePresent = false): string
    {
        // The repository-file rule applies only where the file is absent — the
        // manifests-first layer. The matcher loops every match, unlike a single
        // `match()`: `node node_modules/foo/build.js && node scripts/y.js` has
        // one path under node_modules and one in the repository, and inspecting
        // only the first said "keep".
        $localRule = $sourcePresent
            ? ''
            : 'const local=/(?:^|[\\s;&|(])(?:node|bash|sh|zsh|dash|bun|deno|ts-node|tsx)\\s+(?:\\.\\/)?((?:[\\w.-]+\\/)*[\\w.-]+\\.(?:cjs|mjs|js|ts|sh))\\b/gi;'
                . 'const localHit=s=>{local.lastIndex=0;let m;'
                . 'while((m=local.exec(s))!==null){'
                . 'if(m[1].toLowerCase().indexOf("node_modules/")!==0)return true;}'
                . 'return false;};';
        $localTest = $sourcePresent ? '' : '||localHit(s)';

        $js = 'const fs=require("fs");'
            . 'const p=JSON.parse(fs.readFileSync("package.json","utf8"));'
            . 'if(!p.scripts)process.exit(0);'
            . 'const re=/\\b(husky|lefthook|simple-git-hooks|yorkie|pre-commit)\\b/i;'
            . $localRule
            . 'for(const k of["prepare","postinstall","preinstall"]){'
            . 'const s=p.scripts[k];'
            . 'if(typeof s!=="string")continue;'
            . 'if(re.test(s)' . $localTest . ')delete p.scripts[k];'
            . '}'
            . 'fs.writeFileSync("package.json",JSON.stringify(p));';

        $runner = $pm === 'bun' ? 'bun' : 'node';

        // Bounded and allowed to fail: without the strip `npm install` runs
        // `husky install` in a tree with no .git and fails, which is visible,
        // while an unbounded RUN that hangs pins a core.
        return 'timeout ' . self::STRIP_TIMEOUT_SECONDS . ' '
            . $runner . ' -e ' . "'" . $js . "'"
            . ' || true';
    }

    /**
     * npm/bun/pnpm workspace root: install needs every package.json, not only
     * the root manifest.
     *
     * @param array<string, mixed> $package
     * @param array<string, true> $files
     */
    public static function isJsWorkspace(array $package, array $files = []): bool
    {
        $workspaces = $package['workspaces'] ?? null;
        if (is_array($workspaces) && $workspaces !== []) {
            return true;
        }
        if (is_string($workspaces) && trim($workspaces) !== '') {
            return true;
        }

        return isset($files['pnpm-workspace.yaml']);
    }

    /**
     * Pin pnpm via corepack. Bare `corepack enable && pnpm` downloads latest,
     * and pnpm 11+ needs Node 22.13 (`node:sqlite`), so it crashes on the
     * Node 18/20 images `engines.node` often selects.
     *
     * Never create pnpm-workspace.yaml: a settings-only file turns a
     * single-package repo into a broken workspace. `--dangerously-allow-all-builds`
     * (10.9+) is added only for a project declaring no build policy.
     *
     * @param array<string, mixed> $package
     */
    private static function pnpmInstallCommand(
        bool $frozenLockfile,
        array $package,
        ?string $projectDir = null
    ): string {
        $spec = self::corepackPnpmSpec($package);
        $install = $frozenLockfile ? 'pnpm install --frozen-lockfile' : 'pnpm install';
        if (
            self::pnpmSupportsDangerouslyAllowAllBuilds($spec)
            && !self::pnpmDeclaresBuildPolicy($package, $projectDir)
        ) {
            $install .= ' --dangerously-allow-all-builds';
        }

        return self::ENSURE_COREPACK . ' && corepack enable && corepack prepare ' . $spec . ' --activate && ' . $install;
    }

    /**
     * Every spelling of "this project has already decided which dependencies
     * may run build scripts": pnpm 10's `onlyBuiltDependencies` /
     * `neverBuiltDependencies`, pnpm 11's `allowBuilds` /
     * `ignoredBuiltDependencies`.
     *
     * @var list<string>
     */
    private const PNPM_BUILD_POLICY_KEYS = [
        'onlyBuiltDependencies',
        'neverBuiltDependencies',
        'allowBuilds',
        'ignoredBuiltDependencies',
    ];

    /**
     * Has the project already decided which dependencies may run build scripts?
     *
     * The repo's own policy wins: `--dangerously-allow-all-builds` sets
     * neverBuiltDependencies, and pnpm 10 aborts with
     * ERR_PNPM_CONFIG_CONFLICT_BUILT_DEPENDENCIES when that meets the repo's
     * own onlyBuiltDependencies. HedgeDoc declares `allowBuilds: {
     * better-sqlite3: false }` — the flag overrode it, node-gyp ran, and the
     * build died on `Could not find any Python installation to use`.
     *
     * Read from `package.json` `pnpm` and from pnpm-workspace.yaml.
     *
     * @param array<string, mixed> $package
     */
    private static function pnpmDeclaresBuildPolicy(array $package, ?string $projectDir): bool
    {
        $pnpm = $package['pnpm'] ?? null;
        if (is_array($pnpm)) {
            foreach (self::PNPM_BUILD_POLICY_KEYS as $key) {
                if (isset($pnpm[$key])) {
                    return true;
                }
            }
        }
        if ($projectDir === null) {
            return false;
        }
        $path = rtrim($projectDir, '/') . '/pnpm-workspace.yaml';
        if (!is_file($path)) {
            return false;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return false;
        }

        $keys = implode('|', self::PNPM_BUILD_POLICY_KEYS);

        return preg_match('/^[ \t]*(' . $keys . ')[ \t]*:/mi', $raw) === 1;
    }

    /**
     * `--frozen-lockfile` for Yarn 1, `--immutable` for Yarn 2+. Each rejects
     * the other's spelling as a hard error (`YN0050`), so the major must be
     * known.
     *
     * `yarnMajor()` decides from three signals, in order of authority:
     * `packageManager` in package.json (corepack refuses to run any other
     * version), then `.yarnrc.yml`, which only Yarn 2+ reads, then the
     * lockfile's header — `__metadata:` is Berry, `# yarn lockfile v1` is
     * Classic. With no Berry signal at all it answers Classic.
     *
     * @param array<string, true> $files
     * @param array<string, mixed> $package
     */
    private static function yarnLockfileFlag(array $files, array $package, ?string $projectDir): string
    {
        $major = self::yarnMajor($files, $package, $projectDir);

        return $major !== null && $major >= 2 ? '--immutable' : '--frozen-lockfile';
    }

    /**
     * `--ignore-engines` for a Classic Yarn install, nothing for Berry.
     *
     * Yarn 1 refuses to install when *any* package in the resolved tree
     * declares an `engines.node` the running Node does not satisfy, which npm
     * ignores by default. A transitive `@mybucks.online/core` wanting `>=24`
     * failed a static build at Node 20 with `Found incompatible module`.
     * Yarn 2+ does not enforce `engines`, and the flag is unknown to it.
     *
     * @param array<string, true> $files
     * @param array<string, mixed> $package
     */
    private static function yarnEnginesFlag(array $files, array $package, ?string $projectDir): string
    {
        $major = self::yarnMajor($files, $package, $projectDir);

        // null means no Berry signal, which yarnLockfileFlag() treats as Classic.
        return $major === null || $major < 2 ? '--ignore-engines' : '';
    }

    /**
     * The Yarn major, or null when nothing in the project says which it is.
     *
     * @param array<string, true> $files
     * @param array<string, mixed> $package
     */
    private static function yarnMajor(array $files, array $package, ?string $projectDir): ?int
    {
        $field = $package['packageManager'] ?? '';
        if (is_string($field) && preg_match('/^yarn@(\d+)/', $field, $matches) === 1) {
            return (int) $matches[1];
        }

        if (isset($files['.yarnrc.yml'])) {
            return 2;
        }

        if ($projectDir === null) {
            return null;
        }

        $path = rtrim($projectDir, '/') . '/yarn.lock';
        if (!is_file($path)) {
            return null;
        }
        $raw = (string) @file_get_contents($path);
        if (preg_match('/^__metadata:/m', $raw) === 1) {
            return 2;
        }
        if (stripos($raw, '# yarn lockfile v1') !== false) {
            return 1;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $package
     */
    private static function corepackPnpmSpec(array $package): string
    {
        $field = $package['packageManager'] ?? '';
        if (is_string($field) && preg_match('/^pnpm@(\d+(?:\.\d+){0,2})/', $field, $matches) === 1) {
            return 'pnpm@' . $matches[1];
        }

        return 'pnpm@10';
    }

    private static function pnpmSupportsDangerouslyAllowAllBuilds(string $spec): bool
    {
        if (preg_match('/^pnpm@(\d+)(?:\.(\d+))?/', $spec, $matches) !== 1) {
            return false;
        }
        $major = (int) $matches[1];
        if ($major > 10) {
            return true;
        }
        if ($major < 10) {
            return false;
        }
        // pnpm@10 (no minor) resolves to latest 10.x, which includes 10.9+.
        if (!isset($matches[2]) || $matches[2] === '') {
            return true;
        }

        return (int) $matches[2] >= 9;
    }

    /**
     * Pick build/start for a workspace: `<kind>:<slug>` if it exists, then the
     * root script (injecting `--filter` into a bare `turbo build`), else the
     * framework default from `$partial['default_<kind>']`.
     *
     * @param array<string, mixed> $scripts
     * @param array<string, mixed> $partial
     */
    public static function resolveLifecycleCommand(
        string $pm,
        array $scripts,
        string $kind,
        array $partial
    ): string {
        $slug = trim((string) ($partial['workspace_slug'] ?? ''));
        $pkg = trim((string) ($partial['workspace_package'] ?? ''));
        $defaultKey = 'default_' . $kind;

        if ($slug !== '' && self::hasScript($scripts, $kind . ':' . $slug)) {
            return self::scriptCommand($pm, $kind . ':' . $slug);
        }

        if (self::hasScript($scripts, $kind)) {
            $body = trim((string) $scripts[$kind]);
            if (self::turboNeedsPackageFilter($body) && $pkg !== '') {
                return self::turboFilteredCommand($pm, $kind, $pkg, $body);
            }

            return self::scriptCommand($pm, $kind);
        }

        return !empty($partial[$defaultKey]) ? (string) $partial[$defaultKey] : '';
    }

    /** True for `turbo` with no `--filter`/`--scope` of its own. */
    public static function turboNeedsPackageFilter(string $scriptBody): bool
    {
        if (preg_match('/\bturbo\b/', $scriptBody) !== 1) {
            return false;
        }
        if (preg_match('/--filter(=|\s)/', $scriptBody) === 1) {
            return false;
        }
        if (preg_match('/--scope(=|\s)/', $scriptBody) === 1) {
            return false;
        }

        return true;
    }

    /**
     * Run turbo for one workspace package, keeping a leading `dotenv`/`dotenv -c`
     * wrapper when the original script had one.
     */
    public static function turboFilteredCommand(
        string $pm,
        string $kind,
        string $packageName,
        string $originalBody = ''
    ): string {
        $filter = $packageName;
        $turbo = preg_match('/\bturbo\s+run\b/', $originalBody) === 1
            || $kind !== 'build'
            ? 'turbo run ' . $kind . ' --filter=' . $filter . '...'
            : 'turbo build --filter=' . $filter . '...';

        $prefix = '';
        if (preg_match('/^(dotenv(?:\s+-c)?(?:\s+--)?)\s+/', trim($originalBody), $m) === 1) {
            $prefix = $m[1] . ' ';
        }

        $runner = match ($pm) {
            'bun' => 'bunx ',
            'pnpm' => 'pnpm exec ',
            'yarn' => 'yarn exec ',
            default => 'npx ',
        };

        return $prefix . $runner . $turbo;
    }

    /**
     * A script is present only when it exists and is a non-empty string.
     *
     * @param array<string, mixed> $scripts
     */
    public static function hasScript(array $scripts, string $name): bool
    {
        return isset($scripts[$name]) && is_string($scripts[$name]) && trim($scripts[$name]) !== '';
    }

    /**
     * The workspace paths a root package.json declares, in both shapes npm
     * accepts: a list, or an object with a `packages` key.
     *
     * A path is kept only when it stays inside the repository — these become
     * the directory a shell command runs in, and a customer's package.json is
     * not the place to take `../../..` from. A glob is returned as written.
     * A string in this field names no directory.
     *
     * @param array<string, mixed> $package
     * @return list<string>
     */
    public static function workspaces(array $package): array
    {
        $workspaces = $package['workspaces'] ?? null;
        if (is_array($workspaces) && array_key_exists('packages', $workspaces)) {
            $workspaces = $workspaces['packages'];
        }
        if (!is_array($workspaces)) {
            return [];
        }

        $paths = [];
        foreach ($workspaces as $workspace) {
            if (!is_string($workspace)) {
                continue;
            }
            // Absolute first, on the untrimmed string: trimming the leading
            // slash off `/etc` would make it look like a relative path.
            if (str_starts_with(trim($workspace), '/')) {
                continue;
            }
            $path = trim($workspace, " 	

\0\x0B/");
            if ($path === '' || $path === '.' || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
                continue;
            }
            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    /**
     * Where a project's frontend build actually runs. The root `build` script
     * wins; failing that, the first declared workspace with one, in declaration
     * order.
     *
     * Firefly III's root declares `resources/assets/v1` and
     * `resources/assets/v2` and only v2 carries a `build`, so `npm run build`
     * there stops on `Missing script: "build"` and every Blade `@vite` answers
     * 500 with ViteManifestNotFoundException.
     *
     * @param array<string, mixed> $package the root package.json, decoded
     * @param callable(string): ?array<string, mixed> $packageAt a workspace
     *        path => the package.json under it, decoded, or null when there is
     *        none to read
     * @return array{workspace: string, script: string}|null null when neither
     *         the root nor a declared workspace builds anything; `workspace`
     *         is '' for the root
     */
    public static function buildTarget(array $package, callable $packageAt): ?array
    {
        $scripts = $package['scripts'] ?? null;
        if (is_array($scripts) && self::hasScript($scripts, 'build')) {
            return ['workspace' => '', 'script' => 'build'];
        }

        foreach (self::workspaces($package) as $workspace) {
            $workspacePackage = $packageAt($workspace);
            $workspaceScripts = is_array($workspacePackage) ? ($workspacePackage['scripts'] ?? null) : null;
            if (!is_array($workspaceScripts) || !self::hasScript($workspaceScripts, 'build')) {
                continue;
            }

            return ['workspace' => $workspace, 'script' => 'build'];
        }

        return null;
    }

    /**
     * Run a command in a workspace directory.
     *
     * `cd`, not a `--workspace`/`--filter` flag — every manager spells the
     * filter differently, and the build tools read the directory they start in.
     * Escaped, because the path comes out of a customer repository.
     */
    public static function inWorkspace(string $command, string $workspace): string
    {
        return 'cd ' . escapeshellarg($workspace) . ' && ' . $command;
    }
}
