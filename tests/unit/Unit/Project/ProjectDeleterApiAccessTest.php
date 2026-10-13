<?php

namespace Tests\Unit\Project;

use App\Jobs\ApplyEnvVars;
use App\Lib\Project\ApiAccess;
use App\Lib\Project\ProjectDeleter;
use App\Models\PersonalAccessToken;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingFirewall;
use Tests\TestCase;

/**
 * A project that goes away takes its Engine API allowance with it: the token is
 * revoked and the firewall stops admitting its address. Nothing else fails when
 * it was never allowed.
 */
class ProjectDeleterApiAccessTest extends TestCase
{
    use InMemoryDatabase;

    private string $name;

    private RecordingFirewall $firewall;

    private ApiAccess $access;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        Queue::fake();
        $this->name = 'delapi' . bin2hex(random_bytes(3));
        $this->firewall = new RecordingFirewall();
        $this->access = new ApiAccess(firewall: $this->firewall);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_deleting_a_project_revokes_its_token_and_its_rule(): void
    {
        $user = $this->makeUser($this->name, ['template' => 'dind']);
        $entry = $this->access->allow($user, ApiAccess::SCOPE_SELF, false);
        Queue::fake();

        $this->deleter()->delete($user);

        $this->assertNotNull(PersonalAccessToken::findOrFail((int) $entry['token_id'])->revoked_at);
        $this->assertNull($this->access->entryFor($user));
        $this->assertSame([], $this->firewall->last());
        Queue::assertNotPushed(ApplyEnvVars::class, static fn (ApplyEnvVars $job): bool => true);
    }

    public function test_deleting_a_project_that_was_never_allowed_changes_nothing(): void
    {
        $user = $this->makeUser($this->name, ['template' => 'dind']);

        $this->deleter()->delete($user);

        $this->assertSame([], $this->firewall->applied);
        $this->assertSame([], $this->access->all());
    }

    private function deleter(): ProjectDeleter
    {
        return new class ($this->access) extends ProjectDeleter {
            public function __construct(private ApiAccess $access)
            {
                parent::__construct(apiAccess: $this->access);
            }

            protected function destroy(User $user): void
            {
            }
        };
    }
}
