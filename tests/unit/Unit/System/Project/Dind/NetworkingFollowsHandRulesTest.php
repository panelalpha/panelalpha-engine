<?php

namespace Tests\Unit\System\Project\Dind;

use App\Models\ProxyRule;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\Networking;
use App\System\Project\Dind\ShellOperations;
use App\System\Services\Webserver;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Hand-made proxy rules to a container project have to follow the app to a new
 * port whether or not the old version was running. A running redeploy moves
 * them in {@see \App\System\Project\Dind\Generation\RouteSwitch::follow()};
 * when nothing was running no routing snapshot is taken, so `routeTo()` is the
 * only place left to move them, and the main domain's generated pair is not
 * enough.
 *
 * issue #826
 */
class NetworkingFollowsHandRulesTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_hand_made_rule_follows_a_stopped_app_to_its_new_port(): void
    {
        $user = $this->makeMainDomain($this->makeUser('alice'), 'alice.test');
        // The app was broken: the old hand-made rule pointed at 3000, the
        // redeploy moves the app to 8080, and nothing was running to snapshot.
        $hand = $this->operatorRule('alice', 18691, 3000);

        $this->networking('alice')->applyRoutes($user->getUser(), 8080, 3000);

        $this->assertSame(8080, ProxyRule::find($hand)->upstream_port);
    }

    /** A rule the app was not on is untouched. */
    public function test_a_rule_to_another_port_is_left_alone(): void
    {
        $user = $this->makeMainDomain($this->makeUser('alice'), 'alice.test');
        $other = $this->operatorRule('alice', 18692, 9000);

        $this->networking('alice')->applyRoutes($user->getUser(), 8080, 3000);

        $this->assertSame(9000, ProxyRule::find($other)->upstream_port);
    }

    private function networking(string $username): Networking
    {
        $webserver = $this->createStub(Webserver::class);
        $webserver->method('rebuildDomainConfig');
        $webserver->method('reload');

        $system = $this->createStub(System::class);
        $system->method('webserver')->willReturn($webserver);

        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn($username);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        return new Networking($dind);
    }

    private function operatorRule(string $user, int $listen, int $upstream): int
    {
        return (int) ProxyRule::query()->create([
            'owner_scope' => 'user', 'username' => $user, 'enabled' => true, 'transport' => 'http',
            'listen_port' => $listen, 'upstream_host' => $user, 'upstream_port' => $upstream, 'is_generated' => false,
        ])->id;
    }
}
