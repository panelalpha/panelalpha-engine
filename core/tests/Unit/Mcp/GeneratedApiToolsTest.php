<?php

namespace Tests\Unit\Mcp;

use App\Console\Commands\Mcp\GenerateApiToolsCommand;
use App\Mcp\Tools\Api\ApiTool;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The API tools are generated from the OpenAPI document, so these check the
 * shape of the generation rather than each endpoint: a bad template breaks all
 * 132 at once, and that is what is worth catching.
 */
class GeneratedApiToolsTest extends TestCase
{
    /** @return array<int, class-string<ApiTool>> */
    private function generated(): array
    {
        $file = app_path('Mcp/Tools/Api/generated-tools.php');
        $this->assertFileExists($file, 'Run: php artisan mcp:tool:generate');

        return require $file;
    }

    private function invoke(ApiTool $tool, string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod($tool, $method);
        $m->setAccessible(true);

        return $m->invoke($tool, ...$args);
    }

    public function test_the_registry_is_not_empty_and_every_class_loads(): void
    {
        $classes = $this->generated();
        $this->assertNotEmpty($classes);

        foreach ($classes as $class) {
            $this->assertTrue(class_exists($class), "{$class} does not exist");
            $this->assertTrue(is_subclass_of($class, ApiTool::class), "{$class} is not an ApiTool");
        }
    }

    public function test_tool_names_are_unique_across_the_whole_server(): void
    {
        $names = array_map(fn (string $c): string => (new $c())->name(), $this->generated());
        $names[] = 'metrics_latest';
        $names[] = 'project_list_summary';

        $this->assertSame(
            [],
            array_keys(array_filter(array_count_values($names), fn (int $n): bool => $n > 1)),
            'MCP tool names must be unique'
        );
    }

