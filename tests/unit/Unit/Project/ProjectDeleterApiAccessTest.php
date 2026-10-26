<?php

namespace Tests\Unit\Project;

use App\Auth\TokenAbilities;
use App\Jobs\ApplyEnvVars;
use App\Lib\Project\ApiAccess;
use App\Lib\Project\ProjectDeleter;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use App\Models\Setting;
use App\Models\User;
use ArrayObject;
use Illuminate\Contracts\Cache\LockTimeoutException;
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

    public function test_deleting_a_project_takes_it_out_of_every_other_tokens_scope(): void
    {
        $user = $this->makeUser($this->name, ['template' => 'dind']);
        $shared = $this->token(['mcp'], [$this->name, 'other']);
        $only = $this->token(['api'], [$this->name]);
        $unscoped = $this->token(['api', 'mcp'], null);

        $this->deleter()->delete($user);

        $this->assertSame(['other'], TokenAbilities::of($shared->refresh())->projects());
        $this->assertSame([], TokenAbilities::of($only->refresh())->projects(), 'A token left with no project stays scoped.');
        $this->assertNull(TokenAbilities::of($unscoped->refresh())->projects());
        $this->assertTrue(TokenAbilities::of($shared)->mayUseMcp());
    }

    public function test_a_release_that_leaves_the_allowance_fails_the_delete(): void
    {
        $user = $this->makeUser($this->name, ['template' => 'dind']);
        $locked = new ArrayObject(['locked' => false]);
        $this->access = $this->accessLockedWhen($locked);
        $this->access->allow($user, ApiAccess::SCOPE_SELF, false);
        $scoped = $this->token(['api'], [$this->name]);
        $locked['locked'] = true;
        $destroyed = new ArrayObject();

        $failure = null;
        try {
            $this->deleter($destroyed)->delete($user);
        } catch (\RuntimeException $e) {
            $failure = $e;
        }

        $this->assertStringContainsString('was not deleted', (string) $failure?->getMessage());
        $this->assertSame([], $destroyed->getArrayCopy());
        $this->assertNotNull($this->access->entryFor($user));
        $this->assertSame([$this->name], TokenAbilities::of($scoped->refresh())->projects());
    }

    /**
     * @param  list<string>  $surfaces
     * @param  ?list<string>  $projects
     */
    private function token(array $surfaces, ?array $projects): PersonalAccessToken
    {
        $abilities = TokenAbilities::build(
            api: in_array('api', $surfaces, true),
            mcp: in_array('mcp', $surfaces, true),
            projects: $projects,
        );

        /** @var PersonalAccessToken */
        return Admin::rootAccount()->createToken('scoped', $abilities)->accessToken;
    }

    private function accessLockedWhen(ArrayObject $locked): ApiAccess
    {
        return new class ($this->firewall, $locked) extends ApiAccess {
            public function __construct(RecordingFirewall $firewall, private ArrayObject $locked)
            {
                parent::__construct(firewall: $firewall);
            }

            protected function mutate(callable $work): mixed
            {
                if ($this->locked['locked']) {
                    throw new LockTimeoutException('lock not obtained');
                }

                return parent::mutate($work);
            }
        };
    }

    private function deleter(?ArrayObject $destroyed = null): ProjectDeleter
    {
        return new class ($this->access, $destroyed ?? new ArrayObject()) extends ProjectDeleter {
            public function __construct(private ApiAccess $access, private ArrayObject $destroyed)
            {
                parent::__construct(apiAccess: $this->access);
            }

            protected function destroy(User $user): void
            {
                $this->destroyed->append($user->username);
            }
        };
    }
}
