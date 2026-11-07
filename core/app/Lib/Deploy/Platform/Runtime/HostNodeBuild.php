<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\ProjectCache;
use App\Lib\Deploy\SafeName;

/**
 * Compiling an account's JS project on the host: interpreter, install and build lines, node_modules reuse.
 * Engine-neutral; the container belongs to HostBuilder::nodeBuildArgv().
 */
class HostNodeBuild
{
    public const BUN_IMAGE = 'oven/bun:1';

    public static function usesJsPackageManager(string $install): bool
    {
        $install = self::stripLeadingEnvAssignments(trim($install));

        return preg_match('/^(npm |yarn |pnpm |corepack |bun |sh -c \'command -v corepack )/', $install) === 1;
    }

    /**
     * Drop leading `KEY=value` tokens so `HUSKY=0 CI=1 bun install` still
     * counts as an install.
     */
    public static function stripLeadingEnvAssignments(string $command): string
    {
        $command = trim($command);
        while (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=(?:\'[^\']*\'|"[^"]*"|\S+)\s+/', $command, $m) === 1) {
            $command = trim(substr($command, strlen($m[0])));
        }

        return $command;
    }

    /** Never frozen: lockfiles from older Bun fail on oven/bun:1. */
    public static function bunInstallCommand(string $original): string
    {
        return JsPackageManager::withCiInstallEnv('bun install');
    }

    /**
     * The lockfile's own interpreter for an on-host compile: a tree another one installed fails on packages
     * only the first resolves, and bun's git-hook strip step hangs.
     */
    public static function compilerImage(string $install, string $nodeImage, string $packageManager = ''): string
    {
        if (! self::usesJsPackageManager($install)) {
            return $nodeImage;
        }

        return self::runtimeImage($packageManager, $nodeImage);
    }

    /**
     * Interpreter the standalone Node runtime must use: stock Node importing a bun-installed tree fails on
     * bun-only packages (`drizzle-orm/bun-sql` → `bun`).
     */
    public static function runtimeImage(string $packageManager, string $nodeImage): string
    {
        return $packageManager === 'bun' ? self::BUN_IMAGE : $nodeImage;
    }

    /**
     * A recipe build command rewritten for bun, whose image has no npm, npx, pnpm, yarn or corepack.
     * Commands calling a binary directly (`PATH=… astro build`) stay.
     */
    public static function bunBuildCommand(string $build): string
    {
        $segments = preg_split('/\s*&&\s*/', trim($build));
        if ($segments === false) {
            return $build;
        }

        return implode(' && ', array_map([self::class, 'bunSegment'], $segments));
    }

    /**
     * The install for the Node retry after a Bun compile failed: the Node image has no bun, and bun.lock
     * is not an npm lockfile, so `npm install`, not ci.
     */
    public static function nodeInstallCommand(string $original): string
    {
        if (! self::usesBun($original)) {
            return $original;
        }

        return JsPackageManager::withCiInstallEnv('npm install --no-audit --no-fund');
    }

    /**
     * Mirror of bunBuildCommand for the Node retry: `bun run x` back to
     * `npm run x`, `bunx` back to `npx`.
     */
    public static function nodeBuildCommand(string $build): string
    {
        $segments = preg_split('/\s*&&\s*/', trim($build));
        if ($segments === false) {
            return $build;
        }

        return implode(' && ', array_map([self::class, 'nodeSegment'], $segments));
    }

    public static function usesBun(string $command): bool
    {
        $command = self::stripLeadingEnvAssignments(trim($command));

        return preg_match('/^bunx?\s/', $command) === 1;
    }

    /** Keeps a leading `FOO=bar ` prefix; a bare `bun build` is a script name with no run keyword. */
    private static function nodeSegment(string $segment): string
    {
        $trimmed = trim($segment);
        $body = self::stripLeadingEnvAssignments($trimmed);
        $prefix = substr($trimmed, 0, strlen($trimmed) - strlen($body));

        $patterns = [
            '/^bun\s+run\s+/' => 'npm run ',
            '/^bunx\s+/' => 'npx ',
            '/^bun\s+(?!add\b|install\b|run\b|x\b)/' => 'npm run ',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $count = 0;
            $rewritten = preg_replace($pattern, $replacement, $body, 1, $count);
            if ($count > 0 && is_string($rewritten)) {
                return $prefix.$rewritten;
            }
        }

        return $segment;
    }

    /** `yarn build` and `npm start` are script names with no run keyword. */
    private static function bunSegment(string $segment): string
    {
        $patterns = [
            '/^(?:npm|pnpm|yarn)\s+run\s+/' => 'bun run ',
            '/^(?:npx|pnpm\s+dlx|yarn\s+dlx)\s+/' => 'bunx ',
            '/^(?:npm|pnpm|yarn)\s+(?!add\b|ci\b|dlx\b|exec\b|install\b)/' => 'bun run ',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $count = 0;
            $rewritten = preg_replace($pattern, $replacement, $segment, 1, $count);
            if ($count > 0 && is_string($rewritten)) {
                return $rewritten;
            }
        }

        return $segment;
    }

