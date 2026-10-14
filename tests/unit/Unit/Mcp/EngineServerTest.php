<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Servers\EngineServer;
use App\Mcp\ToolPolicy;
use App\Mcp\Tools\MetricsLatestTool;
use App\Mcp\Tools\ProjectListSummaryTool;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;
use Tests\TestCase;

/**
 * The MCP server is served by laravel/mcp. These cover the wiring that the
 * hand-rolled McpController used to own: which tools exist, and under exactly
 * which names, since renaming one silently breaks every configured client.
 */
class EngineServerTest extends TestCase
{
    /** @return array<int, Tool> */
    private function tools(): array
    {
        $server = new ReflectionClass(EngineServer::class);

        return array_map(
            fn (string $class): Tool => new $class(),
            $server->getProperty('tools')->getDefaultValue()
        );
    }

    public function test_the_server_registers_exactly_the_read_only_tools(): void
    {
        $this->assertEqualsCanonicalizing(
            [MetricsLatestTool::class, ProjectListSummaryTool::class],
            (new ReflectionClass(EngineServer::class))->getProperty('tools')->getDefaultValue()
        );
    }

    public function test_tool_names_match_the_ones_clients_are_configured_with(): void
    {
        $this->assertSame(
            ['metrics_latest', 'project_list_summary'],
            array_map(fn (Tool $tool): string => $tool->name(), $this->tools())
        );
    }

    public function test_every_tool_advertises_itself_as_read_only(): void
    {
        foreach ($this->tools() as $tool) {
            $annotations = $tool->annotations();

            $this->assertTrue(
                $annotations['readOnlyHint'] ?? false,
                $tool->name() . ' must be annotated read-only'
            );
        }
    }

    public function test_every_tool_carries_a_description_for_the_model(): void
    {
        foreach ($this->tools() as $tool) {
            $this->assertNotSame('', trim($tool->description()));
        }
    }

    /**
     * call_engine_api proxied arbitrary authenticated /api/ calls with the
     * caller's bearer token, over a connection with TLS verification disabled
     * and a prefix check that no dot segment had to survive. It was dropped
     * deliberately; nothing should reintroduce it.
     */
    public function test_the_arbitrary_api_proxy_tool_is_gone(): void
    {
        $this->assertFalse(class_exists(\App\Lib\Mcp\Tools\CallEngineApiTool::class));

        foreach ($this->tools() as $tool) {
            $this->assertNotSame('call_engine_api', $tool->name());
        }
    }

    public function test_the_hand_rolled_json_rpc_implementation_is_gone(): void
    {
        $this->assertFalse(class_exists(\App\Http\Controllers\McpController::class));
        $this->assertFalse(class_exists(\App\Lib\Mcp\McpToolsRegistry::class));
        $this->assertFalse(class_exists(\App\Lib\Mcp\McpTool::class));
    }

