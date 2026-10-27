<?php

namespace Tests\Unit\Mcp;

use App\Models\McpActivityLog;
use Illuminate\Support\Facades\DB;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Rows stored before `dbpass` counted as a credential hold wp_config_create's
 * database password in plain text, and GET /api/mcp-activity-logs serves them.
 * The migration redacts those values and leaves the rest of every row as it was.
 */
class ActivityLogStoredRowsRedactionTest extends TestCase
{
    use InMemoryDatabase;

    private const MIGRATION = 'database/migrations/2026_10_26_000000_redact_credentials_in_stored_mcp_activity_logs.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_stored_database_password_is_redacted_and_nothing_else_changes(): void
    {
        $leaky = $this->storeRaw('wp_config_create', ['name' => 'shop', 'dbname' => 'shop_wp', 'dbuser' => 'shop_u', 'dbpass' => 'hunter2']);
        $clean = $this->storeRaw('ssh_run', ['name' => 'shop', 'command' => '[redacted] (27 bytes)']);
        $empty = $this->storeRaw('project_list', null);

        (require base_path(self::MIGRATION))->up();

        $this->assertSame(
            ['name' => 'shop', 'dbname' => 'shop_wp', 'dbuser' => 'shop_u', 'dbpass' => McpActivityLog::REDACTED],
            McpActivityLog::findOrFail($leaky)->input
        );
        $this->assertSame(['name' => 'shop', 'command' => '[redacted] (27 bytes)'], McpActivityLog::findOrFail($clean)->input);
        $this->assertNull(McpActivityLog::findOrFail($empty)->input);
        $this->assertStringNotContainsString('hunter2', (string) DB::table('mcp_activity_logs')->pluck('input')->implode(''));
    }

    public function test_a_new_row_never_stores_it(): void
    {
        $log = McpActivityLog::create(['token_name' => 'agent', 'tool_name' => 'wp_config_create', 'status' => 'success', 'input' => ['dbpass' => 'hunter2']]);

        $this->assertSame(['dbpass' => McpActivityLog::REDACTED], $log->fresh()->input);
    }

    /** @param array<string, mixed>|null $input as an older engine wrote it, past today's redaction */
    private function storeRaw(string $tool, ?array $input): int
    {
        return DB::table('mcp_activity_logs')->insertGetId([
            'token_name' => 'agent',
            'tool_name' => $tool,
            'status' => 'success',
            'input' => $input === null ? null : json_encode($input),
            'created_at' => now(),
        ]);
    }
}
