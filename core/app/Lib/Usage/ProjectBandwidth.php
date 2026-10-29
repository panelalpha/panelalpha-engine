<?php

namespace App\Lib\Usage;

use App\Integrations\Statistics\Statistics;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * This calendar month's transfer of every project, read from the statistics in
 * the background and stored, so listing projects never reads them.
 */
class ProjectBandwidth
{
    private const TABLE = 'project_bandwidth_usage';

    public function __construct(private Statistics $statistics) {}

    /** @return int projects measured */
    public function sample(): int
    {
        $now = Carbon::now();
        $rows = [];
        foreach ($this->domainsByProject() as $username => $domains) {
            $bytes = $this->monthBytes($username, $domains);
            if ($bytes !== null) {
                $rows[] = [
                    'username' => $username,
                    'month' => $now->format('Y-m'),
                    'used_bytes' => $bytes,
                    'measured_at' => $now->copy()->utc()->format('Y-m-d H:i:s'),
                ];
            }
        }

        DB::transaction(function () use ($rows): void {
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table(self::TABLE)->upsert($chunk, ['username'], ['month', 'used_bytes', 'measured_at']);
            }
            DB::table(self::TABLE)->whereNotIn('username', User::query()->select('username'))->delete();
        });

        return count($rows);
    }

    /**
     * Every stored measurement; empty before the first one. Every project view
     * reads this, so a table not migrated yet means nothing measured, not a 500.
     *
     * @return array<string, array{month: string, used_bytes: int, measured_at: string}> username => measurement
     */
    public static function all(): array
    {
        try {
            $rows = DB::table(self::TABLE)->orderBy('username')->get();
        } catch (QueryException) {
            return [];
        }

        $measured = [];
        foreach ($rows as $row) {
            $measured[(string) $row->username] = [
                'month' => (string) $row->month,
                'used_bytes' => (int) $row->used_bytes,
                'measured_at' => Carbon::parse((string) $row->measured_at, 'UTC')->toIso8601ZuluString(),
            ];
        }

        return $measured;
    }

    /** @param list<string> $domains */
    private function monthBytes(string $username, array $domains): ?int
    {
        try {
            return $this->statistics->projectCalendarMonthBytes($domains);
        } catch (\Throwable $e) {
            Log::warning("Could not read this month's transfer of {$username}: ".$e->getMessage());

            return null;
        }
    }

    /** @return array<string, list<string>> username => domain names */
    private function domainsByProject(): array
    {
        $domains = [];
        foreach (User::query()->pluck('username', 'id') as $id => $username) {
            $domains[(int) $id] = ['username' => (string) $username, 'names' => []];
        }
        foreach (Domain::query()->get(['user_id', 'domain']) as $domain) {
            if (isset($domains[(int) $domain->user_id])) {
                $domains[(int) $domain->user_id]['names'][] = (string) $domain->domain;
            }
        }

        return array_column($domains, 'names', 'username');
    }
}
