<?php

namespace Tests\Unit\Console;

use App\Lib\Project\ApiAccess;
use App\Models\Setting;
use App\System;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingFirewall;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/**
 * The four `system:api-access` commands answer in plain lines, exit 1 on a
 * refusal and never print a token: the listing shows the ids, and says when one
 * of them is still valid while the apply that replaces it is outstanding.
 */
class ApiAccessCommandsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->app->instance(System::class, new RecordingSystem());
        Queue::fake();
        $this->makeUser('alice', ['template' => 'dind', 'UID' => 1000, 'GID' => 1000]);
        $this->makeUser('classic1');
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    private function allow(string $username): string
    {
        $access = new ApiAccess(firewall: new RecordingFirewall());
        $access->allow($this->user($username), ApiAccess::SCOPE_SELF, false);

        return (string) $access->entryFor($this->user($username))['token_id'];
    }

    private function user(string $username): \App\Models\User
    {
        $user = \App\Models\User::findByUsername($username);
        $this->assertNotNull($user);

        return $user;
    }

    private function runCommand(string $command, array $parameters = []): string
    {
        Artisan::call($command, $parameters);

        return Artisan::output();
    }

    public function test_the_listing_prints_the_project_its_scope_and_its_token_ids(): void
    {
        $token = $this->allow('alice');

        $output = $this->runCommand('system:api-access:list');

        $this->assertStringContainsString('alice', $output);
        $this->assertStringContainsString('self', $output);
        $this->assertStringContainsString($token, $output);
        $this->assertDoesNotMatchRegularExpression(
            '/\d+\|[A-Za-z0-9]{20,}/',
            $output,
            'no token value is ever printed'
        );
    }

    public function test_the_listing_says_when_a_token_is_still_valid_because_its_apply_failed(): void
    {
        $token = $this->allow('alice');
        (new ApiAccess())->confirmRevocation('alice', null);
        Setting::set(ApiAccess::SETTING, (string) json_encode([[
            'project' => 'alice',
            'scope' => ApiAccess::SCOPE_ALL,
            'mcp' => false,
            'token_id' => (int) $token + 1,
            'token' => 'stored',
            'allowed_at' => now()->toIso8601String(),
            'pending_revoke_token_id' => (int) $token,
        ]]));

        $output = $this->runCommand('system:api-access:list');

        $this->assertStringContainsString('still valid until the apply that replaces it finishes', $output);
        $this->assertStringNotContainsString('failed', $output, 'nothing has failed yet');
    }

    public function test_the_listing_says_when_the_apply_that_replaces_a_token_failed(): void
    {
        $token = $this->allow('alice');
        $access = new ApiAccess();
        $access->allow($this->user('alice'), ApiAccess::SCOPE_ALL, false);
        $access->markPendingFailure('alice');

        $output = $this->runCommand('system:api-access:list');

        $this->assertStringContainsString((string) $token, $output);
        $this->assertStringContainsString('the apply that replaces it failed', $output);
    }

    public function test_an_empty_listing_says_so(): void
    {
        $this->assertStringContainsString(
            'No project is allowed to use the Engine API.',
            $this->runCommand('system:api-access:list')
        );
    }

    public function test_allowing_a_classic_project_fails_with_a_plain_line(): void
    {
        $code = Artisan::call('system:api-access:allow', ['project' => 'classic1']);
        $output = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('PHP hosting project', $output);
        $this->assertStringNotContainsString('422', $output, 'a CLI refusal carries no status code');
        $this->assertStringNotContainsString('api_access_container_only', $output, 'nor a problem code');
        $this->assertNull((new ApiAccess())->entryFor($this->user('classic1')));
    }

    public function test_allowing_an_unknown_project_fails(): void
    {
        $this->assertSame(1, Artisan::call('system:api-access:allow', ['project' => 'nobody']));
        $this->assertStringContainsString('There is no project', Artisan::output());
    }

    public function test_allowing_the_same_scope_twice_says_nothing_changed(): void
    {
        $this->assertSame(0, Artisan::call('system:api-access:allow', ['project' => 'alice']));
        $first = Artisan::output();

        $this->assertSame(0, Artisan::call('system:api-access:allow', ['project' => 'alice']));

        $this->assertStringContainsString('Allowed alice', $first);
        $this->assertStringContainsString('nothing changed', Artisan::output());
    }
}