    /**
     * An install split into provisioning (`corepack enable`), which must run even on a cache hit, and the
     * install itself, which keeps the leading `HUSKY=0 …` env.
     *
     * @return array{0: string, 1: string} provisioning, install
     */
    public static function splitToolingPrefix(string $install): array
    {
        $trimmed = trim($install);
        $body = self::stripLeadingEnvAssignments($trimmed);
        $env = substr($trimmed, 0, strlen($trimmed) - strlen($body));

        $segments = preg_split('/\s*&&\s*/', $body);
        if ($segments === false || $segments === ['']) {
            return ['', ''];
        }

        $prepare = [];
        while ($segments !== [] && preg_match('/^(?:corepack\s|sh -c \'command -v corepack\s|npm\s+install\s+-g\s)/', $segments[0]) === 1) {
            $prepare[] = array_shift($segments);
        }

        $rest = implode(' && ', $segments);

        return [implode(' && ', $prepare), $rest === '' ? '' : $env.$rest];
    }

    /** Where provisioning() puts corepack's shims and a missing corepack. */
    public const PROVISIONED_PATH = '/tmp/corepack-bin:/tmp/corepack-npm/bin';

    /**
     * An install's provisioning part rewritten to run as the account, which cannot write npm's global prefix
     * or /usr/local/bin: both go under /tmp, on PROVISIONED_PATH.
     */
    public static function provisioning(string $install): string
    {
        [$prepare] = self::splitToolingPrefix($install);

        return str_replace(
            ['npm install -g corepack', 'corepack enable'],
            [
                'npm install -g --prefix /tmp/corepack-npm corepack',
                'mkdir -p /tmp/corepack-bin && corepack enable --install-directory /tmp/corepack-bin',
            ],
            $prepare
        );
    }

    /**
     * A non-JavaScript host compile: the recipe's install and build only, since innerScript()'s lockfile
     * copying fails there on `cp: can't stat '/app/package-lock.json'`.
     */
    public static function plainScript(string $install, string $build): string
    {
        $parts = array_values(array_filter([trim($install), trim($build)], static fn (string $p): bool => $p !== ''));

        return implode(' && ', $parts);
    }

    /**
     * @param  string  $dir  the application in the build container: /app, or /app/<app_root>
     */
    public static function innerScript(string $install, string $build, string $dir = '/app'): string
    {
        if (trim($install) === '' && trim($build) === '') {
            return '';
        }
        $parts = [];
        $build = trim($build);
        $prepare = self::provisioning($install);
        [, $install] = self::splitToolingPrefix($install);
        $parts[] = 'export PATH='.self::PROVISIONED_PATH.':$PATH';
        if ($prepare !== '') {
            $parts[] = $prepare;
        }
        if ($install !== '') {
            $parts[] = self::stripGitHooksStep($install, $dir);
            $parts[] = self::cachedInstallStep($install, $dir);
        }
        if ($build !== '') {
            $parts[] = $build;
        }

        return implode(' && ', $parts);
    }

    /** /app is a bind mount of the repo, so only the git-hook tools are stripped: they cannot run without a .git. */
    private static function stripGitHooksStep(string $install, string $dir): string
    {
        $strip = str_contains($install, 'bun ')
            ? JsPackageManager::dockerfileStripGitHookScriptsCommand('bun', sourcePresent: true)
            : JsPackageManager::dockerfileStripGitHookScriptsCommand('npm', sourcePresent: true);

        return 'if [ -f '.$dir.'/package.json ]; then '.$strip.'; fi';
    }

    /**
     * The install, skipped while node_modules/.pa-lock matches a lockfile. Only an existing lockfile is copied:
     * a failing `cp` printed `cannot stat` last on every pnpm build, which reads as the cause of a failure.
     */
    private static function cachedInstallStep(string $install, string $dir): string
    {
        return 'if [ -f '.$dir.'/node_modules/.pa-lock ] && { '
            .'cmp -s '.$dir.'/package-lock.json '.$dir.'/node_modules/.pa-lock '
            .'|| cmp -s '.$dir.'/pnpm-lock.yaml '.$dir.'/node_modules/.pa-lock '
            .'|| cmp -s '.$dir.'/yarn.lock '.$dir.'/node_modules/.pa-lock '
            .'|| cmp -s '.$dir.'/bun.lock '.$dir.'/node_modules/.pa-lock; '
            .'}; then echo "node_modules cache hit"; '
            .'else '.$install.' && { '
            .'for f in package-lock.json pnpm-lock.yaml yarn.lock bun.lock; do '
            .'if [ -f "'.$dir.'/$f" ]; then cp "'.$dir.'/$f" '.$dir.'/node_modules/.pa-lock; break; fi; '
            .'done; true; }; fi';
    }

    public static function isSafeProjectDir(string $dir): bool
    {
        return preg_match('#^/home/[a-zA-Z0-9_.-]+/project$#', $dir) === 1;
    }

    public static function nodeModulesVolumeName(string $username): string
    {
        SafeName::assert($username, 'username for node_modules volume');

        return 'pa-nm-'.$username;
    }

    /**
     * The project's cache directory (npm/, pnpm/, yarn/, bun/, node_modules/);
     * the build container mounts the whole directory.
     */
    public static function cacheDirFor(string $username): string
    {
        return ProjectCache::dirFor($username);
    }
}
