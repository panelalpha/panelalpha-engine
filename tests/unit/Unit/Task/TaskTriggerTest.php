<?php

namespace Tests\Unit\Task;

use App\Auth\TokenAbilities;
use App\Lib\Task\TaskTrigger;
use App\Mcp\Tools\Api\ApiTool;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Tests\TestCase;

/** Which surface asked: an MCP tool call, an API token, or the console. */
class TaskTriggerTest extends TestCase
{
    private function actAsApiToken(): void
    {
        $token = new PersonalAccessToken();
        $token->forceFill([
            'id' => 7,
            'tokenable_type' => Admin::class,
            'tokenable_id' => 1,
            'name' => 'trigger-test',
            'token' => hash('sha256', 'trigger-test'),
            'abilities' => TokenAbilities::build(api: true, mcp: true, projects: null),
        ]);
        $admin = new Admin(['name' => 'root']);
        $admin->id = 1;
        $admin->withAccessToken($token);
        request()->setUserResolver(fn () => $admin);
    }

    public function test_the_console_is_the_cli(): void
    {
        $this->assertSame(TaskTrigger::CLI, TaskTrigger::current());
    }

    public function test_a_token_is_the_api(): void
    {
        $this->actAsApiToken();

        $this->assertSame(TaskTrigger::API, TaskTrigger::current());
    }

    public function test_an_mcp_tool_call_is_mcp_even_with_its_token(): void
    {
        $this->actAsApiToken();
        request()->attributes->set(ApiTool::VIA_ATTRIBUTE, true);

        $this->assertSame(TaskTrigger::MCP, TaskTrigger::current());
    }

    public function test_the_via_header_alone_is_not_mcp(): void
    {
        $this->actAsApiToken();
        request()->headers->set(ApiTool::VIA_HEADER, ApiTool::VIA_MCP);

        $this->assertSame(TaskTrigger::API, TaskTrigger::current());
    }
}
