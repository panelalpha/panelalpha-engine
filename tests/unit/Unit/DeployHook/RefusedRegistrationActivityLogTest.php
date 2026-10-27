<?php

namespace Tests\Unit\DeployHook;

use App\Http\Middleware\McpActivityLogMiddleware;
use App\Mcp\Tools\Api\Git\GitDeployHookCreateTool;
use App\Models\DeployHook;
use App\Models\McpActivityLog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;

/**
 * A refused registration answers the MCP caller with the hook it created, secret included,
 * as the tool's error text. The activity log stores that text, and GET /api/mcp-activity-logs
 * serves it, so the secret must not be stored with it.
 */
class RefusedRegistrationActivityLogTest extends DeployHookTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamps();
        });
        require_once database_path('migrations/2026_06_24_100001_create_mcp_activity_logs_table.php');
        (new \CreateMcpActivityLogsTable)->up();
        require_once database_path('migrations/2026_10_10_000000_add_project_to_mcp_activity_logs_table.php');
        (new \AddProjectToMcpActivityLogsTable)->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('mcp_activity_logs');
        Schema::dropIfExists('personal_access_tokens');
        parent::tearDown();
    }

    public function test_the_secret_a_refused_registration_shows_the_caller_is_not_logged(): void
    {
        Http::fake();
        $this->user('main', ['git_token' => null]);
        $arguments = ['name' => 'alice', 'register' => true];

        $result = (new GitDeployHookCreateTool)->handle(new Request($arguments));
        $text = (string) $result->content();
        $secret = (string) DeployHook::firstOrFail()->secret();

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('provider_token_missing', $text);
        $this->assertStringContainsString($secret, $text, 'the caller is shown the secret this once');

        $this->logThroughTheMiddleware('git_deploy_hook_create', $arguments, $text);

        $row = McpActivityLog::sole();
        $this->assertSame('error', $row->status);
        $this->assertStringNotContainsString($secret, (string) $row->error_message);
        $this->assertStringContainsString('provider_token_missing', (string) $row->error_message);
        $this->assertSame(McpActivityLog::REDACTED, json_decode((string) $row->error_message, true)['data']['problems'][0]['hook']['secret']);
    }

    /** @param array<string, mixed> $arguments */
    private function logThroughTheMiddleware(string $tool, array $arguments, string $errorText): void
    {
        $request = HttpRequest::create('/mcp', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]));
        $reply = new JsonResponse(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
            'content' => [['type' => 'text', 'text' => $errorText]],
            'isError' => true,
        ]]);

        (new McpActivityLogMiddleware)->handle($request, fn () => $reply);
    }
}
