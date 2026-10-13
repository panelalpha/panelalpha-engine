<?php

namespace Tests\Unit\Auth;

use App\Auth\TokenAbilities;
use App\Lib\Limits\ResourceLimit;
use App\Mcp\Servers\EngineServer;
use App\Mcp\ToolPolicy;
use App\Mcp\ToolRegistry;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The token vocabulary: which surface a token may use, and which MCP commands.
 *
 * No database — abilities live on the model, and an unsaved one carries them
 * as well as a stored one does. What is worth pinning down is that one array
 * of strings is read the same way by the two middlewares, the four creation
 * sites and the listing, because it used not to be.
 */
class TokenAbilitiesTest extends TestCase
{
    /**
     * The shape of every token minted before any of this existed. If this
     * breaks, an upgrade locks the operator out of their own engine.
     *
     * @param array<int, string> $abilities
     */
    #[DataProvider('legacyShapes')]
    public function test_a_legacy_token_may_still_do_everything(array $abilities): void
    {
        $abilities = TokenAbilities::fromList($abilities);

        $this->assertTrue($abilities->isLegacy());
        $this->assertTrue($abilities->mayUseApi());
        $this->assertTrue($abilities->mayUseMcp());
        $this->assertNull($abilities->commands());
    }

    /** @return array<string, array{0: array<int, string>}> */
    public static function legacyShapes(): array
    {
        return [
            'sanctum default' => [['*']],
            'no abilities at all' => [[]],
        ];
    }

    /** The boundary, in both directions. It used to run in neither. */
    public function test_a_token_is_only_admitted_to_what_it_was_issued_for(): void
    {
        $api = TokenAbilities::fromList(TokenAbilities::build(api: true, mcp: false));

        $this->assertTrue($api->mayUseApi());
        $this->assertFalse($api->mayUseMcp(), 'software tokens are not assistants');

        $assistant = TokenAbilities::fromList(TokenAbilities::build(api: false, mcp: true));

        $this->assertFalse($assistant->mayUseApi(), 'an assistant token cannot curl the API');
        $this->assertTrue($assistant->mayUseMcp());

        $both = TokenAbilities::fromList(TokenAbilities::build(api: true, mcp: true));

        $this->assertTrue($both->mayUseApi());
        $this->assertTrue($both->mayUseMcp());
    }

    /**
     * A named command implies the surface it is named on, so a hand-written
     * ability list cannot produce a token that is scoped to commands it is
     * then refused permission to speak.
     */
    public function test_naming_a_command_implies_the_mcp_surface(): void
    {
        $abilities = TokenAbilities::fromList(['mcp:project_list']);

        $this->assertTrue($abilities->mayUseMcp());
        $this->assertSame(['project_list'], $abilities->commands());
        $this->assertFalse($abilities->mayUseApi());
    }

    public function test_commands_narrow_the_tools_a_token_may_call(): void
    {
        $abilities = TokenAbilities::fromList(
            TokenAbilities::build(api: false, mcp: true, commands: ['project_list', 'domain_create'])
        );

        $policy = new ToolPolicy();
        $kept = array_map(
            fn (string $class): string => $policy->nameOf($class),
            $abilities->filterTools(ToolRegistry::all())
        );

        $this->assertEqualsCanonicalizing(['project_list', 'domain_create'], $kept);
    }

    /** Naming no commands is "all of them", which is not the same as none. */
    public function test_no_command_limit_is_not_an_empty_command_limit(): void
    {
        $abilities = TokenAbilities::fromList(TokenAbilities::build(api: false, mcp: true));

        $this->assertNull($abilities->commands());
        $this->assertSame(ToolRegistry::all(), $abilities->filterTools(ToolRegistry::all()));
    }

    public function test_a_project_scope_is_read_off_the_abilities(): void
    {
        $abilities = TokenAbilities::fromList(
            TokenAbilities::build(api: false, mcp: true, projects: ['alice'])
        );

        $this->assertTrue($abilities->isProjectScoped());
        $this->assertSame(['alice'], $abilities->projects());
        $this->assertTrue($abilities->mayReachProject('alice'));
        $this->assertFalse($abilities->mayReachProject('bob'));
        $this->assertFalse($abilities->mayReachProject(null));
    }

    /** An empty scope must not fall back to the unscoped "every project". */
    public function test_an_empty_project_scope_reaches_no_project(): void
    {
        $abilities = TokenAbilities::fromList(
            TokenAbilities::build(api: false, mcp: true, projects: [])
        );

        $this->assertTrue($abilities->isProjectScoped());
        $this->assertSame([], $abilities->projects());
        $this->assertFalse($abilities->mayReachProject('alice'));
    }

    public function test_a_token_without_a_project_scope_reaches_every_project(): void
    {
        $abilities = TokenAbilities::fromList(TokenAbilities::build(api: false, mcp: true));

        $this->assertFalse($abilities->isProjectScoped());
        $this->assertNull($abilities->projects());
        $this->assertTrue($abilities->mayReachProject('anyone'));
    }

