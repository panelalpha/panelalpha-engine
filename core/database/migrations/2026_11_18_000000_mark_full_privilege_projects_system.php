<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A project installed with --full-privileges before `system` existed holds an API token for every project and is
 * protected. It is the engine's own application, so it becomes system.
 */
return new class extends Migration
{
    private const API_ACCESS = 'api_access_projects';

    public function up(): void
    {
        $candidates = $this->allowedEveryProject();
        if ($candidates === []) {
            return;
        }

        DB::table('users')->whereIn('username', $candidates)->orderBy('id')->get(['id', 'details'])
            ->each(function (object $user): void {
                $details = json_decode((string) $user->details, true);
                if (is_array($details) && ($details['protected'] ?? null) === 'on') {
                    $details['system'] = 'on';
                    DB::table('users')->where('id', $user->id)->update(['details' => json_encode($details)]);
                }
            });
    }

    public function down(): void {}

    /** @return list<string> */
    private function allowedEveryProject(): array
    {
        $projects = [];
        foreach ($this->setting(self::API_ACCESS) as $entry) {
            if (is_array($entry) && ($entry['scope'] ?? null) === 'all' && is_string($entry['project'] ?? null)) {
                $projects[] = $entry['project'];
            }
        }

        return $projects;
    }

    /** @return array<array-key, mixed> */
    private function setting(string $name): array
    {
        $decoded = json_decode((string) DB::table('settings')->where('name', $name)->value('value'), true);

        return is_array($decoded) ? $decoded : [];
    }
};
