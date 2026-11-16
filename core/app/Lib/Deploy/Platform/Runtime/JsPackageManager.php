<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\AccountHomeRead;

/**
 * npm/yarn/pnpm/bun mechanics shared by every JS recipe: which manager a repo uses, its commands,
 * and the install-time quirks (frozen lockfiles, corepack pinning, git-hook scripts).
 */
class JsPackageManager
{
    /**
     * The lockfile's manager (bun, pnpm, yarn, npm, in that order), then package.json's `packageManager`,
     * then npm.
     *
     * @param  array<string, true>  $files
     * @param  array<string, mixed>  $package
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
            return 'yarn '.$script;
        }
        if ($packageManager === 'pnpm') {
            return $script === 'start' ? 'pnpm start' : 'pnpm run '.$script;
        }
        if ($packageManager === 'bun') {
            return 'bun run '.$script;
        }

        return $script === 'start' ? 'npm start' : 'npm run '.$script;
    }

    /**
     * $script in every workspace that defines it, from the workspace root.
     * npm and Yarn 1 share the `workspaces` field npm reads.
     */
    public static function workspacesScriptCommand(string $packageManager, string $script): string
    {
        return match ($packageManager) {
            'pnpm' => 'pnpm -r --if-present run '.$script,
            'bun' => "bun run --filter '*' ".$script,
            default => 'npm run '.$script.' --workspaces --if-present',
        };
    }

    /**
     * The lockfile this manager reads, or null when the project ships none.
     * `bun.lock` outranks `bun.lockb`; `package-lock.json` outranks `npm-shrinkwrap.json`.
     *
     * @param  array<string, true>  $files
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
     * Installs corepack on Node 25+, which does not bundle it.
     * Its own `sh -c`, so the `||` cannot swallow a failure earlier in an `&&` chain.
     */
    public const ENSURE_COREPACK = "sh -c 'command -v corepack >/dev/null 2>&1 || npm install -g corepack'";

    /**
     * `bun install`, never frozen: older Bun lockfiles disagree with oven/bun:1. pnpm and yarn through corepack,
     * frozen with a lockfile; `npm ci` with a lockfile, `npm install` without.
     *
     * @param  array<string, true>  $files
     * @param  array<string, mixed>  $package
     */
    public static function installCommand(
        string $pm,
        array $files,
        array $package = [],
        ?string $projectDir = null
    ): string {
        $lock = self::lockfileName($pm, $files);
        if ($pm === 'bun') {
            return self::withCiInstallEnv('bun install');
        }
        if ($pm === 'pnpm') {
            return self::withCiInstallEnv(self::pnpmInstallCommand($lock !== null, $package, $projectDir));
        }
        if ($pm === 'yarn') {
            $flags = $lock !== null
                ? self::yarnLockfileFlag($files, $package, $projectDir)
                : '';
            $flags = trim($flags.' '.self::yarnEnginesFlag($files, $package, $projectDir));

            return self::withCiInstallEnv(
                self::ENSURE_COREPACK.' && corepack enable && yarn install'.($flags === '' ? '' : ' '.$flags)
            );
        }

        return self::withCiInstallEnv(
            $lock !== null
                ? 'npm ci --no-audit --no-fund'
                : 'npm install --no-audit --no-fund'
        );
    }

    /**
     * Quiets husky / lefthook hooks, which fail without .git; CI=1 skips interactive postinstall prompts.
     * Idempotent: a command already carrying HUSKY=0 is returned unchanged.
     */
    public static function withCiInstallEnv(string $command): string
    {
        $command = trim($command);
        if ($command === '' || preg_match('/\bHUSKY=0\b/', $command) === 1) {
            return $command;
        }

        return 'HUSKY=0 LEFTHOOK=0 CI=1 '.$command;
    }