    public function test_a_project_scope_survives_replacing_the_other_limits(): void
    {
        $scoped = TokenAbilities::fromList(TokenAbilities::build(
            api: true,
            mcp: true,
            commands: ['project_list'],
            routes: ['GET /domains'],
            projects: ['alice'],
        ));

        $this->assertSame(['alice'], TokenAbilities::fromList($scoped->withCommands(['domain_create']))->projects());
        $this->assertSame(['alice'], TokenAbilities::fromList($scoped->withRoutes(null))->projects());
    }

    public function test_replacing_the_project_scope_keeps_the_other_limits(): void
    {
        $scoped = TokenAbilities::fromList(TokenAbilities::build(
            api: true,
            mcp: true,
            commands: ['project_list'],
            projects: ['alice'],
        ));

        $updated = TokenAbilities::fromList($scoped->withProjects(['bob']));

        $this->assertSame(['bob'], $updated->projects());
        $this->assertSame(['project_list'], $updated->commands());

        $unscoped = TokenAbilities::fromList($updated->withProjects(null));

        $this->assertNull($unscoped->projects());
        $this->assertSame(['project_list'], $unscoped->commands());
    }

    public function test_a_project_scoped_token_is_not_unrestricted(): void
    {
        $scoped = TokenAbilities::fromList(TokenAbilities::build(api: false, mcp: true, projects: ['alice']));

        $this->assertFalse($scoped->isUnrestricted());
        $this->assertTrue(TokenAbilities::fromList(TokenAbilities::build(api: false, mcp: true))->isUnrestricted());
    }

    public function test_a_project_scope_hides_the_tools_of_refused_global_routes(): void
    {
        $names = $this->toolNamesOf(TokenAbilities::build(api: false, mcp: true, projects: ['alice']));

        foreach (['project_list', 'project_get', 'domain_php_version_set', 'task_list', 'task_cancel',
                  'system_info', 'source_inspect', 'project_list_summary'] as $kept) {
            $this->assertContains($kept, $names, "{$kept} should stay reachable for a project-scoped token");
        }

        foreach (['firewall_status', 'metrics_latest', 'project_create', 'project_create_sync', 'system_ssl_config_get'] as $hidden) {
            $this->assertNotContains($hidden, $names, "{$hidden} should be hidden from a project-scoped token");
        }
    }

    public function test_a_project_scope_may_reach_its_parameterised_routes(): void
    {
        $abilities = TokenAbilities::fromList(TokenAbilities::build(api: true, mcp: true, projects: ['alice']));

        $this->assertTrue($abilities->mayReachRoute('GET /projects/{username}'));
        $this->assertTrue($abilities->mayReachRoute('PUT /domains/{domain}/php-version'));
        $this->assertTrue($abilities->mayReachRoute('GET /tasks/{id}'));
        $this->assertFalse($abilities->mayReachRoute('GET /firewall/status'));
        $this->assertFalse($abilities->mayReachRoute('POST /projects'));
    }

    /** Suspending is the operator's, so a scoped assistant is not even offered it. */
    public function test_a_project_scope_hides_the_operators_project_tools(): void
    {
        $names = $this->toolNamesOf(TokenAbilities::build(api: false, mcp: true, projects: ['alice']));

        $this->assertNotContains('project_suspend', $names);
        $this->assertNotContains('project_unsuspend', $names);
        $this->assertContains('project_update', $names);
    }

    public function test_only_an_unscoped_token_may_call_the_operators_routes(): void
    {
        $scoped = TokenAbilities::fromList(TokenAbilities::build(api: true, mcp: true, projects: ['alice']));
        $unscoped = TokenAbilities::fromList(TokenAbilities::build(api: true, mcp: true));

        foreach (['PUT /projects/{username}/suspend', 'PUT /projects/{username}/unsuspend'] as $operation) {
            $this->assertFalse($scoped->mayCallOperatorRoute($operation), $operation);
            $this->assertFalse($scoped->mayReachRoute($operation), $operation);
            $this->assertTrue($unscoped->mayCallOperatorRoute($operation), $operation);
            $this->assertTrue($unscoped->mayReachRoute($operation), $operation);
        }

        $this->assertTrue($scoped->mayCallOperatorRoute('PUT /projects/{username}'));
    }

    /** Every resource limit the update takes, and the runtime sizing beside them. */
    public function test_a_scoped_token_may_not_set_the_operators_fields(): void
    {
        $scoped = TokenAbilities::fromList(TokenAbilities::build(api: true, mcp: false, projects: ['alice']));
        $owned = [...ResourceLimit::keys(), 'php_fpm_pool_settings', 'lsphp_settings', 'redis_config'];

        $this->assertSame($owned, $scoped->operatorFields('PUT /projects/{username}', [...$owned, 'email', 'domain']));
        $this->assertSame([], $scoped->operatorFields('PUT /projects/{username}', ['email', 'domain']));
        $this->assertSame([], $scoped->operatorFields('POST /projects/{username}/rebuild', ['memory_limit']));
    }

