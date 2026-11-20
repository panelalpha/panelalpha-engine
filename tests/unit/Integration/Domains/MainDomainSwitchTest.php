<?php

namespace Tests\Integration\Domains;

use App\Lib\Domains\MainDomainSwitch;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Domain as ProjectDomain;
use Mockery;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Making an addon the main domain of a container project: the old main becomes an addon, the project takes the
 * name, and an addon without rules of its own is routed to the app the generated rules already point at.
 */
class MainDomainSwitchTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_the_main_domain_is_already_main(): void
    {
        $user = $this->makeUser('shop', ['template' => 'wordpress'], 'shop.example.test');

        $this->assertNull(MainDomainSwitch::refusal($user, $this->makeMainDomain($user, 'shop.example.test')));
    }

    public function test_an_addon_without_rules_is_routed_to_the_running_app_and_rendered(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind'], 'shop.example.test');
        $this->makeMainDomain($user, 'shop.example.test');
        $addon = $this->makeMainDomain($user, 'new.example.test');
        $addon->type = 'addon';
        $addon->save();
        ProxyRule::ensureGeneratedHttpPair('shop', 'shop.example.test', 8080, 'https');
        $vhost = Mockery::mock(ProjectDomain::class);
        $vhost->expects('rebuild')->once();
        $addon->setRelation('user', $this->ownerRenderingWith($user, $vhost));

        MainDomainSwitch::apply($user, $addon);

        $this->assertSame(['new.example.test' => 'main', 'shop.example.test' => 'addon'], Domain::query()->orderBy('domain')->pluck('type', 'domain')->all());
        $this->assertSame('new.example.test', User::findByUsernameOrFail('shop')->domain);
        $rules = ProxyRule::query()->where('server_name', 'new.example.test')->orderBy('listen_port')->get();
        $this->assertSame([80, 443], $rules->pluck('listen_port')->map(static fn ($port): int => (int) $port)->all());
        $this->assertSame([8080, 8080], $rules->pluck('upstream_port')->map(static fn ($port): int => (int) $port)->all());
        $this->assertSame(['https', 'https'], $rules->pluck('upstream_protocol')->all());
    }

    private function ownerRenderingWith(User $user, ProjectDomain $vhost): User
    {
        $project = Mockery::mock(Project::class);
        $project->allows('domain')->andReturn($vhost);
        $owner = new class ($project) extends User {
            protected $table = 'users';

            public function __construct(private ?Project $fake = null)
            {
                parent::__construct();
            }

            public function project(?System $system = null): Project
            {
                return $this->fake ?? parent::project($system);
            }
        };
        $owner->setRawAttributes($user->getAttributes(), true);
        $owner->exists = true;

        return $owner;
    }
}
