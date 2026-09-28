<?php

namespace App\Lib\Project;

use App\Lib\Host\ProjectMemory;
use App\Lib\Limits\ResourceLimit;

/**
 * The `details` blob a freshly created project starts life with.
 *
 * The resource limits come from {@see ResourceLimit}, so adding one does not
 * mean remembering to list it here as well -- including the asymmetry where
 * disk_space_limit defaults to -1 and every other limit to null.
 */
final class NewProjectDetails
{
    /** Copied straight through when present, null when absent. */
    private const SETTINGS = [
        'php_fpm_pool_settings',
        'lsphp_settings',
        'redis_config',
    ];

    private const SOURCE = [
        'template',
        'git_repo',
        'git_branch',
        // Resolved from the vault before this point: the plaintext, a literal,
        // or null. Encrypted by the User model from here on.
        'git_token',
    ];

    /**
     * @param array<string, mixed> $params validated create parameters
     * @param array<string, mixed> $domainDetails what the allocator resolved
     * @param array<string, string> $envVars
     *
     * @return array<string, mixed>
     */
    public static function build(array $params, array $domainDetails, array $envVars): array
    {
        $username = (string) $params['username'];

        $details = [
            'home_dir' => "/home/{$username}",
            'mysql_prefix' => $username . '_',
        ];

        foreach (ResourceLimit::all() as $limit) {
            $value = $params[$limit->key] ?? ($limit->storesUnlimitedAsMinusOne ? -1 : null);
            // A limit that cannot be unset takes the host default (#294).
            $details[$limit->key] = $limit->alwaysApplies
                ? ProjectMemory::resolve($value === null ? null : (int) $value)
                : $value;
        }

        foreach (self::SETTINGS as $key) {
            $details[$key] = $params[$key] ?? null;
        }

        $details['dedicated_ipv4'] = !empty($params['dedicated_ipv4']);
        $details['dedicated_ipv6'] = !empty($params['dedicated_ipv6']);

        foreach (self::SOURCE as $key) {
            $details[$key] = $params[$key] ?? null;
        }

        $details['env_vars'] = $envVars;
        // Where the name came from and what it is worth: whether it reaches
        // this host from outside, and which preferred rung was skipped and why.
        $details['domain'] = $domainDetails;

        return $details;
    }

    /**
     * The same blob for a clone or a staging mirror, carried over from the
     * project being copied rather than from a request.
     *
     * Not a copy of build(): the source keys are the stored ones, the
     * dedicated-address flags are always off (a copy gets its own address or
     * none), and the git branch and token arrive through
     * {@see \App\Models\User::copiedDeploySnapshot()} instead.
     *
     * @param array<string, mixed> $source the copied project's details
     *
     * @return array<string, mixed>
     */
    public static function forCopy(string $username, array $source): array
    {
        $details = [
            'home_dir' => "/home/{$username}",
            'mysql_prefix' => $username . '_',
        ];

        foreach (ResourceLimit::all() as $limit) {
            $value = $source[$limit->key] ?? ($limit->storesUnlimitedAsMinusOne ? -1 : null);
            // Copying a project made before every project had a memory limit
            // gives the copy the default rather than nothing (#294).
            $details[$limit->key] = $limit->alwaysApplies
                ? ProjectMemory::resolve($value === null ? null : (int) $value)
                : $value;
        }

        foreach (self::SETTINGS as $key) {
            $details[$key] = $source[$key] ?? null;
        }

        $details['dedicated_ipv4'] = false;
        $details['dedicated_ipv6'] = false;
        $details['template'] = $source['template'] ?? null;
        $details['git_repo'] = $source['git_repo'] ?? null;
        $details['app_port'] = $source['app_port'] ?? null;

        return $details;
    }
}
