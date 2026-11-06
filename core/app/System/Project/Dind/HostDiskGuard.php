<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\System;
use App\System\Privileged;

/**
 * Refuse a deploy up front when the engine host is nearly out of disk, not as ENOSPC blamed on the account deploying.
 * Checked where a deploy writes: the host's Docker root (base images, host builds) and /home (every inner Docker).
 */
final class HostDiskGuard
{
    /** Roughly one real PHP deploy (a Matomo account measured 2.1G) plus margin. */
    public const DEFAULT_MINIMUM = '3G';

    /** Where the host keeps images, build cache and the cache registry's volume. */
    public const DOCKER_ROOT = '/var/lib/docker';

    public const PATHS = [self::DOCKER_ROOT, '/home'];

    public function __construct(
        private readonly System $system,
        private readonly ?int $minimumBytes,
    ) {}

    /**
     * DEPLOY_HOST_MIN_FREE as bytes, or null when the check is off. Empty means the default; an unparseable
     * value falls back to it rather than blocking every deploy.
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
     * What to fail the deploy with, or null to go ahead. A free-space
     * reading that cannot be taken is not a reason to refuse.
     */
    public function refusal(): ?HostDiskLowException
    {
        if ($this->minimumBytes === null) {
            return null;
        }

        $lowest = $this->lowest();
        if ($lowest === null || $lowest['free'] >= $this->minimumBytes) {
            return null;
        }

        return new HostDiskLowException(sprintf(
            'Deploy refused before it started: the engine host has %s free on %s, below the %s '
            .'a deploy needs (DEPLOY_HOST_MIN_FREE). Free disk on the host and deploy again; '
            .'pruning the base images no project has used recently frees the most.',
            HostPrewarmPlan::formatBytes($lowest['free']),
            $lowest['path'],
            HostPrewarmPlan::formatBytes($this->minimumBytes)
        ), $lowest['path']);
    }

    /**
     * The deploy path with the least free space, or null when none could be read.
     *
     * @return array{path: string, free: int, size: int}|null
     */
    public function lowest(): ?array
    {
        $lowest = null;
        foreach (self::PATHS as $path) {
            $usage = $this->usage($path);
            if ($usage !== null && ($lowest === null || $usage['free'] < $lowest['free'])) {
                $lowest = ['path' => $path] + $usage;
            }
        }

        return $lowest;
    }

    /** @return array{free: int, size: int}|null */
    public function usage(string $path): ?array
    {
        try {
            $output = $this->system->execOnHost(Privileged::probes()->diskFreeInKilobytes($path));
        } catch (\Throwable $e) {
            if (Privileged::hostNamespacesUnavailable($e->getMessage())) {
                throw $e;
            }

            return null;
        }

        $lines = array_values(array_filter(preg_split('/\r?\n/', trim($output)) ?: []));
        if (count($lines) < 2) {
            return null;
        }
        $fields = preg_split('/\s+/', trim($lines[count($lines) - 1])) ?: [];
        if (! isset($fields[1], $fields[3]) || ! ctype_digit($fields[1]) || ! ctype_digit($fields[3])) {
            return null;
        }

        return ['free' => (int) $fields[3] * 1024, 'size' => (int) $fields[1] * 1024];
    }
}
