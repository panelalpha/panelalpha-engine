<?php

namespace Tests\Integration\Console\Domains;

use App\Exceptions\ProblemException;
use App\Jobs\RebuildDomainConfigs;
use App\Lib\Domains\DomainAddressChange;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\Task;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** `sites:base-domain`, the refusals of `domain:wp-cli`, and what the address commands print for each answer of DomainAddressChange. */
class DomainsMiscCommandsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::flushCache();
        $shop = $this->makeUser('shop', ['template' => 'dind'], 'shop.example.test');
        $this->makeMainDomain($shop, 'shop.example.test');
        Domain::query()->create(['user_id' => $shop->id, 'domain' => 'shop.example.com', 'type' => 'addon', 'details' => ['document_root' => '/shop.example.com/public_html']]);
    }

    protected function tearDown(): void
    {
        Setting::flushCache();
        parent::tearDown();
    }

    public function test_base_domain_shows_nothing_set_then_saves_an_own_domain(): void
    {
        Bus::fake();

        $this->artisan('sites:base-domain')
            ->expectsOutput('No base domain set; new projects fall back to <name>.local.')
            ->assertExitCode(0);

        $this->artisan('sites:base-domain Sites.Example.COM.')
            ->expectsOutput('New project sites will be named under sites.example.com')
            ->expectsOutput('  1. point *.sites.example.com at this host (a wildcard A record)')
            ->doesntExpectOutputToContain('wildcard zone every engine shares')
            ->assertExitCode(0);

        $this->assertSame('sites.example.com', Setting::get('default_wildcard_domain'));
        Bus::assertDispatched(RebuildDomainConfigs::class);

        $this->artisan('sites:base-domain')
            ->expectsOutput('Sites are named under: sites.example.com')
            ->expectsOutput('  a domain of your own — certificates here have their own budget')
            ->assertExitCode(0);
    }

    public function test_base_domain_warns_about_a_shared_zone(): void
    {
        Bus::fake();

        $this->artisan('sites:base-domain sslip.io')
            ->expectsOutput('sslip.io is a wildcard zone every engine shares.')
            ->expectsOutputToContain("Let's Encrypt counts 50 new certificates")
            ->assertExitCode(0);

        $this->artisan('sites:base-domain')
            ->expectsOutputToContain('a wildcard zone the fleet shares')
            ->assertExitCode(0);
    }

    public function test_base_domain_refuses_a_name_no_certificate_can_cover(): void
    {
        Bus::fake();

        $this->artisan('sites:base-domain sites.local')
            ->expectsOutput('sites.local is not a hostname sites can be named under.')
            ->assertExitCode(1);

        $this->assertNull(Setting::get('default_wildcard_domain'));
        Bus::assertNotDispatched(RebuildDomainConfigs::class);
    }

    public function test_wp_cli_refuses_an_unknown_domain_or_one_without_a_project(): void
    {
        $this->artisan('domain:wp-cli nowhere.example.test option get home')
            ->expectsOutput('Domain `nowhere.example.test` not found in database.')
            ->assertExitCode(1);

        $orphan = Domain::query()->create(['user_id' => 999, 'domain' => 'orphan.example.test', 'type' => 'addon', 'details' => []]);
        $this->artisan('domain:wp-cli orphan.example.test option get home')
            ->expectsOutput("Project #{$orphan->user_id} of domain `orphan.example.test` not found in database.")
            ->assertExitCode(1);
    }

    private function addressChange(): Mockery\MockInterface
    {
        $address = Mockery::mock(DomainAddressChange::class);
        $this->app->instance(DomainAddressChange::class, $address);

        return $address;
    }

    public function test_main_reports_a_redeploy_task_and_the_domain_already_main(): void
    {
        $task = new Task;
        $task->id = 42;
        $this->addressChange()->shouldReceive('apply')->twice()->andReturn($task, null);

        $this->artisan('project:domain:main shop.example.com --project=shop')
            ->expectsOutputToContain('shop.example.com is the main domain of shop now')
            ->expectsOutput('Redeploying so the app is told its new address (task 42).')
            ->assertExitCode(0);

        $this->artisan('project:domain:main shop.example.test --project=shop')
            ->expectsOutput('shop.example.test is already the main domain of shop.')
            ->assertExitCode(0);
    }

    public function test_main_and_https_ports_print_a_refused_change(): void
    {
        $this->addressChange()->shouldReceive('apply')->andThrow(ProblemException::one('main', 'not_dind', 'Only a container project has a main domain to change.'));

        $this->artisan('project:domain:main shop.example.com --project=shop')
            ->expectsOutput('Only a container project has a main domain to change.')
            ->assertExitCode(1);
        $this->artisan('project:domain:https-ports shop.example.test --project=shop --ports=443,8443 --main=8443')
            ->expectsOutput('Only a container project has a main domain to change.')
            ->assertExitCode(1);
    }

    public function test_https_ports_passes_the_ports_and_reports_the_redeploy(): void
    {
        $task = new Task;
        $task->id = 7;
        $this->addressChange()->shouldReceive('apply')
            ->once()
            ->withArgs(fn ($user, $domain, array $params): bool => $params === ['https_ports' => [443, 8443], 'https_port' => 8443])
            ->andReturn($task);

        $this->artisan('project:domain:https-ports shop.example.test --project=shop --ports="443, 8443,443" --main=8443')
            ->expectsOutput('Redeploying so the app is told its new address (task 7).')
            ->expectsOutputToContain('shop.example.test answers HTTPS on')
            ->assertExitCode(0);
    }

    public function test_https_ports_and_main_refuse_bad_options(): void
    {
        $this->artisan('project:domain:https-ports shop.example.test --project=shop --main=x')
            ->expectsOutput('--main takes one port number.')
            ->assertExitCode(1);
        $this->artisan('project:domain:https-ports shop.example.test')
            ->expectsOutputToContain('--project is required.')
            ->assertExitCode(1);
        $this->artisan('project:domain:main nowhere.example.test --project=shop')
            ->expectsOutput("'nowhere.example.test' is not a domain of shop.")
            ->assertExitCode(1);
    }
}
