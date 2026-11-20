<?php

namespace Tests\Integration\Domains;

use App\Exceptions\ProblemException;
use App\Lib\Domains\HttpsPorts;
use App\Lib\Domains\HttpsPortsChange;
use App\Lib\Project\PublicRouting\PublicRoutingGrants;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Domain as ProjectDomain;
use App\System\Services\Webserver;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A container domain's HTTPS ports written and rendered: nginx's listeners follow only when the set of ports changed,
 * and a refused change is a problem naming its field.
 */
class HttpsPortsApplyTest extends TestCase
{
    use InMemoryDatabase;

    private System&MockInterface $system;

    private Webserver&MockInterface $webserver;

    private ProjectDomain&MockInterface $vhost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings([PublicRoutingGrants::SETTING => null]);
        $this->webserver = Mockery::mock(Webserver::class);
        $this->system = Mockery::mock(System::class);
        $this->system->allows('webserver')->andReturn($this->webserver);
        $this->vhost = Mockery::mock(ProjectDomain::class);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_refused_change_is_a_problem_on_its_field(): void
    {
        try {
            $this->change()->assertAllowed($this->domain(), [443], 8443);
            $this->fail('the change was allowed');
        } catch (ProblemException $e) {
            $this->assertSame([HttpsPorts::MAIN_KEY, 'https_port_not_listed'], [$e->problems[0]['field'], $e->problems[0]['code']]);
        }

        $this->change()->assertAllowed($this->domain(), [443], 443);
    }

    public function test_a_domain_answers_on_one_to_eight_ports(): void
    {
        $domain = $this->domain();

        $this->assertSame('https_ports_count', $this->change()->refusal($domain, [], 443)['code'] ?? null);
        $this->assertSame('https_ports_count', $this->change()->refusal($domain, range(8441, 8449), 8441)['code'] ?? null);
    }

    public function test_going_back_to_443_needs_no_permission(): void
    {
        $domain = $this->domain([HttpsPorts::PORTS_KEY => [8443], HttpsPorts::MAIN_KEY => 8443]);

        $this->assertNull($this->change()->refusal($domain, [443, 8443], 443));
    }

    public function test_new_ports_are_written_rendered_and_the_listeners_follow(): void
    {
        $domain = $this->domain();
        $this->vhost->expects('rebuild');
        $this->webserver->expects('applyHttpsPorts');
        $this->system->expects('reloadWebserver');

        $this->change()->apply($domain, [443, 8443], 8443);

        $details = Domain::query()->sole()->getDetails();
        $this->assertSame([443, 8443], $details[HttpsPorts::PORTS_KEY]);
        $this->assertSame(8443, $details[HttpsPorts::MAIN_KEY]);
    }

    public function test_the_same_ports_in_another_order_only_reload_nginx(): void
    {
        $domain = $this->domain([HttpsPorts::PORTS_KEY => [443, 8443], HttpsPorts::MAIN_KEY => 443]);
        $this->vhost->expects('rebuild');
        $this->webserver->shouldNotReceive('applyHttpsPorts');
        $this->system->expects('reloadWebserver');

        $this->change()->apply($domain, [8443, 443], 8443);

        $this->assertSame(8443, Domain::query()->sole()->getDetails()[HttpsPorts::MAIN_KEY]);
    }

    public function test_443_alone_leaves_no_ports_on_the_domain(): void
    {
        $domain = $this->domain([HttpsPorts::PORTS_KEY => [8443], HttpsPorts::MAIN_KEY => 8443]);
        $this->vhost->expects('rebuild');
        $this->webserver->expects('applyHttpsPorts');
        $this->system->expects('reloadWebserver');

        $this->change()->apply($domain, [HttpsPorts::DEFAULT], HttpsPorts::DEFAULT);

        $details = Domain::query()->sole()->getDetails();
        $this->assertArrayNotHasKey(HttpsPorts::PORTS_KEY, $details);
        $this->assertArrayNotHasKey(HttpsPorts::MAIN_KEY, $details);
        $this->assertSame('/shop.example.test/public_html', $details['document_root']);
    }

    private function change(): HttpsPortsChange
    {
        return new HttpsPortsChange($this->system, new PublicRoutingGrants);
    }

    /** @param array<string, mixed> $details */
    private function domain(array $details = []): Domain
    {
        $user = User::query()->firstWhere('username', 'shop') ?? $this->makeUser('shop', ['template' => 'dind'], 'shop.example.test');
        $domain = Domain::query()->firstWhere('domain', 'shop.example.test') ?? $this->makeMainDomain($user, 'shop.example.test');
        $domain->setDetails($details);
        $domain->save();
        $project = Mockery::mock(Project::class);
        $project->allows('domain')->andReturn($this->vhost);
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
        $domain->setRelation('user', $owner);

        return $domain;
    }
}