    /**
     * A root lifecycle script that cannot run before install: a git-hook tool never can (no .git in a build),
     * and one running a repository file cannot while the source is absent (`$sourcePresent`).
     */
    public static function isGitHookInstallerScript(string $script, bool $sourcePresent = false): bool
    {
        return self::referencesGitHookTool($script)
            || (! $sourcePresent && self::referencesLocalScript($script));
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
     * A lifecycle script running a repository file, which the manifests-first layer has not copied yet.
     * Paths under `node_modules/` and inline `node -e '…'` do not count.
     */
    public static function referencesLocalScript(string $script): bool
    {
        $script = trim($script);
        if ($script === '') {
            return false;
        }

        $pattern = '#(?:^|[\s;&|(])(?:node|bash|sh|zsh|dash|bun|deno|ts-node|tsx)\s+'
            .'(?:\./)?((?:[\w.-]+/)*[\w.-]+\.(?:cjs|mjs|js|ts|sh))\b#i';

        if (preg_match_all($pattern, $script, $matches) === 0) {
            return false;
        }

        foreach ($matches[1] as $path) {
            if (! str_starts_with(strtolower($path), 'node_modules/')) {
                return true;
            }
        }

        return false;
    }

    /** Root scripts npm, pnpm, Yarn and bun run during a plain install. */
    public const INSTALL_LIFECYCLE_SCRIPTS = ['preinstall', 'install', 'postinstall', 'prepare'];

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    public static function stripGitHookInstallerScripts(array $package, bool $sourcePresent = false): array
    {
        if (! isset($package['scripts']) || ! is_array($package['scripts'])) {
            return $package;
        }
        foreach (self::INSTALL_LIFECYCLE_SCRIPTS as $name) {
            $script = $package['scripts'][$name] ?? null;
            if (is_string($script) && self::isGitHookInstallerScript($script, $sourcePresent)) {
                unset($package['scripts'][$name]);
            }
        }

        return $package;
    }

    /** Seconds before the strip script is killed; `bun -e` can hang on it. */
    private const STRIP_TIMEOUT_SECONDS = 30;

    /**
     * Writes package.json only when a script goes, leaving a bind-mounted checkout untouched otherwise.
     * Bounded and allowed to fail: a hanging RUN pins a core, while a failed `husky install` is visible.
     */
    public static function dockerfileStripGitHookScriptsCommand(string $pm, bool $sourcePresent = false): string
    {
        $localRule = self::localScriptRule($sourcePresent);
        $localTest = $sourcePresent ? '' : '||localHit(s)';

        $js = 'const fs=require("fs");'
            .'const p=JSON.parse(fs.readFileSync("package.json","utf8"));'
            .'if(!p.scripts)process.exit(0);'
            .'const re=/\\b(husky|lefthook|simple-git-hooks|yorkie|pre-commit)\\b/i;'
            .$localRule
            .'let removed=false;'
            .'for(const k of["preinstall","install","postinstall","prepare"]){'
            .'const s=p.scripts[k];'
            .'if(typeof s!=="string")continue;'
            .'if(re.test(s)'.$localTest.'){delete p.scripts[k];removed=true;}'
            .'}'
            .'if(removed)fs.writeFileSync("package.json",JSON.stringify(p));';

        $runner = $pm === 'bun' ? 'bun' : 'node';

        return 'timeout '.self::STRIP_TIMEOUT_SECONDS.' '
            .$runner.' -e '."'".$js."'"
            .' || true';
    }

    /**
     * The repository-file matcher, only where the file is absent. It loops every match: inspecting only the
     * first kept `node node_modules/foo/build.js && node scripts/y.js`.
     */
    private static function localScriptRule(bool $sourcePresent): string
    {
        return $sourcePresent
            ? ''
            : 'const local=/(?:^|[\\s;&|(])(?:node|bash|sh|zsh|dash|bun|deno|ts-node|tsx)\\s+(?:\\.\\/)?((?:[\\w.-]+\\/)*[\\w.-]+\\.(?:cjs|mjs|js|ts|sh))\\b/gi;'
                .'const localHit=s=>{local.lastIndex=0;let m;'
                .'while((m=local.exec(s))!==null){'
                .'if(m[1].toLowerCase().indexOf("node_modules/")!==0)return true;}'
                .'return false;};';
    }

    /**
     * npm/bun/pnpm workspace root: install needs every package.json, not only the root manifest.
     *
     * @param  array<string, mixed>  $package
     * @param  array<string, true>  $files
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
     * pnpm pinned via corepack, since latest needs Node 22.13; `--dangerously-allow-all-builds` (10.9+) only
     * without a build policy. Never writes pnpm-workspace.yaml: that breaks a single-package repo.
     *
     * @param  array<string, mixed>  $package
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
            && ! self::pnpmDeclaresBuildPolicy($package, $projectDir)
        ) {
            $install .= ' --dangerously-allow-all-builds';
        }

        return self::ENSURE_COREPACK.' && corepack enable && corepack prepare '.$spec.' --activate && '.$install;
    }

    /**
     * Every spelling of "this project decided which dependencies may run build scripts", pnpm 10's and 11's.
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
     * Whether package.json `pnpm` or pnpm-workspace.yaml sets a build policy, which the flag must not override:
     * pnpm 10 aborts on the conflict, and HedgeDoc's `better-sqlite3: false` was overridden.
     *
     * @param  array<string, mixed>  $package
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
        $path = rtrim($projectDir, '/').'/pnpm-workspace.yaml';
        if (! is_file($path)) {
            return false;
        }
        $raw = AccountHomeRead::contents($path);
        if (! is_string($raw) || $raw === '') {
            return false;
        }

        $keys = implode('|', self::PNPM_BUILD_POLICY_KEYS);

        return preg_match('/^[ \t]*('.$keys.')[ \t]*:/mi', $raw) === 1;
    }

    /**
     * `--frozen-lockfile` for Yarn 1, `--immutable` for Yarn 2+: each rejects the other's (`YN0050`).
     * With no Berry signal (`packageManager`, `.yarnrc.yml`, the lockfile header) it is Classic.
     *
     * @param  array<string, true>  $files
     * @param  array<string, mixed>  $package
     */
    private static function yarnLockfileFlag(array $files, array $package, ?string $projectDir): string
    {
        $major = self::yarnMajor($files, $package, $projectDir);

        return $major !== null && $major >= 2 ? '--immutable' : '--frozen-lockfile';
    }

    /**
     * `--ignore-engines` for Classic Yarn, which refuses any package's unmet `engines.node`; nothing for Berry.
     * A null major, no Berry signal, is Classic as in yarnLockfileFlag().
     *
     * @param  array<string, true>  $files
     * @param  array<string, mixed>  $package
     */
    private static function yarnEnginesFlag(array $files, array $package, ?string $projectDir): string
    {
        $major = self::yarnMajor($files, $package, $projectDir);

        return $major === null || $major < 2 ? '--ignore-engines' : '';
    }

    /**
     * The Yarn major, or null when nothing in the project says which it is.
     *
     * @param  array<string, true>  $files
     * @param  array<string, mixed>  $package
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

        $path = rtrim($projectDir, '/').'/yarn.lock';
        if (! is_file($path)) {
            return null;
        }
        $raw = (string) AccountHomeRead::contents($path);
        if (preg_match('/^__metadata:/m', $raw) === 1) {
            return 2;
        }
        if (stripos($raw, '# yarn lockfile v1') !== false) {
            return 1;
        }

        return null;
    }

    /**
     * Whether the install's lifecycle scripts or $build call `php` or `composer` (selfoss's postinstall), through
     * the scripts they run and, with $read, the gulpfile or Gruntfile they use and literal workspace members.
     *
     * @param  array<string, mixed>  $package
     * @param  (callable(string): ?string)|null  $read
     */
    public static function scriptsCallPhp(array $package, string $build, ?callable $read = null): bool
    {
        $scripts = is_array($package['scripts'] ?? null) ? $package['scripts'] : [];
        $pending = [$build];
        foreach (['preinstall', 'install', 'postinstall', 'prepare'] as $hook) {
            $pending[] = is_string($scripts[$hook] ?? null) ? $scripts[$hook] : '';
        }
        $visited = '';
        if (self::scriptBodiesCallPhp($scripts, $pending, $visited)) {
            return true;
        }
        if ($read === null) {
            return false;
        }

        return self::taskRunnerCallsPhp($visited, $read) || self::workspaceMemberCallsPhp($package, $read);
    }

    /**
     * Walks the bodies and every `npm run x` / `yarn x` they name, with its pre and post scripts.
     *
     * @param  array<string, mixed>  $scripts
     * @param  list<string>  $pending
     */
    private static function scriptBodiesCallPhp(array $scripts, array $pending, string &$visited): bool
    {
        $seen = [];
        while ($pending !== []) {
            $body = (string) array_shift($pending);
            $visited .= $body."\n";
            if (preg_match('/(?:^|[\s;&|(])(?:php|composer)(?:\s|$)/', $body) === 1) {
                return true;
            }
            preg_match_all('/\b(?:(?:npm|pnpm)\s+run(?:-script)?|yarn(?:\s+run)?)\s+([\w:.-]+)/', $body, $m);
            foreach ($m[1] as $name) {
                foreach (["pre{$name}", $name, "post{$name}"] as $script) {
                    if (! isset($seen[$script]) && is_string($scripts[$script] ?? null)) {
                        $seen[$script] = true;
                        $pending[] = $scripts[$script];
                    }
                }
            }
        }

        return false;
    }

    /**
     * A shell command in a string of a visited runner's config: OSPOS's gulp task runs `composer licenses`.
     *
     * @param  callable(string): ?string  $read
     */
    private static function taskRunnerCallsPhp(string $visited, callable $read): bool
    {
        foreach (self::TASK_RUNNER_FILES as $runner => $files) {
            if (preg_match('/\b'.$runner.'\b/i', $visited) !== 1) {
                continue;
            }
            foreach ($files as $file) {
                $contents = $read($file);
                if ($contents !== null && preg_match('/[\'"`]\s*(?:php|composer)\s/', $contents) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $package
     * @param  callable(string): ?string  $read
     */
    private static function workspaceMemberCallsPhp(array $package, callable $read): bool
    {
        foreach (self::workspaces($package) as $workspace) {
            if (str_contains($workspace, '*')) {
                continue;
            }
            $member = json_decode((string) $read($workspace.'/package.json'), true);
            if (is_array($member) && self::scriptsCallPhp($member, '')) {
                return true;
            }
        }

        return false;
    }

    /** Task runner => the config files it reads from the working directory. */
    private const TASK_RUNNER_FILES = [
        'gulp' => ['gulpfile.js', 'gulpfile.mjs', 'gulpfile.cjs', 'gulpfile.ts', 'gulpfile.babel.js'],
        'grunt' => ['Gruntfile.js', 'Gruntfile.cjs', 'Gruntfile.coffee', 'gruntfile.js'],
    ];

    /**
     * Yarn 2+ on Plug'n'Play, whose install writes no node_modules and deletes any it finds.
     * From file contents, so a caller reading through the account's filesystem can ask.
     *
     * @param  array<string, mixed>  $package
     */
    public static function isYarnPnp(array $package, ?string $yarnrc, ?string $yarnLock): bool
    {
        $field = $package['packageManager'] ?? null;
        if (is_string($field) && $field !== '') {
            if (preg_match('/^yarn@(\d+)/', $field, $m) !== 1 || (int) $m[1] < 2) {
                return false;
            }
        } elseif ($yarnLock === null || ($yarnrc === null && preg_match('/^__metadata:/m', $yarnLock) !== 1)) {
            return false;
        }

        return $yarnrc === null
            || preg_match('/^\s*nodeLinker\s*:\s*[\'"]?(?:node-modules|pnpm)\b/m', $yarnrc) !== 1;
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private static function corepackPnpmSpec(array $package): string
    {
        $field = $package['packageManager'] ?? '';
        if (is_string($field) && preg_match('/^pnpm@(\d+(?:\.\d+){0,2})/', $field, $matches) === 1) {
            return 'pnpm@'.$matches[1];
        }

        return 'pnpm@10';
    }

    /** pnpm@10 with no minor resolves to the latest 10.x, which includes 10.9+. */
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
        if (! isset($matches[2]) || $matches[2] === '') {
            return true;
        }

        return (int) $matches[2] >= 9;
    }

    /**
     * Build/start for a workspace: `<kind>:<slug>`, then the root script (a bare `turbo build` gets `--filter`),
     * else the framework default, run in the workspace, where its binary is installed (Teable's root has none).
     *
     * @param  array<string, mixed>  $scripts
     * @param  array<string, mixed>  $partial
     */
    public static function resolveLifecycleCommand(
        string $pm,
        array $scripts,
        string $kind,
        array $partial
    ): string {
        $slug = trim((string) ($partial['workspace_slug'] ?? ''));
        $pkg = trim((string) ($partial['workspace_package'] ?? ''));
        $defaultKey = 'default_'.$kind;

        if ($slug !== '' && self::hasScript($scripts, $kind.':'.$slug)) {
            return self::scriptCommand($pm, $kind.':'.$slug);
        }

        if (self::hasScript($scripts, $kind)) {
            $body = trim((string) $scripts[$kind]);
            if (self::turboNeedsPackageFilter($body) && $pkg !== '') {
                return self::turboFilteredCommand($pm, $kind, $pkg, $body);
            }

            return self::scriptCommand($pm, $kind);
        }

        if (empty($partial[$defaultKey])) {
            return '';
        }

        $relative = trim((string) ($partial['workspace_relative'] ?? ''), '/');

        return $relative === ''
            ? (string) $partial[$defaultKey]
            : self::inWorkspace((string) $partial[$defaultKey], $relative);
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
            ? 'turbo run '.$kind.' --filter='.$filter.'...'
            : 'turbo build --filter='.$filter.'...';

        $prefix = '';
        if (preg_match('/^(dotenv(?:\s+-c)?(?:\s+--)?)\s+/', trim($originalBody), $m) === 1) {
            $prefix = $m[1].' ';
        }

        $runner = match ($pm) {
            'bun' => 'bunx ',
            'pnpm' => 'pnpm exec ',
            'yarn' => 'yarn exec ',
            default => 'npx ',
        };

        return $prefix.$runner.$turbo;
    }

    /**
     * A script is present only when it exists and is a non-empty string.
     *
     * @param  array<string, mixed>  $scripts
     */
    public static function hasScript(array $scripts, string $name): bool
    {
        return isset($scripts[$name]) && is_string($scripts[$name]) && trim($scripts[$name]) !== '';
    }

    /**
     * The workspace paths a root package.json declares, as a list or under `packages`, globs as written.
     * Only paths inside the repository (absolute checked before trimming): a shell command runs in them.
     *
     * @param  array<string, mixed>  $package
     * @return list<string>
     */
    public static function workspaces(array $package): array
    {
        $workspaces = $package['workspaces'] ?? null;
        if (is_array($workspaces) && array_key_exists('packages', $workspaces)) {
            $workspaces = $workspaces['packages'];
        }
        if (! is_array($workspaces)) {
            return [];
        }

        $paths = [];
        foreach ($workspaces as $workspace) {
            if (! is_string($workspace)) {
                continue;
            }
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
     * Where the frontend build runs: the root `build` script, else the first declared workspace with one
     * (Firefly III's v2), else null; `workspace` is '' for the root.
     *
     * @param  array<string, mixed>  $package  the root package.json, decoded
     * @param  callable(string): ?array<string, mixed>  $packageAt  workspace path => its package.json, or null
     * @return array{workspace: string, script: string}|null
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
            if (! is_array($workspaceScripts) || ! self::hasScript($workspaceScripts, 'build')) {
                continue;
            }

            return ['workspace' => $workspace, 'script' => 'build'];
        }

        return null;
    }

    /**
     * A command run in a workspace by `cd`, since every manager spells the filter differently.
     * Escaped, because the path comes out of a customer repository.
     */
    public static function inWorkspace(string $command, string $workspace): string
    {
        return 'cd '.escapeshellarg($workspace).' && '.$command;
    }
}
