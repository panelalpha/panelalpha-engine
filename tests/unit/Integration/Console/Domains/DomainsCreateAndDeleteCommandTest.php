<?php

namespace Tests\Integration\Console\Domains;

use App\Models\Domain;
use App\Models\Tunnel;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** `domain:create` and `domain:delete`: their refusals, and the summary they print before a "no" leaves the domain as it was. */
class DomainsCreateAndDeleteCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $alice = $this->makeUser('alice', ['template' => 'dind']);
        $this->makeMainDomain($alice, 'alice.example.test');
        $this->makeUser('bob');
    }

    private function addon(string $name, array $details = []): Domain
    {
        $user = Domain::findByName('alice.example.test')->user;

        return Domain::query()->create([
            'user_id' => $user->id,
            'domain' => $name,
            'type' => 'addon',
            'details' => $details + ['document_root' => "/{$name}/public_html"],
        ]);
    }

    public function test_create_summarises_a_subdomain_and_a_no_creates_nothing(): void
    {
        $this->artisan('domain:create blog.alice.example.test --project=alice --type=subdomain --parent-domain=alice.example.test --alias=News.alice.example.test --alias=" " --no-ssl --proxy-to=3000')
            ->expectsOutput('Create domain')
            ->expectsOutputToContain('news.alice.example.test')
            ->expectsOutputToContain('/blog.alice.example.test/public_html')
            ->expectsOutputToContain('alice:3000')
            ->expectsConfirmation('Create this domain?', 'no')
            ->expectsOutput('Cancelled.')
            ->assertExitCode(0);

        $this->assertNull(Domain::findByName('blog.alice.example.test'));
    }

    public function test_create_refuses_what_it_cannot_create(): void
    {
        $this->artisan('domain:create one.example.test --domain=two.example.test --project=alice')
            ->expectsOutput("Conflicting domain names: argument 'one.example.test' vs --domain='two.example.test'.")
            ->assertExitCode(1);
        $this->artisan('domain:create new.example.test --project=alice --proxy-to=alice:notaport')
            ->expectsOutputToContain('notaport')
            ->assertExitCode(1);
        $this->artisan('domain:create new.example.test --project=alice --proxy-to=bob:3000')
            ->expectsOutputToContain("The upstream must be the project's own app, 'alice'")
            ->assertExitCode(1);
        $this->artisan('domain:create new.example.test --project=alice --type=sub')
            ->expectsOutput('--parent-domain is required when --type=sub.')
            ->assertExitCode(1);
        $this->artisan('domain:create new.example.test --project=alice --type=sub --parent-domain=other.example.test')
            ->expectsOutput("Parent domain 'other.example.test' not found for project 'alice'.")
            ->assertExitCode(1);
        $this->artisan('domain:create new.example.com --project=alice --type=sub --parent-domain=alice.example.test')
            ->expectsOutput("Domain 'new.example.com' must end with parent domain 'alice.example.test'.")
            ->assertExitCode(1);
        $this->artisan('domain:create alice.example.test --project=alice')
            ->expectsOutput("Domain 'alice.example.test' already exists.")
            ->assertExitCode(1);
        $this->artisan('domain:create new.example.test --project=alice --alias=bad_alias!')
            ->expectsOutput("Invalid alias 'bad_alias!'.")
            ->assertExitCode(1);

        $this->assertSame(1, Domain::query()->where('user_id', Domain::findByName('alice.example.test')->user_id)->count());
    }

    public function test_create_refuses_past_the_projects_domain_limits(): void
    {
        $alice = Domain::findByName('alice.example.test')->user;
        $alice->setAddonDomainsLimit(1);
        $alice->setSubdomainsLimit(0);
        $alice->save();
        $this->addon('shop.example.test');

        $this->artisan('domain:create more.example.test --project=alice --force')
            ->expectsOutput('Addon domains limit of 1 reached.')
            ->assertExitCode(1);
        $this->artisan('domain:create blog.alice.example.test --project=alice --type=sub --parent-domain=alice.example.test --force')
            ->expectsOutput('Subdomains limit of 0 reached.')
            ->assertExitCode(1);
    }

    public function test_delete_refuses_what_it_cannot_delete(): void
    {
        $this->addon('shop.example.test');
        Domain::query()->create(['user_id' => Domain::findByName('alice.example.test')->user_id, 'domain' => 'blog.shop.example.test', 'type' => 'sub', 'details' => ['parent_domain' => 'shop.example.test']]);

        $this->artisan('domain:delete one.example.test --domain=two.example.test --project=alice')
            ->expectsOutput("Conflicting domain names: argument 'one.example.test' vs --domain='two.example.test'.")
            ->assertExitCode(1);
        $this->artisan('domain:delete --project=alice')
            ->expectsOutput('Domain name is required (positional argument or --domain).')
            ->assertExitCode(1);
        $this->artisan('domain:delete shop.example.test')
            ->expectsOutput('--project is required.')
            ->assertExitCode(1);
        $this->artisan('domain:delete shop.example.test --project=nobody')
            ->expectsOutput("Project 'nobody' not found.")
            ->assertExitCode(1);
        $this->artisan('domain:delete shop.example.test --project=bob')
            ->expectsOutput("Domain 'shop.example.test' not found for project 'bob'.")
            ->assertExitCode(1);
        $this->artisan('domain:delete shop.example.test --project=alice --force')
            ->expectsOutput("Cannot delete domain 'shop.example.test' while subdomains still exist.")
            ->assertExitCode(1);

        $this->assertNotNull(Domain::findByName('shop.example.test'));
    }

    public function test_delete_lists_aliases_and_tunnels_and_a_no_keeps_the_domain(): void
    {
        $shop = $this->addon('shop.example.test', ['aliases' => ['www.shop.example.test']]);
        Tunnel::create(['domain_id' => $shop->id, 'user_id' => $shop->user_id, 'provider' => Tunnel::PROVIDER_CLOUDFLARE, 'hostname' => 'shop.example.com', 'details' => []]);

        $this->artisan('domain:delete --domain=shop.example.test --project=alice')
            ->expectsOutput('Delete domain')
            ->expectsOutputToContain('www.shop.example.test')
            ->expectsOutputToContain('shop.example.com (cloudflare)')
            ->expectsOutputToContain('This removes the domain, its proxy rules, nginx vhost, and any attached tunnels.')
            ->expectsConfirmation('Delete this domain?', 'no')
            ->expectsOutput('Cancelled.')
            ->assertExitCode(0);

        $this->assertNotNull(Domain::findByName('shop.example.test'));
    }
}
