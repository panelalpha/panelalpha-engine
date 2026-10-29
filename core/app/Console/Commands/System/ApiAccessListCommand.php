<?php

namespace App\Console\Commands\System;

use App\Lib\Project\ApiAccess;
use Illuminate\Console\Command;

/**
 * Which projects may use the Engine API, and with what. The tokens themselves
 * are never printed: only the ids, so an operator can revoke them.
 */
class ApiAccessListCommand extends Command
{
    protected $signature = 'system:api-access:list';

    protected $description = 'List the projects allowed to use the Engine API and their scope';

    public function handle(): int
    {
        $entries = (new ApiAccess)->all();
        if ($entries === []) {
            $this->line('No project is allowed to use the Engine API.');

            return 0;
        }

        $rows = [];
        $warnings = [];
        foreach ($entries as $entry) {
            $pending = $entry['pending_revoke_token_id'] ?? null;
            $rows[] = [
                'project' => (string) $entry['project'],
                'scope' => ApiAccess::scopeLabel($entry['scope'] ?? ApiAccess::SCOPE_SELF),
                'mcp' => ($entry['mcp'] ?? false) ? 'yes' : 'no',
                'allowed_at' => (string) ($entry['allowed_at'] ?? '-'),
                'token_id' => (string) ($entry['token_id'] ?? '-'),
                'previous' => $pending === null ? '-' : (string) $pending,
            ];

            if ($pending !== null) {
                $warnings[] = sprintf(
                    'Token %s of %s: %s. It is revoked by the next successful apply or rotation of that project.',
                    (string) $pending,
                    (string) $entry['project'],
                    (string) ($entry['warning'] ?? ApiAccess::pendingWarning(false))
                );
            }
        }

        $this->table(['Project', 'Scope', 'MCP', 'Allowed at', 'Token id', 'Previous token'], $rows);

        foreach ($warnings as $warning) {
            $this->line($warning);
        }

        return 0;
    }
}
