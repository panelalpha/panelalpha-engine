<?php

namespace App\Console\Commands\Api;

use App\Models\McpActivityLog;
use Illuminate\Console\Command;

class McpActivityLogsListCommand extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['mcp-activity-logs:list'];

    protected $signature = 'mcp:log:list
        {--limit=20}
        {--token-id=* : Only calls made with this token id; repeat for several}
        {--token-name= : Only calls whose token name contains this text, ignoring case}
        {--project= : Only calls that named this project}';

    protected $description = 'List recent MCP activity log entries';

    public function handle(): int
    {
        $tokenIds = $this->tokenIdsOption();

        if ($tokenIds === null) {
            $this->error('--token-id takes a positive whole number.');

            return self::FAILURE;
        }

        $rows = McpActivityLog::matching($tokenIds, (string) $this->option('token-name'), (string) $this->option('project'))
            ->orderByDesc('created_at')
            ->limit((int) $this->option('limit'))
            ->get(['id', 'token_id', 'token_name', 'tool_name', 'status', 'error_message', 'created_at'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'token_id' => $r->token_id ?? '-',
                'token' => $r->token_name,
                'tool' => $r->tool_name,
                'status' => $r->status,
                'error' => $r->error_message ? substr($r->error_message, 0, 60) : '-',
                'created_at' => $r->created_at,
            ])
            ->toArray();

        $this->table(
            ['ID', 'Token ID', 'Token', 'Tool', 'Status', 'Error', 'Time'],
            $rows
        );

        return self::SUCCESS;
    }

    /** @return list<int>|null null when any given id is not a positive integer */
    private function tokenIdsOption(): ?array
    {
        $ids = [];

        foreach ((array) $this->option('token-id') as $value) {
            if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1) {
                return null;
            }
            $ids[] = (int) $value;
        }

        return $ids;
    }
}
