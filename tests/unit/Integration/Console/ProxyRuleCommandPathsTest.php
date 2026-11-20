<?php

namespace Tests\Integration\Console;

use App\Models\ProxyRule;
use Laravel\Prompts\Prompt;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordsWebserverApply;
use Tests\TestCase;

/**
 * `proxy:rule:list` filters, and the refusals of `proxy:rule:update` and `:delete`: each says why and changes
 * nothing.
 */
class ProxyRuleCommandPathsTest extends TestCase
{
    use InMemoryDatabase;
    use RecordsWebserverApply;

    private ProxyRule $site;

    private ProxyRule $system;

    protected function setUp(): void
    {
        parent::setUp();
        (new \ReflectionProperty(Prompt::class, 'shouldFallback'))->setValue(null, false);
        $this->bootInMemoryDatabase();
        $this->recordWebserverApply();
        $this->makeMainDomain($this->makeUser('alice', ['template' => 'dind']), 'shop.test');
        $this->makeUser('bob', ['template' => 'dind']);
        $this->site = $this->rule(['owner_scope' => 'user', 'username' => 'alice', 'transport' => 'http', 'listen_port' => 80, 'server_name' => 'shop.test', 'upstream_host' => 'alice', 'upstream_port' => 8080]);
        $this->rule(['owner_scope' => 'user', 'username' => 'bob', 'transport' => 'tcp', 'listen_port' => 2222, 'upstream_host' => 'bob', 'upstream_port' => 22, 'enabled' => false]);
        $this->system = $this->rule(['owner_scope' => 'system', 'transport' => 'tcp', 'listen_port' => 25212, 'upstream_host' => 'db', 'upstream_port' => 5432]);
    }

    protected function tearDown(): void
    {
        Prompt::interactive(false);
        parent::tearDown();
    }

    /** @param array<string, mixed> $attributes */
    private function rule(array $attributes): ProxyRule
    {
        return ProxyRule::query()->create($attributes + ['listen_ip' => '*', 'upstream_protocol' => 'http', 'enabled' => true]);
    }

    public function test_the_listing_filters_by_scope_and_project(): void
    {
        $this->artisan('proxy:rule:list --scope=system')
            ->expectsOutputToContain('db:5432')
            ->doesntExpectOutputToContain('alice:8080')
            ->assertExitCode(0);
        $this->artisan('proxy:rule:list --scope=user')
            ->expectsOutputToContain('alice:8080')
            ->expectsOutputToContain('bob:22')
            ->doesntExpectOutputToContain('db:5432')
            ->assertExitCode(0);
        $this->artisan('proxy:rule:list --scope=user --project=bob')
            ->expectsOutputToContain('bob:22')
            ->doesntExpectOutputToContain('alice:8080')
            ->assertExitCode(0);
        $this->artisan('proxy:rule:list --project=alice')
            ->expectsOutputToContain('shop.test')
            ->doesntExpectOutputToContain('bob:22')
            ->doesntExpectOutputToContain('db:5432')
            ->assertExitCode(0);
    }

    public function test_an_empty_listing_says_so(): void
    {
        $this->artisan('proxy:rule:list --scope=user --project=nobody')
            ->expectsOutput('No proxy rules found.')
            ->assertExitCode(0);
    }

    public function test_an_update_of_an_unknown_rule_or_with_nothing_to_change_is_refused(): void
    {
        $this->artisan('proxy:rule:update 999999 --force --upstream-port=81')
            ->expectsOutput('Rule with ID 999999 not found.')
            ->assertExitCode(1);
        $this->artisan("proxy:rule:update {$this->site->id} --force --enabled=yes")
            ->expectsOutput('No updates provided.')
            ->assertExitCode(1);

        $this->assertWebserverApplied(0);
    }

    public function test_an_upstream_port_outside_the_range_is_refused(): void
    {
        foreach (['65536', '-5'] as $port) {
            $this->artisan("proxy:rule:update {$this->site->id} --force --upstream-port={$port}")
                ->expectsOutput('Invalid port number.')
                ->assertExitCode(1);
        }

        $this->assertSame(8080, $this->site->fresh()->upstream_port);
        $this->assertWebserverApplied(0);
    }

    public function test_an_update_declined_at_the_prompt_changes_nothing(): void
    {
        $this->artisan("proxy:rule:update {$this->site->id} --upstream-port=8081")
            ->expectsConfirmation('Apply these changes?', 'no')
            ->expectsOutput('Cancelled.')
            ->assertExitCode(0);

        $this->assertSame(8080, $this->site->fresh()->upstream_port);
        $this->assertWebserverApplied(0);
    }

    public function test_deleting_a_site_rule_shows_its_server_name(): void
    {
        $this->artisan("proxy:rule:delete {$this->site->id} --force")
            ->expectsOutput('  Server Name: shop.test')
            ->expectsOutputToContain('Rule deleted successfully.')
            ->assertExitCode(0);

        $this->assertNull(ProxyRule::query()->find($this->site->id));
        $this->assertNotNull(ProxyRule::query()->find($this->system->id));
    }
}
