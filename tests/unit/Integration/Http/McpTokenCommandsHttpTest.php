<?php

namespace Tests\Integration\Http;

use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The panel reads which MCP tools there are before it limits a token to some of them.
 */
class McpTokenCommandsHttpTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware();
    }

    public function test_the_catalogue_describes_every_served_tool(): void
    {
        $data = $this->getJson('/api/mcp-tokens/commands')->assertStatus(200)->json('data');

        $byName = array_column($data, null, 'name');

        $this->assertSame(
            ['name' => 'project_get', 'toolset' => 'projects', 'access' => 'read', 'deletes' => false, 'project_scoped' => true],
            $byName['project_get'],
        );
        $this->assertSame('write', $byName['project_create']['access']);
        $this->assertTrue($byName['project_delete']['deletes']);
        $this->assertSame('secret', $byName['app_credentials_get']['access']);
        $this->assertFalse($byName['project_suspend']['project_scoped']);

        $names = array_column($data, 'name');
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
        $this->assertNotContains('mcp_token_list', $names);
    }

    public function test_a_listed_name_can_be_given_to_a_token(): void
    {
        $names = array_column($this->getJson('/api/mcp-tokens/commands')->json('data'), 'name');

        $this->postJson('/api/mcp-tokens', ['name' => 'claude', 'commands' => array_slice($names, 0, 2)])
            ->assertStatus(201)
            ->assertJsonPath('data.commands', array_slice($names, 0, 2));
    }
}
