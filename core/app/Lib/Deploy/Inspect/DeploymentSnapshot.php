<?php

namespace App\Lib\Deploy\Inspect;

/**
 * What a project's last deploy decided, next to what its files say now. The engine
 * freezes the strategy, port and commit of every deploy onto the account; without that
 * an inspection reports what the files *would* deploy, and the drift is the finding.
 */
final class DeploymentSnapshot
{
    /**
     * Fields worth comparing, as [drift name, details key, path into the report].
     *
     * @var list<array{0: string, 1: string, 2: list<string>}>
     */
    private const COMPARED = [
        ['strategy', 'deploy_strategy', ['application', 'strategy']],
        ['runtime', 'deploy_runtime', ['application', 'runtime']],
        ['port', 'deploy_port', ['ports', 'primary']],
    ];

    /**
     * @param  array<string, mixed>  $details  the account's details
     * @return array<string, mixed>
     */
    public static function describe(array $details): array
    {
        return [
            'status' => self::stringOrNull($details['deployment_status'] ?? null) ?? 'unknown',
            'warnings' => is_array($details['deployment_warnings'] ?? null)
                ? array_values($details['deployment_warnings'])
                : [],
            'source' => self::stringOrNull($details['deploy_source'] ?? null),
            'strategy' => self::stringOrNull($details['deploy_strategy'] ?? null),
            'label' => self::stringOrNull($details['deploy_label'] ?? null),
            // The recipe, not the strategy it shares: `html` and `static` are both the
            // static strategy. It is also the value to send back as `recipe`.
            'platform' => self::stringOrNull($details['deploy_platform'] ?? null),
            'runtime' => self::stringOrNull($details['deploy_runtime'] ?? null),
            'port' => self::intOrNull($details['deploy_port'] ?? null),
            'app_port' => self::intOrNull($details['app_port'] ?? null),
            'repository' => self::stringOrNull($details['git_repo'] ?? null),
            'branch' => self::stringOrNull($details['git_branch'] ?? null),
            'commit' => self::stringOrNull($details['git_commit'] ?? null),
        ];
    }

    /**
     * Where the deployed snapshot and the current files disagree. Silent when the
     * account has never deployed: everything differs from nothing.
     *
     * @param  array<string, mixed>  $details  the account's details
     * @param  array<string, mixed>  $report  what AppInspector just found
     * @param  ?string  $commit  the commit the project directory is on now
     * @return list<array{field: string, deployed: mixed, detected: mixed}>
     */
    public static function drift(array $details, array $report, ?string $commit = null): array
    {
        $drift = [];

        foreach (self::COMPARED as [$field, $key, $path]) {
            $deployed = $details[$key] ?? null;
            if ($deployed === null || $deployed === '') {
                continue;
            }
            $detected = self::dig($report, $path);
            if ($detected === null) {
                continue;
            }
            if (self::comparable($deployed) !== self::comparable($detected)) {
                $drift[] = ['field' => $field, 'deployed' => $deployed, 'detected' => $detected];
            }
        }

        $deployedCommit = self::stringOrNull($details['git_commit'] ?? null);
        if ($deployedCommit !== null && $commit !== null && $deployedCommit !== $commit) {
            $drift[] = ['field' => 'commit', 'deployed' => $deployedCommit, 'detected' => $commit];
        }

        return $drift;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<string>  $path
     */
    private static function dig(array $report, array $path): mixed
    {
        $value = $report;
        foreach ($path as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /** A port frozen as the string "3000" is the same port as the int 3000. */
    private static function comparable(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : json_encode($value);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
