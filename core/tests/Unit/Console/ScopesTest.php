<?php

namespace Tests\Unit\Console;

use App\Auth\TokenAbilities;
use App\Console\Wizard\Scopes\CommandScope;
use App\Console\Wizard\Scopes\RouteScope;
use App\Console\Wizard\Scopes\Scope;
use App\Models\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What the wizard writes when boxes are ticked.
 *
 * The screens are shared and the vocabularies are not, so the thing worth
 * pinning down per scope is that what it writes reads back as what was ticked,
 * and that it leaves the token's other surface alone — the two are edited from
 * different sections and neither should quietly undo the other.
 */
class ScopesTest extends TestCase
{
    /** @return array<string, array{0: Scope}> */
    public static function scopes(): array
    {
        return [
            'commands' => [new CommandScope()],
            'routes' => [new RouteScope()],
        ];
    }

    #[DataProvider('scopes')]
    public function test_what_it_writes_reads_back_as_what_was_ticked(Scope $scope): void
    {
        $keys = array_slice($scope->universe()[array_key_first($scope->universe())], 0, 3);

        $token = $this->token(['*']);
        $token->abilities = $scope->abilitiesFor($token, $keys);

        $this->assertEqualsCanonicalizing($keys, $scope->current(TokenAbilities::of($token)));
    }

    /** Ticking everything is stored as no limit, so the token follows the engine. */
    #[DataProvider('scopes')]
    public function test_no_limit_is_written_as_no_limit(Scope $scope): void
    {
        $token = $this->token(['*']);
        $token->abilities = $scope->abilitiesFor($token, null);

        $this->assertNull($scope->current(TokenAbilities::of($token)));
    }

    /**
     * The two sections edit the same token from different menus. Narrowing one
     * surface must not silently widen or close the other.
     */
    public function test_each_scope_leaves_the_other_surface_alone(): void
    {
        $both = TokenAbilities::build(
            api: true,
            mcp: true,
            commands: ['project_list'],
            routes: ['GET /projects'],
        );

        $token = $this->token($both);
        $token->abilities = (new RouteScope())->abilitiesFor($token, ['GET /domains']);

        $after = TokenAbilities::of($token);

        $this->assertSame(['project_list'], $after->commands(), 'the MCP side was edited from the API section');
        $this->assertSame(['GET /domains'], $after->routes());
        $this->assertTrue($after->mayUseMcp());
        $this->assertTrue($after->mayUseApi());

        $token->abilities = (new CommandScope())->abilitiesFor($token, ['domain_create']);
        $after = TokenAbilities::of($token);

        $this->assertSame(['GET /domains'], $after->routes(), 'the API side was edited from the MCP section');
        $this->assertSame(['domain_create'], $after->commands());
    }

    /** @param array<int, string> $abilities */
    private function token(array $abilities): PersonalAccessToken
    {
        return new PersonalAccessToken(['name' => 'test', 'abilities' => $abilities]);
    }
}
