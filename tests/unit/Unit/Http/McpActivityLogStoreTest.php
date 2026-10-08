<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\McpActivityLogController;
use App\Models\Admin;
use App\Models\McpActivityLog;
use App\Models\PersonalAccessToken;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * POST /api/mcp-activity-logs takes a free-form `token_name` (the panel's own
 * token), so the stored row used to say nothing about who wrote it.
 */
class McpActivityLogStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::connection('sqlite')->create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        require_once database_path('migrations/2026_06_24_100001_create_mcp_activity_logs_table.php');
        (new \CreateMcpActivityLogsTable())->up();
    }

    protected function tearDown(): void
    {
        Schema::connection('sqlite')->dropIfExists('mcp_activity_logs');
        Schema::connection('sqlite')->dropIfExists('personal_access_tokens');
        parent::tearDown();
    }

    public function test_the_row_records_the_token_that_wrote_it(): void
    {
        $writer = PersonalAccessToken::forceCreate([
            'tokenable_type' => Admin::class,
            'tokenable_id' => 1,
            'name' => 'panel',
            'token' => hash('sha256', 'x'),
            'abilities' => ['*'],
        ]);

        $request = Request::create('/api/mcp-activity-logs', 'POST', [
            'token_name' => 'someone-else',
            'token_id' => 999,
            'tool_name' => 'wp_plugin_list',
            'status' => 'success',
            'input' => ['env_vars' => ['APP_KEY' => 'base64:abc']],
        ]);
        $request->setUserResolver(fn () => (new Admin())->withAccessToken($writer));

        $response = (new McpActivityLogController())->store($request);

        $this->assertSame(201, $response->getStatusCode());
        $row = McpActivityLog::firstOrFail();
        $this->assertSame($writer->id, $row->token_id);
        $this->assertSame('someone-else', $row->token_name);
        $this->assertSame(['env_vars' => ['APP_KEY' => McpActivityLog::REDACTED]], $row->input);
    }
}
