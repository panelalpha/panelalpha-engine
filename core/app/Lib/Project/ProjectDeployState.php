<?php

namespace App\Lib\Project;

use App\Lib\Deploy\DeployLog\DeployLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Each project's latest deploy as its latest.json last said it, kept in the database so a list reads one table
 * instead of a file per project.
 */
final class ProjectDeployState
{
    private const TABLE = 'project_deploy_states';

    /** @param  array<string, mixed>  $latest  latest.json as written */
    public static function record(string $username, array $latest): void
    {
        try {
            DB::table(self::TABLE)->upsert([[
                'username' => $username,
                'deploy_id' => self::text($latest['id'] ?? null),
                'status' => self::text($latest['status'] ?? null),
                'finished_at' => is_int($latest['finished_at'] ?? null) ? $latest['finished_at'] : null,
                'updated_at' => Carbon::now('UTC'),
            ]], ['username'], ['deploy_id', 'status', 'finished_at', 'updated_at']);
        } catch (QueryException $e) {
            Log::warning("Could not store the deploy state of {$username}: ".$e->getMessage());
        }
    }

    public static function forget(string $username): void
    {
        try {
            DB::table(self::TABLE)->where('username', $username)->delete();
        } catch (QueryException $e) {
            Log::warning("Could not forget the deploy state of {$username}: ".$e->getMessage());
        }
    }

    /**
     * A deploy without an id is a pointer with no deploy behind it, so it has no verdict.
     *
     * @param  list<string>  $usernames
     * @return array<string, array{status: string, finished_at: ?int}>
     */
    public static function of(array $usernames): array
    {
        if ($usernames === []) {
            return [];
        }
        try {
            $rows = DB::table(self::TABLE)->whereIn('username', $usernames)->whereNotNull('deploy_id')->whereNotNull('status')->get();
        } catch (QueryException) {
            return [];
        }

        $states = [];
        foreach ($rows as $row) {
            $states[(string) $row->username] = [
                'status' => (string) $row->status,
                'finished_at' => $row->finished_at === null ? null : (int) $row->finished_at,
            ];
        }

        return $states;
    }

    /** @param  ?array{status: string, finished_at: ?int}  $state */
    public static function isRunning(?array $state): bool
    {
        return ($state['status'] ?? null) === DeployLogger::STATUS_RUNNING;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