    /**
     * The previous hand-rolled server answered 2024-11-05 to everyone, so that
     * is what every already-configured client opens with. laravel/mcp 1.x drops
     * the initialize handshake entirely; this pins us to a line that still
     * speaks it, and fails loudly if a future bump takes it away.
     */
    public function test_clients_on_every_supported_protocol_version_can_still_handshake(): void
    {
        foreach (['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25'] as $version) {
            $reply = $this->dispatch([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => $version,
                    'capabilities' => [],
                    'clientInfo' => ['name' => 'test', 'version' => '1'],
                ],
            ]);

            $this->assertArrayNotHasKey('error', $reply, "initialize failed for {$version}");
            $this->assertSame($version, $reply['result']['protocolVersion']);
            $this->assertSame('PanelAlpha Engine', $reply['result']['serverInfo']['name']);
        }
    }

    public function test_tools_list_advertises_the_hand_written_summary_tools(): void
    {
        $reply = $this->dispatch(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []]);

        // The generated per-endpoint API tools are listed alongside these and
        // are covered by GeneratedApiToolsTest.
        $names = array_column($reply['result']['tools'], 'name');

        $this->assertContains('metrics_latest', $names);
        $this->assertContains('project_list_summary', $names);
    }

    public function test_tools_list_returns_every_tool_in_one_unpaginated_page(): void
    {
        config(['mcp-tools.tool_search' => false]);

        $reply = $this->dispatch(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list', 'params' => []]);

        $this->assertArrayNotHasKey(
            'nextCursor',
            $reply['result'],
            'tools/list must fit one page: clients that ignore nextCursor lose every tool past it'
        );

        $server = new ReflectionClass(EngineServer::class);
        $registered = $server->getProperty('tools')->getDefaultValue();

        $generated = dirname($server->getFileName()) . '/../Tools/Api/generated-tools.php';

        if (is_file($generated)) {
            $registered = array_merge($registered, require $generated);
        }

        $this->assertCount(
            count((new ToolPolicy())->filter($registered)),
            $reply['result']['tools'],
            'the single page must hold the whole catalogue, not a truncated one'
        );
    }

    public function test_calling_the_dropped_proxy_tool_is_refused(): void
    {
        $reply = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'call_engine_api', 'arguments' => []],
        ]);

        $this->assertArrayHasKey('error', $reply);
        $this->assertStringContainsString('call_engine_api', $reply['error']['message']);
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function dispatch(array $message): array
    {
        $transport = new class implements \Laravel\Mcp\Server\Contracts\Transport
        {
            /** @var array<int, string> */
            public array $sent = [];

            public function onReceive(\Closure $handler): void {}

            public function run() {}

            public function send(string $message, ?string $sessionId = null): void
            {
                $this->sent[] = $message;
            }

            public function sessionId(): ?string
            {
                return 'test-session';
            }

            // execute_tools answers as a stream; the reply is its last message.
            public function stream(\Closure $stream): void
            {
                $stream();
            }
        };

        $server = new EngineServer($transport);
        $server->start();
        $server->handle(json_encode($message, JSON_THROW_ON_ERROR));

        return json_decode(end($transport->sent), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> the JSON a catalogue tool put in its text
     */
    private function callCatalogue(string $tool, array $arguments): array
    {
        $reply = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);

        $this->assertArrayNotHasKey('error', $reply, json_encode($reply));

        return json_decode($reply['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<int, string> */
    private function listedNames(): array
    {
        $reply = $this->dispatch(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list', 'params' => []]);

        return array_column($reply['result']['tools'], 'name');
    }

    public function test_tool_search_lists_only_the_direct_tools_and_the_two_catalogue_tools(): void
    {
        config(['mcp-tools.tool_search' => true]);

        $names = $this->listedNames();

        $this->assertContains('search_tools', $names);
        $this->assertContains('execute_tools', $names);
        $this->assertContains('project_create', $names);
        $this->assertContains('deploy_log_get', $names);
        $this->assertContains('project_list', $names);
        $this->assertContains('project_delete', $names);
        $this->assertNotContains('mysql_database_list', $names);
        $this->assertLessThan(30, count($names), 'the default direct set is meant to be small');
    }

    public function test_search_tools_finds_catalogued_and_direct_tools_alike(): void
    {
        config(['mcp-tools.tool_search' => true]);

        $found = array_column($this->callCatalogue('search_tools', ['query' => 'mysql database'])['tools'], 'name');
        $this->assertContains('mysql_database_list', $found);

        // Direct tools are in the catalogue too, so a search never misses one.
        $found = array_column($this->callCatalogue('search_tools', ['query' => 'project_create'])['tools'], 'name');
        $this->assertSame('project_create', $found[0]);
    }

    public function test_browsing_the_catalogue_pages_through_every_tool(): void
    {
        config(['mcp-tools.tool_search' => true]);

        $first = $this->callCatalogue('search_tools', ['query' => '', 'limit' => 50]);
        $this->assertTrue($first['hasMore']);

        $seen = [];
        $offset = 0;
        for ($page = 0; $page < 100; $page++) {
            $out = $this->callCatalogue('search_tools', ['query' => '', 'limit' => 50, 'offset' => $offset]);
            $this->assertNotSame([], $out['tools']);
            array_push($seen, ...array_column($out['tools'], 'name'));
            if (!$out['hasMore']) {
                $this->assertArrayNotHasKey('nextOffset', $out);
                break;
            }
            $offset = $out['nextOffset'];
        }

        $this->assertSame($seen, array_values(array_unique($seen)), 'no tool is returned twice');
        $this->assertContains('mysql_database_list', $seen);
        $this->assertGreaterThan(100, count($seen), 'the whole catalogue, not the first page');
    }

    public function test_search_results_carry_the_annotations_tools_list_would(): void
    {
        config(['mcp-tools.tool_search' => true]);

        $tools = $this->callCatalogue('search_tools', ['query' => 'project_delete'])['tools'];
        $delete = collect($tools)->firstWhere('name', 'project_delete');

        $this->assertNotNull($delete);
        $this->assertTrue($delete['annotations']['destructiveHint'] ?? false);
    }

    public function test_execute_tools_runs_a_catalogued_tool(): void
    {
        config(['mcp-tools.tool_search' => true]);

        // No `name`: the tool's own validation answers, which proves the call
        // reached it without needing a project to exist.
        $out = $this->callCatalogue('execute_tools', ['calls' => [
            ['name' => 'mysql_database_list', 'arguments' => (object) []],
        ]]);

        $this->assertFalse($out['ok']);
        $this->assertSame('mysql_database_list', $out['results'][0]['name']);
        $this->assertStringContainsString('name', $out['results'][0]['content'][0]['text']);
    }

    public function test_the_catalogue_holds_only_what_the_filters_left(): void
    {
        config(['mcp-tools.tool_search' => true, 'mcp-tools.permission_mode' => ToolPolicy::MODE_READONLY]);

        $found = array_column($this->callCatalogue('search_tools', ['query' => 'project_delete'])['tools'], 'name');
        $this->assertNotContains('project_delete', $found);

        $out = $this->callCatalogue('execute_tools', ['calls' => [
            ['name' => 'project_delete', 'arguments' => ['name' => 'shop']],
        ]]);

        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('not found', $out['results'][0]['content'][0]['text']);
    }

    public function test_a_direct_list_covering_everything_turns_the_catalogue_off(): void
    {
        config(['mcp-tools.tool_search' => true, 'mcp-tools.direct' => '*']);

        $names = $this->listedNames();

        $this->assertNotContains('search_tools', $names);
        $this->assertContains('mysql_database_list', $names);
    }

    /** @return string the instructions an initialize answers with */
    private function instructions(): string
    {
        $reply = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
        ]);

        return (string)$reply['result']['instructions'];
    }

    /** The areas an agent can search, named where it reads them first, from the catalogue itself. */
    public function test_the_instructions_and_search_tools_name_every_catalogue_area(): void
    {
        config(['mcp-tools.tool_search' => true]);

        $this->assertMatchesRegularExpression('/^Tool names in the catalogue begin with: (.+)\.$/m', $this->instructions());
        preg_match('/^Tool names in the catalogue begin with: (.+)\.$/m', $this->instructions(), $m);
        $areas = explode(', ', $m[1]);
        foreach (['app', 'vault', 'ssl', 'git', 'tunnel', 'metrics', 'mysql', 'wp'] as $area) {
            $this->assertContains($area, $areas);
        }

        $reply = $this->dispatch(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list', 'params' => []]);
        $search = collect($reply['result']['tools'])->firstWhere('name', 'search_tools');
        $this->assertStringContainsString('Tool names in the catalogue begin with: ' . $m[1] . '.', $search['description']);
    }

    public function test_an_area_the_token_cannot_reach_is_not_named(): void
    {
        config(['mcp-tools.tool_search' => true, 'mcp-tools.permission_mode' => ToolPolicy::MODE_READONLY]);

        preg_match('/^Tool names in the catalogue begin with: (.+)\.$/m', $this->instructions(), $m);
        // ssh_run is the only ssh tool, and it writes.
        $this->assertNotContains('ssh', explode(', ', $m[1]));
        $this->assertContains('mysql', explode(', ', $m[1]));
    }

    public function test_without_a_catalogue_the_instructions_name_no_areas(): void
    {
        config(['mcp-tools.tool_search' => false]);

        $this->assertStringNotContainsString('Tool names in the catalogue begin with', $this->instructions());
    }
}
