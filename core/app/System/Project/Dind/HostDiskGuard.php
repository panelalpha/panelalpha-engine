<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\System;

/**
 * Refuse a deploy up front when the engine host is nearly out of disk.
 *
 * Without it a full host surfaces as ENOSPC from whichever step hit it first,
 * explained as the account's problem, on whatever app happened to be deploying.
 * Checked on the host filesystems a deploy writes to: the host's Docker root
 * (base images, host builds) and /home (every account's inner Docker).
 */
final class HostDiskGuard
{
    /** Roughly one real PHP deploy (a Matomo account measured 2.1G) plus margin. */
    public const DEFAULT_MINIMUM = '3G';

    public const PATHS = ['/var/lib/docker', '/home'];

    public function __construct(
        private readonly System $system,
        private readonly ?int $minimumBytes,
    ) {
    }

    /**
     * DEPLOY_HOST_MIN_FREE as bytes, or null when the check is off. Empty means
     * the default; an unparseable value falls back to it rather than blocking
     * every deploy.
     */
    public static function minimumFrom(mixed $configured): ?int
    {
        $value = is_scalar($configured) ? strtolower(trim((string) $configured)) : '';
        if (in_array($value, ['0', 'off', 'none', 'false'], true)) {
            return null;
        }
        try {
            $bytes = HostPrewarmPlan::parseBytes($value === '' ? self::DEFAULT_MINIMUM : $value);
        } catch (\InvalidArgumentException $e) {
            $bytes = HostPrewarmPlan::parseBytes(self::DEFAULT_MINIMUM);
        }

        return $bytes !== null && $bytes > 0 ? $bytes : null;
    }

    /**
     * The sentence to fail the deploy with, or null to go ahead. A free-space
     * reading that cannot be taken is not a reason to refuse.
     */
    public function refusal(): ?string
    {
        if ($this->minimumBytes === null) {
            return null;
        }

        $lowest = null;
        $where = null;
        foreach (self::PATHS as $path) {
            $free = $this->freeBytes($path);
            if ($free !== null && ($lowest === null || $free < $lowest)) {
                $lowest = $free;
                $where = $path;
            }
        }
        if ($lowest === null || $lowest >= $this->minimumBytes) {
            return null;
        }

        return sprintf(
            'Deploy refused before it started: the engine host has %s free on %s, below the %s '
            . 'a deploy needs (DEPLOY_HOST_MIN_FREE). Free disk on the host and deploy again; '
            . '`pae system:image:prune` removes base images no project has used recently.',
            HostPrewarmPlan::formatBytes($lowest),
            $where,
            HostPrewarmPlan::formatBytes($this->minimumBytes)
        );
    }

    private function freeBytes(string $path): ?int
    {
        try {
            $output = $this->system->execOnHost(['df', '-Pk', $path]);
        } catch (\Throwable $e) {
            return null;
        }

        $lines = array_values(array_filter(preg_split('/\r?\n/', trim($output)) ?: []));
        if (count($lines) < 2) {
            return null;
        }
        $fields = preg_split('/\s+/', trim($lines[count($lines) - 1])) ?: [];

        return isset($fields[3]) && ctype_digit($fields[3]) ? (int) $fields[3] * 1024 : null;
    }
}
