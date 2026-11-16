<?php

namespace Tests\Unit\Console;

use App\Console\Commands\Domains\ProjectDomainHttpsPortsCommand;
use App\Console\Commands\Domains\ProjectDomainMainCommand;
use App\Lib\Domains\HttpsPorts;
use App\Models\Domain;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * `project:domain:main` and `project:domain:https-ports` act on a domain of the project `--project` names, never on
 * another project's.
 */
class ProjectDomainAddressCommandsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $shop = $this->makeUser('shop', ['template' => 'dind'], 'shop.example.test');
        $this->makeMainDomain($shop, 'shop.example.test');
        Domain::query()->create(['user_id' => $shop->id, 'domain' => 'shop.example.com', 'type' => 'addon', 'details' => ['document_root' => '/shop.example.com/public_html']]);
        $blog = $this->makeUser('blog', ['template' => 'dind'], 'blog.example.test');
        $this->makeMainDomain($blog, 'blog.example.test');
    }

    public function test_the_help_says_both_commands_work_only_on_a_container_project(): void
    {
        foreach ([new ProjectDomainMainCommand, new ProjectDomainHttpsPortsCommand] as $command) {
            $this->assertStringContainsString('container project', $command->getDescription(), (string) $command->getName());
        }
    }

    public function test_the_project_is_required(): void
    {
        $this->artisan('project:domain:main shop.example.com')
            ->expectsOutputToContain('--project is required.')
            ->assertExitCode(1);
    }

    public function test_another_projects_domain_is_refused(): void
    {
        $this->artisan('project:domain:main shop.example.com --project=blog')
            ->expectsOutputToContain("'shop.example.com' is not a domain of blog.")
            ->assertExitCode(1);
        $this->artisan('project:domain:https-ports shop.example.com --project=blog --ports=443')
            ->expectsOutputToContain("'shop.example.com' is not a domain of blog.")
            ->assertExitCode(1);

        $this->assertSame('shop.example.test', User::findByUsernameOrFail('shop')->domain);
    }

    public function test_an_addon_of_the_project_becomes_its_main_domain(): void
    {
        $this->artisan('project:domain:main shop.example.com --project=shop')
            ->expectsOutputToContain('shop.example.com is the main domain of shop now; shop.example.test stays as an addon domain.')
            ->expectsOutputToContain('The project has not been deployed, so nothing is redeployed.')
            ->assertExitCode(0);

        $this->assertSame('shop.example.com', User::findByUsernameOrFail('shop')->domain);
    }

    public function test_the_ports_are_shown_without_options(): void
    {
        $this->artisan('project:domain:https-ports shop.example.test --project=shop')
            ->expectsOutputToContain('shop.example.test answers HTTPS on 443; its main port is 443.')
            ->assertExitCode(0);
        $this->artisan('project:domain:https-ports shop.example.test --project=shop --ports=443,x')
            ->expectsOutputToContain('--ports takes port numbers separated by commas')
            ->assertExitCode(1);

        $this->assertSame([HttpsPorts::DEFAULT], HttpsPorts::of(Domain::query()->where('domain', 'shop.example.test')->sole()));
    }
}