    /**
     * A name a client cannot address is a tool that does not exist. 64 is the
     * limit the stricter clients enforce.
     */
    public function test_tool_names_stay_addressable(): void
    {
        foreach ($this->generated() as $class) {
            $name = (new $class())->name();
            $this->assertLessThanOrEqual(64, strlen($name), "{$name} is too long");
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $name, "{$name} is not snake_case");
        }
    }

    /**
     * Every {placeholder} in the path must be a declared path parameter, or the
     * tool builds a URI with a literal brace in it and the call 404s.
     */
    public function test_every_path_placeholder_is_a_declared_parameter(): void
    {
        foreach ($this->generated() as $class) {
            $tool = new $class();
            $path = $this->invoke($tool, 'path');
            $declared = $this->invoke($tool, 'pathParams');

            preg_match_all('/\{([^}]+)\}/', $path, $matches);

            $this->assertSame(
                [],
                array_diff($matches[1], $declared),
                "{$tool->name()} has placeholders in {$path} that are not declared path parameters"
            );
        }
    }

    public function test_every_declared_parameter_appears_in_the_schema(): void
    {
        foreach ($this->generated() as $class) {
            $tool = new $class();
            $keys = array_keys((array)($tool->toArray()['inputSchema']['properties'] ?? []));

            foreach ($this->invoke($tool, 'pathParams') as $param) {
                $argument = $this->invoke($tool, 'argument', $param);
                $this->assertContains($argument, $keys, "{$tool->name()} omits path parameter {$param} from its schema");
            }
        }
    }

    /**
     * A project is `name` on every tool. The API takes it as `username` on
     * every route, and the generated tool translates; a tool that still asked
     * for `username` would be the one tool a model had to remember.
     */
    public function test_no_tool_asks_for_a_username(): void
    {
        $projectTools = 0;

        foreach ($this->generated() as $class) {
            $tool = new $class();
            $keys = array_keys((array)($tool->toArray()['inputSchema']['properties'] ?? []));

            foreach (array_keys(GenerateApiToolsCommand::ARGUMENT_NAMES['*']) as $apiName) {
                $this->assertNotContains($apiName, $keys, "{$tool->name()} still exposes `{$apiName}`");
            }

            if (in_array('username', $this->invoke($tool, 'pathParams'), true)) {
                $this->assertContains('name', $keys, "{$tool->name()} addresses a project but has no `name` argument");
                $this->assertSame('username', $this->invoke($tool, 'argumentNames')['name'] ?? null);
                $projectTools++;
            }
        }

        $this->assertGreaterThan(70, $projectTools, 'expected most tools to address a project');
    }

    /**
     * Every renamed argument has to reach the endpoint under the API's own
     * name, and a rename must never land on a name another parameter of the
     * same tool already uses -- mysql_user_rename takes the project as `name`
     * and the new MySQL user name as `new_dbuser`, which the API calls `name`.
     */
    public function test_renamed_arguments_reach_the_api_under_their_own_names(): void
    {
        foreach ($this->generated() as $class) {
            $tool = new $class();
            /** @var array<string, string> $renamed */
            $renamed = $this->invoke($tool, 'argumentNames');
            $declared = [
                ...$this->invoke($tool, 'pathParams'),
                ...$this->invoke($tool, 'queryParams'),
                ...$this->invoke($tool, 'bodyParams'),
            ];

            $this->assertSame(
                array_values($renamed),
                array_unique(array_values($renamed)),
                "{$tool->name()} maps two arguments onto one API parameter"
            );

            foreach ($renamed as $apiName) {
                $this->assertContains($apiName, $declared, "{$tool->name()} renames `{$apiName}`, which it does not declare");
            }

            $exposed = array_map(fn (string $apiName): string => $this->invoke($tool, 'argument', $apiName), $declared);
            $this->assertSame(
                [],
                array_keys(array_filter(array_count_values($exposed), fn (int $n): bool => $n > 1)),
                "{$tool->name()} exposes one argument name for two parameters"
            );

            $input = [];
            foreach ($declared as $i => $apiName) {
                $input[$this->invoke($tool, 'argument', $apiName)] = "v{$i}";
            }
            $expected = [];
            foreach ($declared as $i => $apiName) {
                $expected[$apiName] = "v{$i}";
            }

            $this->assertSame($expected, $this->invoke($tool, 'apiInput', $input), "{$tool->name()} loses an argument in translation");
        }
    }

    /**
     * A project an agent creates is an app, so it gets dind without asking;
     * REST callers keep classic hosting as the default. From `x-mcp-default`.
     */
    public function test_project_create_sends_dind_when_the_template_is_omitted(): void
    {
        $tool = new \App\Mcp\Tools\Api\Projects\ProjectCreateTool();

        $this->assertSame('dind', $this->invoke($tool, 'apiInput', ['name' => 'shop'])['template'] ?? null);
        $this->assertSame(
            'default',
            $this->invoke($tool, 'apiInput', ['name' => 'shop', 'template' => 'default'])['template'] ?? null
        );
        $this->assertStringContainsString(
            'sends dind when it is omitted',
            (string)($tool->toArray()['inputSchema']['properties']['template']['description'] ?? '')
        );
    }

    /**
     * The synchronous create shares provision() with the async one and must
     * default to the same dind template, not classic shared hosting.
     */
    public function test_project_create_sync_sends_dind_when_the_template_is_omitted(): void
    {
        $tool = new \App\Mcp\Tools\Api\Projects\ProjectCreateSyncTool();

        $this->assertSame('dind', $this->invoke($tool, 'apiInput', ['name' => 'shop'])['template'] ?? null);
        $this->assertSame(
            'default',
            $this->invoke($tool, 'apiInput', ['name' => 'shop', 'template' => 'default'])['template'] ?? null
        );
        $this->assertStringContainsString(
            'sends dind when it is omitted',
            (string)($tool->toArray()['inputSchema']['properties']['template']['description'] ?? '')
        );
    }

    /**
     * The embedded screenshot cannot be rendered over MCP's text-only
     * transport and dwarfs the score data, so the MCP tool strips it by
     * default; a REST caller who wants the image keeps getting it.
     */
    public function test_lighthouse_report_create_strips_the_screenshot_when_omitted(): void
    {
        $tool = new \App\Mcp\Tools\Api\Lighthouse\LighthouseReportCreateTool();

        $this->assertSame(
            '1',
            $this->invoke($tool, 'apiInput', ['url' => 'https://example.com'])['strip_screenshot'] ?? null
        );
        $this->assertSame(
            '0',
            $this->invoke($tool, 'apiInput', ['url' => 'https://example.com', 'strip_screenshot' => '0'])['strip_screenshot'] ?? null
        );
        $this->assertStringContainsString(
            'sends 1 when it is omitted',
            (string)($tool->toArray()['inputSchema']['properties']['strip_screenshot']['description'] ?? '')
        );
    }

    public function test_every_per_operation_rename_refers_to_an_operation_that_exists(): void
    {
        $operations = [];
        foreach ($this->generated() as $class) {
            $tool = new $class();
            $operations[] = $this->invoke($tool, 'method') . ' ' . $this->invoke($tool, 'path');
        }

        foreach (array_keys(GenerateApiToolsCommand::ARGUMENT_NAMES) as $operation) {
            if ($operation === '*') {
                continue;
            }
            $this->assertContains($operation, $operations, "ARGUMENT_NAMES renames parameters of {$operation}, which no tool calls");
        }
    }

    /**
     * The risk annotations are what a client uses to decide whether to confirm
     * before running something. A GET marked destructive, or a DELETE that is
     * not, is worse than no annotation at all.
     *
     * The verb decides, with one documented exception: an operation on
     * {@see GenerateApiToolsCommand::READ_ONLY_OPERATIONS} POSTs only because
     * it takes a body, and reads. Those must come out read-only, or a
     * `MCP_PERMISSION_MODE=readonly` client loses a tool that cannot write.
     */
    public function test_risk_annotations_match_the_http_verb(): void
    {
        $readOnlyOperations = GenerateApiToolsCommand::READ_ONLY_OPERATIONS;

        foreach ($this->generated() as $class) {
            $tool = new $class();
            $verb = $this->invoke($tool, 'method');
            $operation = $verb . ' ' . $this->invoke($tool, 'path');
            $annotations = $tool->annotations();

            if ($verb === 'GET' || in_array($operation, $readOnlyOperations, true)) {
                $this->assertTrue($annotations['readOnlyHint'] ?? false, "{$tool->name()} ({$verb}) is not read-only");
                $this->assertNotTrue($annotations['destructiveHint'] ?? false, "{$tool->name()} ({$verb}) is marked destructive");
            } else {
                $this->assertTrue($annotations['destructiveHint'] ?? false, "{$tool->name()} ({$verb}) is not marked destructive");
                $this->assertNotTrue($annotations['readOnlyHint'] ?? false, "{$tool->name()} ({$verb}) is marked read-only");
            }
        }
    }

    public function test_every_tool_describes_itself(): void
    {
        foreach ($this->generated() as $class) {
            $tool = new $class();
            $this->assertNotSame('', trim($tool->description()), "{$tool->name()} has no description");
        }
    }

    public function test_generated_tools_are_registered_with_the_server(): void
    {
        $registered = (new ReflectionClass(\App\Mcp\Servers\EngineServer::class))
            ->getProperty('tools')->getDefaultValue();

        // The generated set is merged in the constructor, so the default list
        // holds only the hand-written pair.
        $this->assertSame(
            [\App\Mcp\Tools\MetricsLatestTool::class, \App\Mcp\Tools\ProjectListSummaryTool::class],
            $registered
        );

        // With every group on, the merge must lose nothing; what the shipped
        // default exposes is ToolPolicy's business and is covered separately.
        // Tool search off: the catalogue regrouping is covered in EngineServerTest.
        config(['mcp-tools.toolsets' => 'all', 'mcp-tools.permission_mode' => 'full', 'mcp-tools.tool_search' => false]);

        $server = new \App\Mcp\Servers\EngineServer(new \Laravel\Mcp\Server\Transport\FakeTransporter());
        $merged = (new ReflectionClass($server))->getProperty('tools');
        $merged->setAccessible(true);

        $this->assertCount(count($this->generated()) + 2, $merged->getValue($server));
    }

    /**
     * Every parameter a tool declares has to survive rules(), because
     * Validator::validate() returns only the keys it has rules for. Without a
     * rule, a query or body parameter is silently dropped and the endpoint is
     * called without it -- which for a write operation means a 422 the model
     * cannot act on. Regression: every tool with a body was broken this way.
     */
    public function test_declared_parameters_survive_validation(): void
    {
        $checked = 0;

        foreach ($this->generated() as $class) {
            /** @var ApiTool $tool */
            $tool = new $class();

            /** @var array<string, string> $rules */
            $rules = $this->invoke($tool, 'rules');

            foreach (['queryParams', 'bodyParams', 'pathParams'] as $group) {
                /** @var array<int, string> $names */
                $names = $this->invoke($tool, $group);

                foreach ($names as $name) {
                    $argument = $this->invoke($tool, 'argument', $name);
                    $this->assertArrayHasKey(
                        $argument,
                        $rules,
                        "{$class}: '{$argument}' has no validation rule, so it is dropped before the API is called"
                    );
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(100, $checked, 'expected to have checked the whole generated surface');
    }
}
