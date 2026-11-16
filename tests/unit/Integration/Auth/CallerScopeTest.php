<?php

namespace Tests\Integration\Auth;

use App\Auth\CallerScope;
use App\Auth\TokenAbilities;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * What a caller may hand on: never a surface, operation, command or project outside its own token, nor a later
 * expiry. A project-scoped caller is refused on the token routes before any of this, so its rules are pinned here.
 */
class CallerScopeTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Carbon::setTestNow('2026-11-16 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_legacy_or_console_caller_grants_anything(): void
    {
        foreach ([CallerScope::everything(), $this->caller([TokenAbilities::EVERYTHING])] as $caller) {
            $this->assertSame([], $caller->exceeded([TokenAbilities::EVERYTHING], null));
            $this->assertSame([], $caller->exceeded(TokenAbilities::build(api: true, mcp: true, projects: ['alice']), null));
        }
    }

    public function test_a_caller_that_is_not_legacy_never_covers_a_legacy_token(): void
    {
        $caller = $this->caller(TokenAbilities::build(api: true, mcp: true));

        $this->assertSame(['everything'], $caller->exceeded([TokenAbilities::EVERYTHING], null));
        $this->assertSame(['everything'], $caller->exceeded([], null));
    }

    public function test_a_surface_the_caller_lacks_is_never_granted(): void
    {
        $this->assertSame(['mcp'], $this->caller(TokenAbilities::build(api: true, mcp: false))->exceeded(TokenAbilities::build(api: false, mcp: true), null));
        $this->assertSame(['api'], $this->caller(TokenAbilities::build(api: false, mcp: true))->exceeded(TokenAbilities::build(api: true, mcp: false), null));
    }

    public function test_routes_and_commands_stay_within_the_callers_own(): void
    {
        $caller = $this->caller(TokenAbilities::build(api: true, mcp: true, commands: ['project_list'], routes: ['GET /projects', 'GET /tasks']));

        $this->assertSame([], $caller->exceeded(TokenAbilities::build(api: true, mcp: true, commands: ['project_list'], routes: ['GET /tasks']), null));
        $this->assertSame(['routes'], $caller->exceeded(TokenAbilities::build(api: true, mcp: false), null));
        $this->assertSame(['routes'], $caller->exceeded(TokenAbilities::build(api: true, mcp: false, routes: ['DELETE /projects/{username}']), null));
        $this->assertSame(['commands'], $caller->exceeded(TokenAbilities::build(api: false, mcp: true), null));
        $this->assertSame(['commands'], $caller->exceeded(TokenAbilities::build(api: false, mcp: true, commands: ['project_delete']), null));
    }

    public function test_a_project_scoped_caller_grants_only_its_own_projects_and_never_an_unscoped_token(): void
    {
        $caller = $this->caller(TokenAbilities::build(api: true, mcp: true, projects: ['alice', 'bob']));

        $this->assertSame([], $caller->exceeded(TokenAbilities::build(api: true, mcp: false, projects: ['alice']), null));
        $this->assertSame([], $caller->exceeded(TokenAbilities::build(api: true, mcp: false, projects: []), null));
        $this->assertSame(['projects'], $caller->exceeded(TokenAbilities::build(api: true, mcp: false, projects: ['alice', 'carol']), null));
        $this->assertSame(['projects'], $caller->exceeded(TokenAbilities::build(api: true, mcp: false), null));
    }

    public function test_an_expiring_caller_grants_nothing_that_outlives_it(): void
    {
        $caller = $this->caller(TokenAbilities::build(api: true, mcp: false), Carbon::now()->addDays(30));
        $abilities = TokenAbilities::build(api: true, mcp: false);

        $this->assertSame([], $caller->exceeded($abilities, Carbon::now()->addDays(30)));
        $this->assertSame(['expires_at'], $caller->exceeded($abilities, Carbon::now()->addDays(31)));
        $this->assertSame(['expires_at'], $caller->exceeded($abilities, null));
    }

    public function test_an_ability_outside_the_vocabulary_is_not_handed_on(): void
    {
        $this->assertSame(['other'], $this->caller(TokenAbilities::build(api: true, mcp: false))->exceeded(['api', 'hub:admin'], null));
        $this->assertSame([], $this->caller(['api', 'hub:admin'])->exceeded(['api', 'hub:admin'], null));
    }

    public function test_a_surface_is_refused_with_403_and_a_limit_with_422_on_its_field(): void
    {
        $caller = $this->caller(TokenAbilities::build(api: true, mcp: false, routes: ['GET /projects'], projects: ['alice']), Carbon::now()->addDay());

        try {
            $caller->mayGrant(TokenAbilities::build(api: false, mcp: true), null);
            $this->fail('an mcp token was granted');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        try {
            $caller->mayGrant(TokenAbilities::build(api: true, mcp: false), null);
            $this->fail('a wider api token was granted');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['routes', 'projects', 'expires_in'], array_keys($e->errors()));
        }
    }

    public function test_a_caller_covers_itself_and_what_is_within_it(): void
    {
        $self = $this->token(TokenAbilities::build(api: true, mcp: false, routes: ['GET /projects', 'GET /tasks']), Carbon::now()->addDay());
        $caller = CallerScope::of($self);

        $this->assertTrue($caller->covers($self));
        $this->assertTrue($caller->covers($this->token(TokenAbilities::build(api: true, mcp: false, routes: ['GET /tasks']), Carbon::now()->addHour())));
        $this->assertFalse($caller->covers($this->token(TokenAbilities::build(api: true, mcp: false, routes: ['GET /tasks']), null)));
        $this->expectException(AuthorizationException::class);
        $caller->mayManage($this->token(TokenAbilities::build(api: true, mcp: false), Carbon::now()->addHour()));
    }

    /** @param  list<string>  $abilities */
    private function caller(array $abilities, ?Carbon $expiresAt = null): CallerScope
    {
        return CallerScope::of($this->token($abilities, $expiresAt));
    }

    /** @param  list<string>  $abilities */
    private function token(array $abilities, ?Carbon $expiresAt): PersonalAccessToken
    {
        $token = Admin::rootAccount()->createToken('t', $abilities, $expiresAt)->accessToken;
        assert($token instanceof PersonalAccessToken);

        return $token;
    }
}
