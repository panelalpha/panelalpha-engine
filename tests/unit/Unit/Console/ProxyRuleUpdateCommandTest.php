<?php

namespace Tests\Unit\Console;

use App\Models\ProxyRule;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordsWebserverApply;
use Tests\TestCase;

/**
 * proxy:rule:update run the way a script runs it: --force, no prompts.
 * It writes the proxy_rules row, then rebuilds and reloads the webserver
 * the way the API does.
 */
class ProxyRuleUpdateCommandTest extends TestCase
{
    use InMemoryDatabase;
    use RecordsWebserverApply;

    private ProxyRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->recordWebserverApply();
        $this->makeMainDomain($this->makeUser('alice'), 'shop.test');
        $this->rule = ProxyRule::query()->create([
            'owner_scope' => 'user',
            'username' => 'alice',
            'transport' => 'http',
            'listen_ip' => '*',
            'listen_port' => 80,
            'server_name' => 'shop.test',
            'upstream_host' => 'alice',
            'upstream_port' => 8080,
            'upstream_protocol' => 'http',
            'enabled' => true,
        ]);
    }

    private function update(string $args): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan("proxy:rule:update {$this->rule->id} --no-interaction --force {$args}");
    }

    public function test_valid_values_are_stored(): void
    {
        $this->update('--upstream-host=alice --upstream-port=8443 --upstream-protocol=https')
            ->expectsOutput('New values:')
            ->doesntExpectOutputToContain('\\n')
            ->expectsOutputToContain('Rule updated successfully')
            ->assertExitCode(0);

        $rule = $this->rule->fresh();
        $this->assertSame(['alice', 8443, 'https'], [$rule->upstream_host, $rule->upstream_port, $rule->upstream_protocol]);
        $this->assertWebserverApplied(1);
    }

    /** A project's rule reaches its own app only; the operator's may point anywhere. */
    public function test_a_project_rule_cannot_be_pointed_elsewhere(): void
    {
        $this->makeUser('bob');
        foreach (['bob', 'sites-db', '172.25.0.2', '10.0.0.9'] as $upstream) {
            $this->update("--upstream-host={$upstream}")
                ->expectsOutput("The upstream must be the project's own app, 'alice': "
                    . "a project's rule cannot reach another project, the engine's services or the host.")
                ->assertExitCode(1);
        }
        $this->assertSame('alice', $this->rule->fresh()->upstream_host);
        $this->assertWebserverApplied(0);

        $this->rule->update(['owner_scope' => 'system', 'username' => null]);
        $this->update('--upstream-host=10.0.0.9')->assertExitCode(0);
        $this->assertSame('10.0.0.9', $this->rule->fresh()->upstream_host);
    }

    /** One stored before the check can be switched off or fixed, and nothing else. */
    public function test_a_rule_stored_before_the_check_is_fixed_or_switched_off(): void
    {
        $this->rule->update(['upstream_host' => '172.25.0.2']);

        $this->update('--upstream-port=3307')->assertExitCode(1);
        $this->update('--enabled=0')->assertExitCode(0);
        $this->update('--enabled=1')->assertExitCode(1);
        $this->update('--upstream-host=alice --enabled=1')->assertExitCode(0);

        $this->assertSame(['alice', true], [$this->rule->fresh()->upstream_host, $this->rule->fresh()->enabled]);
    }

    /** A rule named after another project's site cannot be renamed here, so it can only be switched off. */
    public function test_a_rule_on_another_projects_site_can_only_be_switched_off(): void
    {
        $this->makeMainDomain($this->makeUser('bob'), 'bob.test');
        $this->rule->update(['server_name' => 'bob.test']);

        $this->update('--upstream-port=8081')
            ->expectsOutput("The server name must be empty or one of the project's own domains or aliases: "
                . "a project's rule cannot answer for another project's site.")
            ->assertExitCode(1);
        $this->update('--enabled=0')->assertExitCode(0);
        $this->update('--enabled=1')->assertExitCode(1);

        $this->assertSame([8080, false], [$this->rule->fresh()->upstream_port, $this->rule->fresh()->enabled]);
    }

    public function test_disabling_a_rule_is_applied(): void
    {
        $this->update('--enabled=0')
            ->expectsOutput('  Enabled: No')
            ->assertExitCode(0);

        $this->assertFalse($this->rule->fresh()->enabled);
        $this->assertWebserverApplied(1);
    }

    public function test_enabling_a_rule_prints_yes_as_the_current_values_do(): void
    {
        $this->rule->update(['enabled' => false]);

        $this->update('--enabled=1')
            ->expectsOutput('  Enabled: Yes')
            ->assertExitCode(0);

        $this->assertTrue($this->rule->fresh()->enabled);
    }

    public function test_enabling_a_rule_on_a_port_now_in_use_is_refused(): void
    {
        $this->rule->update(['enabled' => false, 'listen_port' => 3306]);
        $this->hostListens('LISTEN 0 80 127.0.0.1:3306 0.0.0.0:*');

        $this->update('--enabled=1')
            ->expectsOutput('Port 3306 is already in use on this host.')
            ->assertExitCode(1);

        $this->assertFalse($this->rule->fresh()->enabled);
        $this->assertWebserverApplied(0);
    }

    /** An enabled rule's port is bound by nginx for that rule; that is no reason to refuse it. */
    public function test_an_enabled_rule_keeps_its_own_port(): void
    {
        $this->rule->update(['listen_port' => 8081]);
        $this->hostListens('LISTEN 0 511 0.0.0.0:8081 0.0.0.0:*');

        $this->update('--upstream-port=8082 --enabled=1')->assertExitCode(0);

        $this->assertSame(8082, $this->rule->fresh()->upstream_port);
        $this->assertWebserverApplied(1);
    }

    /** The values the API's update validates before they reach the shared nginx config verbatim. */
    public function test_values_written_into_the_proxy_config_are_validated(): void
    {
        $cases = [
            ["--upstream-host='10.0.0.9; return 200'", 'The upstream host must be a hostname or an IP address.'],
            ["--upstream-host='h b'", 'The upstream host must be a hostname or an IP address.'],
            ['--upstream-protocol=grpc', 'The selected upstream protocol is invalid.'],
            ['--upstream-protocol=HTTPS', 'The selected upstream protocol is invalid.'],
            ['--upstream-host=good --upstream-protocol=ftp', 'The selected upstream protocol is invalid.'],
        ];

        foreach ($cases as [$args, $message]) {
            $this->update($args)->expectsOutputToContain($message)->assertExitCode(1);
        }

        $rule = $this->rule->fresh();
        $this->assertSame(['alice', 'http'], [$rule->upstream_host, $rule->upstream_protocol]);
        $this->assertWebserverApplied(0);
    }
}
