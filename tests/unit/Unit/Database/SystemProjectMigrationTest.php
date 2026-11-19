<?php

namespace Tests\Unit\Database;

use App\Lib\Project\ApiAccess;
use App\Lib\Project\PublicRouting\PublicRoutingGrants;
use App\Models\Setting;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** A protected project with an API token for every project becomes a system project; no other one does. */
class SystemProjectMigrationTest extends TestCase
{
    use InMemoryDatabase;

    private const MIGRATION = 'database/migrations/2026_11_18_000000_mark_full_privilege_projects_system.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_only_a_protected_project_with_a_token_for_every_project_becomes_system(): void
    {
        $panel = $this->project('panel', protected: true);
        $withoutHostDomain = $this->project('nohost', protected: true);
        $unprotected = $this->project('unprotected', protected: false);
        $selfScoped = $this->project('selfscoped', protected: true);
        $listScoped = $this->project('listscoped', protected: true);
        $this->allowApi(['panel' => 'all', 'nohost' => 'all', 'unprotected' => 'all', 'selfscoped' => 'self', 'listscoped' => ['panel']]);
        $this->allowRouting(['panel' => [PublicRoutingGrants::HOST_DOMAIN, PublicRoutingGrants::PORTS]]);

        $this->migration()->up();

        $this->assertTrue($panel->refresh()->isSystem());
        $this->assertSame('on', $panel->getDetails()['protected']);
        $this->assertTrue($withoutHostDomain->refresh()->isSystem());
        $this->assertFalse($unprotected->refresh()->isSystem());
        $this->assertFalse($selfScoped->refresh()->isSystem());
        $this->assertFalse($listScoped->refresh()->isSystem());
    }

    public function test_an_engine_without_api_access_changes_nothing(): void
    {
        $user = $this->project('lonely', protected: true);

        $this->migration()->up();

        $this->assertFalse($user->refresh()->isSystem());
    }

    private function project(string $username, bool $protected): User
    {
        return $this->makeUser($username, $protected ? [User::DETAIL_PROTECTED => User::PROTECTED_ON] : []);
    }

    /** @param array<string, string|list<string>> $scopes */
    private function allowApi(array $scopes): void
    {
        $entries = [];
        foreach ($scopes as $project => $scope) {
            $entries[] = ['project' => $project, 'scope' => $scope, 'mcp' => false, 'token_id' => count($entries) + 1];
        }
        Setting::set(ApiAccess::SETTING, json_encode($entries));
    }

    /** @param array<string, list<string>> $grants */
    private function allowRouting(array $grants): void
    {
        Setting::set(PublicRoutingGrants::SETTING, json_encode($grants));
    }

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }
}
