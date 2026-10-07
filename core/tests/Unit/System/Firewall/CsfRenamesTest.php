<?php

namespace Tests\Unit\System\Firewall;

use App\Mcp\ToolRegistry;
use App\System\Firewall\CsfRenames;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server\Tool;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** Settings and tokens that named the CSF tools keep meaning the same thing. */
class CsfRenamesTest extends TestCase
{
    use InMemoryDatabase;

    public function test_every_new_name_exists(): void
    {
        $tools = array_map(fn (string $c): string => (new $c())->name(), ToolRegistry::all());
        foreach (CsfRenames::TOOLS as $new) {
            $this->assertContains($new, $tools);
        }

        $names = require dirname(__DIR__, 4) . '/app/Mcp/tool-names.php';
        foreach (CsfRenames::ROUTES as $new) {
            $this->assertArrayHasKey($new, $names, $new);
        }
    }

    public function test_a_denied_list_keeps_denying(): void
    {
        $this->assertSame('project_delete,firewall_disable,firewall_*', CsfRenames::toolList('project_delete,csf_disable,csf_*'));
        $this->assertSame('firewall_reload', CsfRenames::toolList('csf_restart'));
        $this->assertSame('project_delete, system_*', CsfRenames::toolList('project_delete, system_*'));
        $this->assertSame('^(firewall_reload|firewall_disable|modsec_.*)$', CsfRenames::regex('^(csf_restart|csf_disable|modsec_.*)$'));
    }

    public function test_toolsets_rename(): void
    {
        $this->assertSame('engine,firewall,modsecurity', CsfRenames::toolsetList('engine,csf,modsecurity'));
        $this->assertSame('all', CsfRenames::toolsetList('all'));
    }

    public function test_an_ability_with_no_successor_is_kept_not_dropped(): void
    {
        // Dropping it could leave a token with no limits, which means every tool.
        $this->assertSame('mcp:csf_ui_credentials', CsfRenames::ability('mcp:csf_ui_credentials'));
        $this->assertSame('api:GET /csf/ui-credentials', CsfRenames::ability('api:GET /csf/ui-credentials'));
        $this->assertSame('mcp:firewall_status', CsfRenames::ability('mcp:csf_status'));
        $this->assertSame('api:PUT /firewall/rules/{id}', CsfRenames::ability('api:PUT /csf/rules/{type}/{lineMd5}'));
        $this->assertSame('mcp', CsfRenames::ability('mcp'));
        $this->assertSame('*', CsfRenames::ability('*'));
    }

    public function test_the_migration_renames_stored_token_abilities(): void
    {
        $this->bootInMemoryDatabase();
        $insert = fn (string $name, ?array $abilities): int => DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => 'x', 'tokenable_id' => 1, 'name' => $name, 'token' => hash('sha256', $name),
            'abilities' => $abilities === null ? null : json_encode($abilities),
        ]);
        $scoped = $insert('scoped', ['mcp', 'mcp:csf_status', 'mcp:csf_ui_credentials', 'api', 'api:GET /csf/rules']);
        $plain = $insert('plain', ['*']);

        $migration = require dirname(__DIR__, 5) . '/core/database/migrations/2026_10_01_000000_rename_csf_tools_to_firewall.php';
        $migration->up();

        $read = fn (int $id): mixed => json_decode((string) DB::table('personal_access_tokens')->where('id', $id)->value('abilities'), true);
        $this->assertSame(['mcp', 'mcp:firewall_status', 'mcp:csf_ui_credentials', 'api', 'api:GET /firewall/rules'], $read($scoped));
        $this->assertSame(['*'], $read($plain));
    }
}