    public function test_an_unscoped_token_may_set_every_field(): void
    {
        foreach ([TokenAbilities::build(api: true, mcp: false), ['*']] as $abilities) {
            $this->assertSame(
                [],
                TokenAbilities::fromList($abilities)->operatorFields('PUT /projects/{username}', ResourceLimit::keys())
            );
        }
    }

    /** @param array<int, string> $abilities */
    private function toolNamesOf(array $abilities): array
    {
        $token = TokenAbilities::fromList($abilities);
        $policy = new ToolPolicy();

        return array_map(
            fn (string $class): string => $policy->nameOf($class),
            $token->filterTools(ToolRegistry::all())
        );
    }

    /** Re-saving replaces the command list rather than growing it. */
    public function test_saving_again_replaces_the_previous_commands(): void
    {
        $first = TokenAbilities::build(api: false, mcp: true, commands: ['project_list', 'project_get']);
        $second = TokenAbilities::build(api: false, mcp: true, commands: ['domain_list'], existing: $first);

        $this->assertSame(['mcp', 'mcp:domain_list'], $second);
    }

    /** Abilities outside this vocabulary were put there by someone else. */
    public function test_abilities_that_are_not_ours_survive(): void
    {
        $built = TokenAbilities::build(api: true, mcp: false, existing: ['billing', 'mcp', 'mcp:project_list']);

        $this->assertContains('billing', $built);
        $this->assertNotContains('mcp', $built, 'ours are rewritten');
        $this->assertNotContains('mcp:project_list', $built);
    }

    public function test_a_token_keeps_a_command_the_engine_no_longer_offers(): void
    {
        $abilities = TokenAbilities::fromList(
            TokenAbilities::build(api: false, mcp: true, commands: ['project_list', 'gone_away'])
        );

        // The wizard warns about these; dropping them silently would read as
        // the engine having taken something away.
        $this->assertSame(['gone_away', 'project_list'], $abilities->commands());
    }

    public function test_replacing_one_limit_leaves_the_other_alone(): void
    {
        $both = TokenAbilities::fromList(
            TokenAbilities::build(api: true, mcp: true, commands: ['project_list'], routes: ['GET /domains'])
        );

        $narrowed = TokenAbilities::fromList($both->withCommands(['domain_create']));

        $this->assertSame(['domain_create'], $narrowed->commands());
        $this->assertSame(['GET /domains'], $narrowed->routes());

        $widened = TokenAbilities::fromList($both->withRoutes(null));

        $this->assertNull($widened->routes());
        $this->assertSame(['project_list'], $widened->commands());
        $this->assertTrue($widened->mayUseApi());
    }

    public function test_the_label_says_what_a_token_is(): void
    {
        $this->assertSame('everything', TokenAbilities::fromList(['*'])->label());
        $this->assertSame('api', TokenAbilities::fromList(['api'])->label());
        $this->assertSame('assistant', TokenAbilities::fromList(['mcp'])->label());
        $this->assertSame('api + assistant', TokenAbilities::fromList(['api', 'mcp'])->label());
    }

    /**
     * The console has no token and asks about the engine, not about a caller.
     * Not a fail-open: both surfaces authenticate before anything asks this.
     */
    public function test_no_token_means_no_narrowing(): void
    {
        $this->assertTrue(TokenAbilities::forCurrentRequest()->isLegacy());
    }

    /**
     * The point of filtering at registration: a restricted token's server has
     * fewer tools on it, so tools/list is honest and calling one that is not
     * there fails as "no such tool" rather than being refused afterwards.
     */
    public function test_the_server_registers_only_what_the_token_may_call(): void
    {
        $every = $this->toolsOfServer();

        $this->actingWith(TokenAbilities::build(
            api: false,
            mcp: true,
            commands: ['project_list', 'metrics_latest']
        ));

        $this->assertCount(2, $this->toolsOfServer());
        $this->assertGreaterThan(2, count($every));
    }

    public function test_an_unrestricted_token_sees_what_the_engine_offers(): void
    {
        $every = $this->toolsOfServer();

        $this->actingWith(['mcp']);

        $this->assertCount(count($every), $this->toolsOfServer());
    }

    /** @param array<int, string> $abilities */
    private function actingWith(array $abilities): void
    {
        $admin = (new Admin(['name' => 'root']))
            ->withAccessToken(new PersonalAccessToken(['name' => 'test', 'abilities' => $abilities]));

        request()->setUserResolver(fn () => $admin);
    }

    /** @return array<int, class-string<\Laravel\Mcp\Server\Tool>> */
    private function toolsOfServer(): array
    {
        $server = new EngineServer(new FakeTransporter());

        return (new ReflectionProperty($server, 'tools'))->getValue($server);
    }
}
