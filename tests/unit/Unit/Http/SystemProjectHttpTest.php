<?php

namespace Tests\Unit\Http;

use App\Auth\TokenAbilities;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnsureProjectNotProtected;
use App\Lib\Project\ApiAccess;
use App\Mcp\Tools\ProjectListSummaryTool;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Request as McpRequest;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The `system` project setting over HTTP: every token but the project's own
 * finds the project missing, no token lists it, and it is protected.
 */
class SystemProjectHttpTest extends TestCase
{
    use InMemoryDatabase;

    private const OWN_TOKEN_ID = 944401;

    private const OTHER_TOKEN_ID = 944402;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();
    }

    public function test_no_token_lists_a_system_project(): void
    {
        $system = $this->systemProject();
        $ordinary = $this->project();

        foreach ([self::OTHER_TOKEN_ID, self::OWN_TOKEN_ID] as $tokenId) {
            $this->actingAsToken($tokenId);

            foreach (['/api/projects', '/api/projects/all'] as $uri) {
                $names = array_column($this->getJson($uri)->assertStatus(200)->json('data'), 'username');
                $this->assertContains($ordinary->username, $names, $uri);
                $this->assertNotContains($system->username, $names, $uri);
            }
        }
    }

    public function test_the_mcp_summary_tool_does_not_list_a_system_project(): void
    {
        $system = $this->systemProject();
        $ordinary = $this->project();
        $this->actingAsToken(self::OTHER_TOKEN_ID);

        $payload = json_decode((string) (new ProjectListSummaryTool())->handle(new McpRequest())->content(), true);

        $names = array_column($payload['projects'], 'name');
        $this->assertContains($ordinary->username, $names);
        $this->assertNotContains($system->username, $names);
    }

    public function test_another_token_finds_a_system_project_missing(): void
    {
        $system = $this->systemProject();
        $this->actingAsToken(self::OTHER_TOKEN_ID);

        $missing = $this->getJson('/api/projects/no-such-project')->assertStatus(404)->getContent();

        $this->assertSame($missing, $this->getJson("/api/projects/{$system->username}")->assertStatus(404)->getContent());
    }

    public function test_another_token_cannot_delete_a_system_project(): void
    {
        $system = $this->systemProject();
        $this->actingAsToken(self::OTHER_TOKEN_ID);

        $this->deleteJson("/api/projects/{$system->username}")->assertStatus(404);

        Queue::assertNothingPushed();
        $this->assertNotNull(User::findByUsername($system->username));
    }

    public function test_a_body_field_naming_a_system_project_finds_it_missing(): void
    {
        $system = $this->systemProject();
        $source = $this->project();
        $this->actingAsToken(self::OTHER_TOKEN_ID);

        $missing = $this->postJson("/api/projects/{$source->username}/push", ['target' => 'no-such-project'])->getContent();

        $this->assertSame(
            $missing,
            $this->postJson("/api/projects/{$source->username}/push", ['target' => $system->username])->assertStatus(404)->getContent()
        );

        Queue::assertNothingPushed();
    }

    public function test_a_domain_of_a_system_project_is_missing_to_another_token(): void
    {
        $system = $this->systemProject();
        $this->makeMainDomain($system, 'panel.example.test');
        $this->actingAsToken(self::OTHER_TOKEN_ID);

        $missing = $this->getJson('/api/domains/no-such.example.test')->assertStatus(404)->getContent();

        $this->assertSame($missing, $this->getJson('/api/domains/panel.example.test')->assertStatus(404)->getContent());
    }

    public function test_a_task_of_a_system_project_is_missing_and_unlisted_to_another_token(): void
    {
        $system = $this->systemProject();
        $task = Task::start(jobType: 'test', queue: 'default', username: $system->username);
        $this->actingAsToken(self::OTHER_TOKEN_ID);

        $missing = $this->getJson('/api/tasks/99999999')->assertStatus(404)->getContent();

        $this->assertSame($missing, $this->getJson("/api/tasks/{$task->id}")->assertStatus(404)->getContent());
        $this->assertNotContains(
            $task->id,
            array_column($this->getJson('/api/tasks?scope=all')->assertStatus(200)->json('data'), 'id')
        );
        $this->assertSame([], $this->getJson("/api/tasks?scope=all&username={$system->username}")->assertStatus(200)->json('data'));
    }

    public function test_another_token_cannot_change_the_api_access_of_a_system_project(): void
    {
        $system = $this->systemProject();
        $this->actingAsToken(self::OTHER_TOKEN_ID);

        $this->deleteJson("/api/system/api-access/{$system->username}")->assertStatus(404);
        $this->assertNotContains(
            $system->username,
            array_column($this->getJson('/api/system/api-access')->assertStatus(200)->json('data'), 'project')
        );
    }

    public function test_its_own_token_reaches_it_and_reads_it_as_system(): void
    {
        $system = $this->systemProject();
        $this->actingAsToken(self::OWN_TOKEN_ID);

        $this->getJson("/api/projects/{$system->username}")
            ->assertStatus(200)
            ->assertJsonPath('data.system', true)
            ->assertJsonPath('data.protected', true);
    }

    public function test_its_own_token_is_still_refused_a_delete(): void
    {
        $system = $this->systemProject();
        $this->actingAsToken(self::OWN_TOKEN_ID);

        $message = $this->deleteJson("/api/projects/{$system->username}")
            ->assertStatus(409)
            ->assertJsonPath('code', EnsureProjectNotProtected::CODE)
            ->json('message');

        $this->assertStringContainsString('make it an ordinary project first', $message);

        $this->assertNotNull(User::findByUsername($system->username));
    }

    public function test_the_calling_token_names_the_project_its_api_access_belongs_to(): void
    {
        $system = $this->systemProject();

        $this->actingAsToken(self::OWN_TOKEN_ID);
        $this->getJson('/api/tokens/current')
            ->assertStatus(200)
            ->assertJsonPath('data.token_id', self::OWN_TOKEN_ID)
            ->assertJsonPath('data.project', $system->username)
            ->assertJsonPath('data.system', true);

        $this->actingAsToken(self::OTHER_TOKEN_ID);
        $this->getJson('/api/tokens/current')
            ->assertStatus(200)
            ->assertJsonPath('data.project', null)
            ->assertJsonPath('data.system', false);
    }

    public function test_the_setting_is_read_only_over_the_api(): void
    {
        $system = $this->systemProject();
        $this->actingAsToken(self::OWN_TOKEN_ID);

        $this->deleteJson("/api/projects/{$system->username}/settings/system")->assertStatus(403);

        $this->assertTrue($system->refresh()->isSystem());
    }

    public function test_the_console_sets_and_clears_the_setting(): void
    {
        $user = $this->project();

        $this->artisan('project:settings:set', [
            'key' => 'system',
            'value' => 'on',
            '--project' => $user->username,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertTrue($user->refresh()->isSystem());
        $this->assertTrue($user->isProtected());

        $this->artisan('project:settings:unset', [
            'key' => 'system',
            '--project' => $user->username,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertFalse($user->refresh()->isSystem());
        $this->assertFalse($user->isProtected());
    }

    public function test_protection_cannot_be_unset_while_the_project_is_system(): void
    {
        $system = $this->systemProject();

        $this->artisan('project:settings:unset', [
            'key' => 'protected',
            '--project' => $system->username,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertTrue($system->refresh()->isProtected());
    }

    private function systemProject(): User
    {
        $user = $this->project();
        $user->setDetails([User::DETAIL_SYSTEM => User::SYSTEM_ON]);
        $user->save();
        Setting::set(ApiAccess::SETTING, json_encode([
            ['project' => $user->username, 'scope' => ApiAccess::SCOPE_ALL, 'mcp' => false, 'token_id' => self::OWN_TOKEN_ID],
        ]));

        return $user;
    }

    private function project(): User
    {
        return $this->makeUser('sp' . bin2hex(random_bytes(4)), ['template' => 'dind']);
    }

    private function actingAsToken(int $id): void
    {
        $token = new PersonalAccessToken();
        $token->forceFill([
            'id' => $id,
            'tokenable_type' => Admin::class,
            'tokenable_id' => 1,
            'name' => 'api',
            'token' => hash('sha256', "system-project-{$id}"),
            'abilities' => TokenAbilities::build(api: true, mcp: true),
        ]);

        $admin = new Admin(['name' => 'root']);
        $admin->id = 1;
        $admin->withAccessToken($token);

        Auth::guard('api')->setUser($admin);
        Auth::setUser($admin);
        request()->setUserResolver(fn () => $admin);
    }
}
