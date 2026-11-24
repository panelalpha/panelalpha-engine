<?php

namespace Tests\Integration\Database;

use App\Lib\Project\ApiAccess;
use App\Models\Admin;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** A system project allowed every project keeps its token, which gains MCP; no other token changes. */
class SystemProjectMcpMigrationTest extends TestCase
{
    use InMemoryDatabase;

    private const MIGRATION = 'database/migrations/2026_11_24_000000_let_system_projects_use_mcp.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_only_the_token_of_a_system_project_allowed_every_project_gains_mcp(): void
    {
        $this->project('panel', system: true);
        $this->project('selfscoped', system: true);
        $this->project('ordinary', system: false);
        $panel = $this->token('panel');
        $selfScoped = $this->token('selfscoped');
        $ordinary = $this->token('ordinary');
        $this->allow([
            ['project' => 'panel', 'scope' => 'all', 'mcp' => false, 'token_id' => $panel->id],
            ['project' => 'selfscoped', 'scope' => 'self', 'mcp' => false, 'token_id' => $selfScoped->id],
            ['project' => 'ordinary', 'scope' => 'all', 'mcp' => false, 'token_id' => $ordinary->id],
        ]);

        $this->migration()->up();

        $this->assertSame(['api', 'mcp'], $panel->refresh()->abilities);
        $this->assertSame(['api'], $selfScoped->refresh()->abilities);
        $this->assertSame(['api'], $ordinary->refresh()->abilities);
        $this->assertSame([true, false, false], array_column($this->entries(), 'mcp'));
        $this->assertSame([$panel->id, $selfScoped->id, $ordinary->id], array_column($this->entries(), 'token_id'));
    }

    public function test_a_token_that_already_holds_mcp_is_left_as_it_is(): void
    {
        $this->project('panel', system: true);
        $panel = $this->token('panel', ['api', 'mcp']);
        $this->allow([['project' => 'panel', 'scope' => 'all', 'mcp' => true, 'token_id' => $panel->id]]);

        $this->migration()->up();

        $this->assertSame(['api', 'mcp'], $panel->refresh()->abilities);
        $this->assertTrue($this->entries()[0]['mcp']);
    }

    public function test_an_engine_without_api_access_changes_nothing(): void
    {
        $this->project('lonely', system: true);

        $this->migration()->up();

        $this->assertSame([], $this->entries());
    }

    private function project(string $username, bool $system): User
    {
        return $this->makeUser($username, $system ? [User::DETAIL_SYSTEM => User::SYSTEM_ON] : []);
    }

    /** @param list<string> $abilities */
    private function token(string $project, array $abilities = ['api']): PersonalAccessToken
    {
        $token = Admin::rootAccount()->createToken('api-access:'.$project, $abilities)->accessToken;
        assert($token instanceof PersonalAccessToken);

        return $token;
    }

    /** @param list<array<string, mixed>> $entries */
    private function allow(array $entries): void
    {
        Setting::set(ApiAccess::SETTING, json_encode($entries));
    }

    /** @return list<array<string, mixed>> */
    private function entries(): array
    {
        $decoded = json_decode((string) DB::table('settings')->where('name', ApiAccess::SETTING)->value('value'), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }
}
