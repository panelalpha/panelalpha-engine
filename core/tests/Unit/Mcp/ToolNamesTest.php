<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Tools\Api\ApiTool;
use Tests\TestCase;

/**
 * Tool names are what a client addresses; renaming one silently breaks saved
 * prompts and allow-lists. These guard the map in app/Mcp/tool-names.php: that
 * it still matches the API surface, that the names the generator actually wrote
 * are the names in the map, and that the convention holds.
 */
class ToolNamesTest extends TestCase
{
    /** @return array<string, string> */
    private function map(): array
    {
        return require base_path('app/Mcp/tool-names.php');
    }

    /** @return array<int, class-string<ApiTool>> */
    private function generated(): array
    {
        return require base_path('app/Mcp/Tools/Api/generated-tools.php');
    }

    public function test_every_name_is_unique(): void
    {
        $map = $this->map();

        $this->assertSame(
            count($map),
            count(array_unique($map)),
            'Two operations share a tool name: ' . implode(', ', array_keys(array_filter(
                array_count_values($map),
                fn (int $n): bool => $n > 1
            )))
        );
    }

    public function test_the_map_covers_the_documented_api_exactly(): void
    {
        $path = base_path('storage/api-docs/api-docs.json');

        // The OpenAPI document is generated, not committed, so on a fresh
        // checkout there is nothing to compare against. Skipping says that,
        // rather than failing as though the map were wrong.
        if (!is_file($path)) {
            $this->markTestSkipped('No OpenAPI document. Run: php artisan l5-swagger:generate');
        }

        $spec = json_decode((string)file_get_contents($path), true);

        $this->assertIsArray($spec['paths'] ?? null, 'The OpenAPI document could not be parsed.');

        $operations = [];
        foreach ($spec['paths'] as $path => $item) {
            foreach ((array)$item as $verb => $op) {
                // Hidden from MCP on purpose: no tool, so no name.
                if (($op['x-mcp-hide'] ?? false) === true) {
                    continue;
                }
                if (in_array(strtoupper($verb), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    $operations[] = strtoupper($verb) . ' ' . $path;
                }
            }
        }

        $map = $this->map();

        $this->assertSame(
            [],
            array_values(array_diff($operations, array_keys($map))),
            'API operations with no tool name; add them to app/Mcp/tool-names.php'
        );

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($map), $operations)),
            'Tool names for operations that no longer exist; removing one removes a tool clients may call'
        );
    }

    public function test_generated_tools_carry_the_name_the_map_gives_them(): void
    {
        $wanted = array_values($this->map());
        $actual = array_map(fn (string $c): string => (new $c())->name(), $this->generated());

        sort($wanted);
        sort($actual);

        $this->assertSame($wanted, $actual, 'Stale generated tools. Run: php artisan mcp:tool:generate');
    }

    /**
     * The point of the map: a name says what it acts on and what it does, and
     * leaves out the parameters the caller passes anyway. `get_users_by_
     * username_mysql_databases_by_dbname` is what this rules out.
     */
    public function test_names_follow_the_resource_action_convention(): void
    {
        foreach ($this->map() as $operation => $name) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9]*(_[a-z0-9]+)+$/',
                $name,
                "{$operation} => {$name} is not lower snake_case with an action"
            );

            $this->assertDoesNotMatchRegularExpression(
                '/(^|_)by_/',
                $name,
                "{$operation} => {$name} names a parameter; the convention is <resource>_<action>"
            );

            $this->assertLessThanOrEqual(
                4,
                substr_count($name, '_') + 1,
                "{$operation} => {$name} is more than four words; shorten the resource or the action"
            );
        }
    }

    public function test_no_name_still_calls_a_project_a_user(): void
    {
        foreach ($this->map() as $operation => $name) {
            // mysql_user_* and app_user_* are genuinely other things: MySQL
            // accounts, and accounts inside the deployed application.
            if (str_starts_with($name, 'mysql_user') || str_starts_with($name, 'app_user')) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/(^|_)users?(_|$)/',
                $name,
                "{$operation} => {$name} still calls a project a user"
            );
        }
    }

    public function test_the_project_resource_is_named_consistently(): void
    {
        $map = $this->map();

        foreach (['project_list', 'project_get', 'project_create', 'project_update',
                  'project_delete', 'project_suspend', 'project_unsuspend',
                  'project_clone', 'project_verify_name'] as $expected) {
            $this->assertContains($expected, $map, "{$expected} is missing from the tool name map");
        }
    }
}
