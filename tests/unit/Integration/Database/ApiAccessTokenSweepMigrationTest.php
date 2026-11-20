<?php

namespace Tests\Integration\Database;

use App\Models\Admin;
use Illuminate\Support\Facades\DB;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** Rows an earlier engine only revoked, and rows whose project is gone, leave the token table. */
class ApiAccessTokenSweepMigrationTest extends TestCase
{
    use InMemoryDatabase;

    private const MIGRATION = 'database/migrations/2026_11_14_000000_delete_dead_api_access_tokens.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_revoked_token_and_a_token_of_a_gone_project_go_while_every_other_stays(): void
    {
        $this->makeUser('fe120live');
        $this->rootToken('api-access:fe120live', revoked: true);
        $this->rootToken('api-access:fe120gone');
        $live = $this->rootToken('api-access:fe120live');
        $claude = $this->rootToken('claude');
        $hub = $this->rootToken('hub:203-0-113-7');
        $mcp = $this->rootToken('mcp:assistant');
        $foreign = $this->userToken('fe120outsider', 'api-access:fe120gone');

        $this->migration()->up();

        $this->assertSame([$live, $claude, $hub, $mcp, $foreign], $this->tokenIds());
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->makeUser('fe120live');
        $this->rootToken('api-access:fe120gone');
        $live = $this->rootToken('api-access:fe120live');

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame([$live], $this->tokenIds());
    }

    public function test_a_fresh_install_sweeps_nothing(): void
    {
        $this->migration()->up();

        $this->assertSame([], $this->tokenIds());
    }

    private function rootToken(string $name, bool $revoked = false): int
    {
        $token = Admin::rootAccount()->createToken($name, ['api']);
        if ($revoked) {
            $token->accessToken->forceFill(['revoked_at' => now()])->save();
        }

        return (int) $token->accessToken->getKey();
    }

    private function userToken(string $username, string $name): int
    {
        return (int) $this->makeUser($username)->createToken($name, ['api'])->accessToken->getKey();
    }

    /** @return list<int> */
    private function tokenIds(): array
    {
        return DB::table('personal_access_tokens')->orderBy('id')->pluck('id')->map(static fn (int|string $id): int => (int) $id)->all();
    }

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }
}
