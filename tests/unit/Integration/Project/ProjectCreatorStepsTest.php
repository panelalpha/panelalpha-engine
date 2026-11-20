<?php

namespace Tests\Integration\Project;

use App\Exceptions\ProblemException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Domains\AllocatedDomain;
use App\Lib\Domains\DomainAllocationException;
use App\Lib\Domains\DomainAllocator;
use App\Lib\Domains\DomainPlan;
use App\Lib\Project\NewProjectInput;
use App\Lib\Project\ProjectCreator;
use App\Lib\Project\ProjectRebuild;
use App\Lib\Project\PublicRouting\HostDomain;
use App\Lib\Project\PublicRouting\PublicRoutingGrants;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\Tunnel;
use App\Models\User;
use App\System;
use App\System\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingSystem;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * The steps of a create one at a time: naming, the domain bought for it, the user it saves, the checks asked again
 * with the user in hand, and what is recorded once it is saved.
 */
class ProjectCreatorStepsTest extends TestCase
{
    use InMemoryDatabase;

    private const HOST = 'host.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Setting::setRuntimeSettings([PublicRoutingGrants::SETTING => null, 'cert_domain' => self::HOST]);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_create_provisions_then_deploys_into_the_project_with_its_log(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind']);
        $input = new NewProjectInput(['username' => 'shop']);
        $logger = Mockery::mock(DeployLogger::class);
        $creator = Mockery::mock(ProjectCreator::class)->makePartial();
        $creator->expects('provisionForDeploy')->with($input)->andReturn($user);
        $creator->expects('startDeployLog')->with($user)->andReturn($logger);
        $creator->expects('deploy')->with($user, $logger);

        $this->assertSame($user, $creator->create($input));
    }

    public function test_a_redeploy_rebuilds_while_the_project_is_idle(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind']);
        $logger = Mockery::mock(DeployLogger::class);
        $rebuild = Mockery::mock(ProjectRebuild::class);
        $rebuild->expects('whileIdle')->with($user, Mockery::type('callable'))->andReturnUsing(static fn (User $u, callable $start): mixed => $start());
        $rebuild->expects('openLogWithoutTask')->with($user, ProjectRebuild::REBUILD)->andReturn($logger);
        $rebuild->expects('run')->with($user, ProjectRebuild::REBUILD, $logger, null)->andReturn([]);
        $this->app->instance(ProjectRebuild::class, $rebuild);

        (new ProjectCreator)->redeploy($user);
    }

    public function test_a_www_domain_is_created_as_the_name_under_it(): void
    {
        $params = $this->invoke('namedParams', new NewProjectInput(['username' => 'shop', 'domain' => 'www.shop.example.com']));

        $this->assertSame(['username' => 'shop', 'domain' => 'shop.example.com'], $params);
    }

    public function test_a_domain_the_allocator_cannot_give_is_a_problem_with_its_reason(): void
    {
        $allocator = Mockery::mock(DomainAllocator::class);
        $allocator->expects('allocate')->with('shop', 'shop.example.com', null)
            ->andThrow(new DomainAllocationException('No name is left under the zone.', 'zone_exhausted'));
        $this->app->instance(DomainAllocator::class, $allocator);

        $problem = $this->problemOf(fn () => $this->invoke('allocateDomain', ['username' => 'shop', 'domain' => 'shop.example.com']));

        $this->assertSame(['field' => 'domain', 'code' => 'zone_exhausted', 'message' => 'No name is left under the zone.'], $problem);
    }

    public function test_a_site_password_is_stored_only_as_a_hash(): void
    {
        $user = $this->invoke('newUser', ['username' => 'shop', 'domain' => 'shop.example.com', 'password' => 'opensesame'], $this->allocated('shop.example.com'), null, null);

        $details = $user->getDetails();
        $this->assertTrue($details['site_password_enabled']);
        $this->assertSame(1, $details['site_password_version']);
        $this->assertTrue(password_verify('opensesame', $details['site_password_hash']));
        $this->assertStringNotContainsString('opensesame', json_encode($details));
        $this->assertFalse($user->exists);
    }

    public function test_a_name_still_held_on_the_host_is_unavailable(): void
    {
        $this->app->instance(System::class, new RecordingSystem);

        $problem = $this->problemOf(fn () => $this->invoke('assertNameStillFree', $this->candidate('shop', true), 'shop.example.com'));

        $this->assertSame(['username', 'name_unavailable'], [$problem['field'], $problem['code']]);
        $this->assertStringContainsString("'shop' is still held on this server", $problem['message']);
    }

    public function test_a_domain_another_project_serves_as_an_alias_is_taken(): void
    {
        $holder = $this->makeUser('holder', [], 'holder.example.com');
        $main = $this->makeMainDomain($holder, 'holder.example.com');
        $main->setDetails(['aliases' => ['shop.example.com']]);
        $main->save();

        $problem = $this->problemOf(fn () => $this->invoke('assertNameStillFree', $this->candidate('shop', false), 'shop.example.com'));

        $this->assertSame(['field' => 'domain', 'code' => 'domain_taken', 'message' => 'Domain name not available.'], $problem);
    }

    public function test_the_servers_own_name_needs_the_operators_permission(): void
    {
        $problem = $this->problemOf(fn () => $this->invoke('assertNameStillFree', $this->candidate('shop', false), self::HOST));

        $this->assertSame(['domain', HostDomain::CLAIM_REFUSED], [$problem['field'], $problem['code']]);
        $this->invoke('assertNameStillFree', $this->candidate('shop', false), 'shop.example.com');
    }

    public function test_the_main_domain_carries_a_redirect_and_its_www_alias(): void
    {
        $user = $this->makeUser('shop', [], 'shop.example.com');

        $domain = $this->invoke('mainDomainFor', ['domain' => 'shop.example.com', 'domain_redirect_url' => 'https://elsewhere.example.com'])($user);

        $this->assertSame(['shop.example.com', 'main', $user->id], [$domain->domain, $domain->type, $domain->user_id]);
        $this->assertTrue($domain->getDetails()['redirect_enabled']);
        $this->assertSame('https://elsewhere.example.com', $domain->getDetails()['redirect_url']);
        $this->assertSame(['www.shop.example.com'], $domain->getDetails()['aliases']);
    }

    public function test_a_dedicated_address_lost_to_a_race_is_recorded_as_none(): void
    {
        $user = $this->withoutFreeAddresses($this->makeUser('shop', ['dedicated_ipv4' => true, 'dedicated_ipv6' => true]));
        Log::spy();

        $this->invoke('assignDedicatedIps', $user, ['dedicated_ipv4' => true, 'dedicated_ipv6' => true]);

        $details = User::findByUsernameOrFail('shop')->getDetails();
        $this->assertFalse($details['dedicated_ipv4']);
        $this->assertFalse($details['dedicated_ipv6']);
        Log::shouldHaveReceived('warning')->with('No free address left for dedicated_ipv4 of shop; created without one')->once();
        Log::shouldHaveReceived('warning')->with('No free address left for dedicated_ipv6 of shop; created without one')->once();
    }

    public function test_a_panelalpha_online_name_is_recorded_as_the_main_domains_tunnel(): void
    {
        $fqdn = 'shop-9f0e.'.DomainPlan::PANELALPHA_ONLINE;
        $user = $this->makeUser('shop', [], $fqdn);
        $domain = $this->makeMainDomain($user, $fqdn);
        $allocated = new AllocatedDomain(
            domain: $fqdn,
            source: DomainPlan::SOURCE_PANELALPHA_ONLINE,
            tunnelProvider: Tunnel::PROVIDER_PANELALPHA,
            allocation: ['path' => 'shop-9f0e', 'path_fqdn' => $fqdn, 'wdns_site_id' => 7, 'valid_until' => null, 'target_ip' => TestNetwork::public(5)],
        );

        $this->invoke('recordOnlineTunnel', $user, $domain, $allocated);
        $this->invoke('recordOnlineTunnel', $user, $domain, $this->allocated('shop.example.com'));

        $tunnel = Tunnel::query()->sole();
        $this->assertSame([$fqdn, Tunnel::PROVIDER_PANELALPHA, $domain->id], [$tunnel->hostname, $tunnel->provider, $tunnel->domain_id]);
    }

    public function test_a_unique_violation_on_neither_the_name_nor_the_domain_is_not_mistaken_for_one(): void
    {
        $violation = new UniqueConstraintViolationException('sqlite', 'insert into domains', [], new RuntimeException('UNIQUE constraint failed: domains.other'));
        $user = User::make(['username' => 'shop', 'domain' => 'shop.example.com', 'details' => []]);

        try {
            $this->invoke('saveNewProject', $user, static fn (): Domain => throw $violation, 'name');
            $this->fail('the violation was swallowed');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertSame($violation, $e);
        }

        $this->assertFalse(User::existsByUsername('shop'), 'the transaction rolled the user back');
    }

    private function allocated(string $domain): AllocatedDomain
    {
        return new AllocatedDomain(domain: $domain, source: DomainPlan::SOURCE_REQUESTED);
    }

    /** An unsaved user whose host has, or has not, anything under its name. */
    private function candidate(string $username, bool $hostingExists): User
    {
        $project = Mockery::mock(Project::class);
        $project->allows('hostingExists')->andReturn($hostingExists);
        $user = new class ($project) extends User {
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
        $user->username = $username;

        return $user;
    }

    private function withoutFreeAddresses(User $user): User
    {
        $taken = new class extends User {
            protected $table = 'users';

            public function assignFreeDedicatedIpv4(): bool
            {
                return false;
            }

            public function assignFreeDedicatedIpv6(): bool
            {
                return false;
            }
        };
        $taken->setRawAttributes($user->getAttributes(), true);
        $taken->exists = true;

        return $taken;
    }

    /** @return array{field: string, code: string, message: string} */
    private function problemOf(\Closure $call): array
    {
        try {
            $call();
        } catch (ProblemException $e) {
            return $e->problems[0];
        }
        $this->fail('expected a ProblemException');
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(ProjectCreator::class, $method))->invoke(null, ...$arguments);
    }
}
