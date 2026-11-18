<?php

namespace App\Lib\Deploy\Platform\Runtime;

/**
 * A lockfile the frozen install refused because it no longer matches package.json, and the same install without
 * the freeze: the dependencies the project declares are the ones it builds with, as a plain `npm install` would.
 * The checkout keeps its own lockfile; only node_modules holds what the unfrozen install resolved.
 */
final class StaleLockfile
{
    /**
     * What npm ci, pnpm, Yarn 1 and Yarn Berry print when the lockfile disagrees with package.json.
     *
     * @var list<string>
     */
    private const REFUSALS = [
        '/can only install packages when your package\.json and package-lock\.json(?: or npm-shrinkwrap\.json)? are in sync/i',
        '/ERR_PNPM_OUTDATED_LOCKFILE/',
        '/ERR_PNPM_LOCKFILE_CONFIG_MISMATCH/',
        '/Your lockfile needs to be updated, but yarn was run with `--frozen-lockfile`/i',
        '/YN0028\b/',
    ];

    /**
     * Each frozen spelling and its unfrozen one; pnpm and Berry freeze by default under CI=1, so they are told not to.
     *
     * @var array<string, string>
     */
    private const UNFROZEN = [
        '/^npm ci\b/' => 'npm install',
        '/^(pnpm install\b.*?)\s--frozen-lockfile\b/' => '$1 --no-frozen-lockfile',
        '/^(yarn install\b.*?)\s--frozen-lockfile\b/' => '$1',
        '/^(yarn install\b.*?)\s--immutable\b/' => '$1 --no-immutable',
    ];

    /** The lockfiles an unfrozen install may rewrite, put back after it. */
    private const LOCKFILES = 'package-lock.json npm-shrinkwrap.json pnpm-lock.yaml yarn.lock';

    public static function refusedBy(string $failure): bool
    {
        foreach (self::REFUSALS as $pattern) {
            if (preg_match($pattern, $failure) === 1) {
                return true;
            }
        }

        return false;
    }

    /** $install with its frozen step installing from package.json, or null when no step is frozen. */
    public static function unfrozen(string $install): ?string
    {
        $segments = preg_split('/\s+&&\s+/', trim($install)) ?: [];
        $changed = false;
        foreach ($segments as $i => $segment) {
            $unfrozen = self::unfrozenSegment($segment);
            if ($unfrozen !== null) {
                $segments[$i] = $unfrozen;
                $changed = true;
            }
        }

        return $changed ? implode(' && ', $segments) : null;
    }

    /** One `&&` step, keeping its leading `KEY=value` env, or null when it is not a frozen install. */
    private static function unfrozenSegment(string $segment): ?string
    {
        $command = HostNodeBuild::stripLeadingEnvAssignments($segment);
        $env = substr(trim($segment), 0, strlen(trim($segment)) - strlen($command));
        $unfrozen = $command;
        foreach (self::UNFROZEN as $pattern => $replacement) {
            $unfrozen = (string) preg_replace($pattern, $replacement, $unfrozen, 1);
        }
        if ($unfrozen === $command || str_contains($unfrozen, "'")) {
            return null;
        }

        return $env.self::keepingLockfiles($unfrozen);
    }

    /** $command in its own shell, the checkout's lockfiles copied aside first and back after, its exit code kept. */
    private static function keepingLockfiles(string $command): string
    {
        $lockfiles = self::LOCKFILES;

        return "sh -c 'for f in {$lockfiles}; do if [ -f \"\$f\" ]; then cp \"\$f\" \"/tmp/pa-lock.\$f\"; fi; done; "
            .$command.'; s=$?; '
            ."for f in {$lockfiles}; do if [ -f \"/tmp/pa-lock.\$f\" ]; then cp \"/tmp/pa-lock.\$f\" \"\$f\"; fi; done; "
            ."exit \$s'";
    }
}
