<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\McpActivityLogMiddleware;
use App\Models\McpActivityLog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * execute_tools runs catalogue tools inside one tools/call, and answers as a
 * stream that only runs once the body is sent. The log must still name the
 * tools that ran, with their own outcomes, and be written after they did.
 */
class McpActivityLogMiddlewareTest extends TestCase
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

        // mcp_activity_logs.token_id references it.
        Schema::connection('sqlite')->create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
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

    /** @param array<string, mixed> $arguments */
    private function request(string $tool, array $arguments): Request
    {
        return Request::create('/mcp', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]));
    }

    public function test_a_direct_call_is_logged_as_itself(): void
    {
        $reply = new JsonResponse(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
            'content' => [['type' => 'text', 'text' => 'boom']],
            'isError' => true,
        ]]);

        (new McpActivityLogMiddleware())->handle($this->request('project_get', ['name' => 'shop']), fn () => $reply);

        $row = McpActivityLog::sole();
        $this->assertSame('project_get', $row->tool_name);
        $this->assertSame('error', $row->status);
        $this->assertSame('boom', $row->error_message);
    }

    public function test_a_failed_direct_call_answered_as_a_plain_response_is_logged_as_an_error(): void
    {
        // What laravel/mcp's HttpTransport returns for a single reply: HTTP 200,
        // an Illuminate Response rather than a JsonResponse, isError in the body.
        $reply = response(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
            'content' => [['type' => 'text', 'text' => '{"status":422,"data":{"message":"The name field is required."}}']],
            'isError' => true,
        ]]), 200, ['Content-Type' => 'application/json']);

        (new McpActivityLogMiddleware())->handle($this->request('file_write', ['path' => 'a.txt']), fn () => $reply);

        $row = McpActivityLog::sole();
        $this->assertSame('file_write', $row->tool_name);
        $this->assertSame('error', $row->status);
        $this->assertStringContainsString('422', (string) $row->error_message);
    }

    public function test_execute_tools_is_logged_as_the_tools_it_ran_once_the_stream_is_sent(): void
    {
        $summary = json_encode(['ok' => false, 'results' => [
            ['name' => 'domain_list', 'content' => [['type' => 'text', 'text' => '[]']], 'isError' => false],
            ['name' => 'project_delete', 'content' => [['type' => 'text', 'text' => 'Tool [project_delete] was not found in the catalog.']], 'isError' => true],
        ]]);
        $stream = new StreamedResponse(function () use ($summary): void {
            echo 'data: ' . json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => []]) . "\n\n";
            echo 'data: ' . json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
                'content' => [['type' => 'text', 'text' => $summary]],
                'isError' => true,
            ]]) . "\n\n";
        });

        $request = $this->request('execute_tools', ['calls' => [
            ['name' => 'domain_list', 'arguments' => ['name' => 'shop']],
            ['name' => 'project_delete', 'arguments' => ['name' => 'shop']],
            ['name' => 'project_suspend', 'arguments' => ['name' => 'shop']],
        ]]);

        $response = (new McpActivityLogMiddleware())->handle($request, fn () => $stream);

        $this->assertSame(0, McpActivityLog::count(), 'nothing has run before the body is sent');

        ob_start();
        $response->sendContent();
        $body = ob_get_clean();

        $this->assertStringContainsString('project_delete', $body, 'the stream still reaches the client');

        $rows = McpActivityLog::orderBy('id')->get();
        $this->assertSame(['domain_list', 'project_delete'], $rows->pluck('tool_name')->all());
        $this->assertSame(['success', 'error'], $rows->pluck('status')->all());
        $this->assertSame(['name' => 'shop'], $rows[1]->input);
    }

    public function test_execute_tools_that_ran_nothing_is_logged_as_itself(): void
    {
        $stream = new StreamedResponse(function (): void {
            echo 'data: ' . json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
                'content' => [['type' => 'text', 'text' => '{"ok":false,"error":{"kind":"OutputLimitExceeded"}}']],
                'isError' => true,
            ]]) . "\n\n";
        });

        $response = (new McpActivityLogMiddleware())->handle(
            $this->request('execute_tools', ['calls' => [['name' => 'file_download']]]),
            fn () => $stream
        );

        ob_start();
        $response->sendContent();
        ob_end_clean();

        $row = McpActivityLog::sole();
        $this->assertSame('execute_tools', $row->tool_name);
        $this->assertSame('error', $row->status);
    }
}
