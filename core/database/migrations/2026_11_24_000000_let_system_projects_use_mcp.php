<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A system project allowed every project before --full-privileges included MCP holds an API-only token, so the panel
 * it is cannot mint the MCP tokens of Connect AI or its OAuth connections. Its token gains MCP in place.
 */
return new class extends Migration
{
    private const API_ACCESS = 'api_access_projects';

    public function up(): void
    {
        $entries = $this->setting();
        $system = $this->systemProjects();
        $changed = false;

        foreach ($entries as $index => $entry) {
            if (! $this->needsMcp($entry, $system)) {
                continue;
            }

            $this->addMcp((int) $entry['token_id']);
            $entries[$index]['mcp'] = true;
            $changed = true;
        }

        if ($changed) {
            DB::table('settings')->where('name', self::API_ACCESS)->update(['value' => json_encode(array_values($entries))]);
        }
    }

    public function down(): void {}

    /** @param list<string> $system */
    private function needsMcp(mixed $entry, array $system): bool
    {
        return is_array($entry)
            && ($entry['scope'] ?? null) === 'all'
            && ($entry['mcp'] ?? false) !== true
            && is_int($entry['token_id'] ?? null)
            && in_array($entry['project'] ?? null, $system, true);
    }

    private function addMcp(int $tokenId): void
    {
        $abilities = json_decode((string) DB::table('personal_access_tokens')->where('id', $tokenId)->value('abilities'), true);
        if (! is_array($abilities) || in_array('mcp', $abilities, true)) {
            return;
        }

        $abilities[] = 'mcp';
        DB::table('personal_access_tokens')->where('id', $tokenId)->update(['abilities' => json_encode(array_values($abilities))]);
    }

    /** @return list<string> */
    private function systemProjects(): array
    {
        $projects = [];
        foreach (DB::table('users')->orderBy('id')->get(['username', 'details']) as $user) {
            $details = json_decode((string) $user->details, true);
            if (is_array($details) && ($details['system'] ?? null) === 'on') {
                $projects[] = (string) $user->username;
            }
        }

        return $projects;
    }

    /** @return array<array-key, mixed> */
    private function setting(): array
    {
        $decoded = json_decode((string) DB::table('settings')->where('name', self::API_ACCESS)->value('value'), true);

        return is_array($decoded) ? $decoded : [];
    }
};
