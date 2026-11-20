<?php

namespace Tests\Integration\Console;

use App\Jobs\ApplyEnvVars;
use App\Lib\Project\ApiAccess;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\System;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingFirewall;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/**
 * `system:api-access:rotate` and the scope forms of `allow`: a rotation keeps the scope, queues the apply that hands
 * the new token over, and says the old one stays valid until it has run.
 */
class ApiAccessRotateCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->app->instance(System::class, new RecordingSystem);
        Queue::fake();
        $this->makeUser('alice', ['template' => 'dind', 'UID' => 1000, 'GID' => 1000]);
        $this->makeUser('bob', ['template' => 'dind', 'UID' => 1001, 'GID' => 1001]);
        $this->makeUser('classic1');
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    /** @return array{int, string} */
    private function run_(string $command, array $args): array
    {
        $code = Artisan::call($command, $args);

        return [$code, Artisan::output()];
    }

    private function user(string $username): User
    {
        return User::findByUsername($username) ?? throw new \LogicException("no {$username}");
    }

    public function test_rotating_an_unknown_project_is_refused(): void
    {
        $this->assertSame([1, "There is no project 'nobody' on this engine.\n"], $this->run_('system:api-access:rotate', ['project' => 'nobody']));
        Queue::assertNothingPushed();
    }

    public function test_rotating_a_project_never_allowed_is_refused(): void
    {
        [$code, $out] = $this->run_('system:api-access:rotate', ['project' => 'alice']);

        $this->assertSame(1, $code);
        $this->assertSame("Project 'alice' is not allowed to use the Engine API.\n", $out);
        Queue::assertNothingPushed();
    }

    public function test_rotating_a_classic_project_is_refused(): void
    {
        [$code, $out] = $this->run_('system:api-access:rotate', ['project' => 'classic1']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('PHP hosting project', $out);
    }

    public function test_a_rotation_mints_a_new_token_with_the_same_scope_and_keeps_the_old_one_until_the_apply(): void
    {
        $access = new ApiAccess(firewall: new RecordingFirewall);
        $access->allow($this->user('alice'), ApiAccess::SCOPE_ALL, false);
        $old = (int) $access->entryFor($this->user('alice'))['token_id'];
        $access->confirmDelivery('alice', $access->envFor($this->user('alice')));

        [$code, $out] = $this->run_('system:api-access:rotate', ['project' => 'alice']);

        $entry = (new ApiAccess)->entryFor($this->user('alice'));
        $task = Task::query()->where('username', 'alice')->latest('id')->first();
        $this->assertSame(0, $code, $out);
        $this->assertNotSame($old, (int) $entry['token_id']);
        $this->assertSame(ApiAccess::SCOPE_ALL, $entry['scope']);
        $this->assertNotNull($task);
        $this->assertStringContainsString(
            "Minted a new Engine API token (id {$entry['token_id']}) for alice, scope all, and queued apply task {$task->id} to hand it over.",
            $out
        );
        $this->assertStringContainsString(
            "Token {$old} is replaced once this apply succeeds: previous token still valid until the apply that replaces it finishes.",
            $out
        );
        $this->assertTrue(ApiAccess::hasValidToken('alice'));
        Queue::assertPushed(ApplyEnvVars::class);
    }

    public function test_allow_takes_all_or_a_list_of_projects_as_its_scope(): void
    {
        [$code, $out] = $this->run_('system:api-access:allow', ['project' => 'alice', '--scope' => ' all ']);
        $this->assertSame(0, $code, $out);
        $this->assertSame(ApiAccess::SCOPE_ALL, (new ApiAccess)->entryFor($this->user('alice'))['scope']);

        [$code, $out] = $this->run_('system:api-access:allow', ['project' => 'alice', '--scope' => 'alice, ,bob,', '--mcp' => true]);
        $this->assertSame(0, $code, $out);
        $this->assertSame(['alice', 'bob'], (new ApiAccess)->entryFor($this->user('alice'))['scope']);
        $this->assertStringContainsString('including MCP tools', $out);
    }

    public function test_denying_an_unknown_project_is_refused(): void
    {
        $this->assertSame([1, "There is no project 'nobody' on this engine.\n"], $this->run_('system:api-access:deny', ['project' => 'nobody']));
    }
}
