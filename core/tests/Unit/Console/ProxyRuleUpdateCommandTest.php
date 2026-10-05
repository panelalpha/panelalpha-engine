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
        $this->makeUser('alice');
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
        $this->update('--upstream-host=10.0.0.9 --upstream-port=8443 --upstream-protocol=https')
            ->expectsOutput('New values:')
            ->doesntExpectOutputToContain('\\n')
            ->expectsOutputToContain('Rule updated successfully')
            ->assertExitCode(0);

        $rule = $this->rule->fresh();
        $this->assertSame(['10.0.0.9', 8443, 'https'], [$rule->upstream_host, $rule->upstream_port, $rule->upstream_protocol]);
        $this->assertWebserverApplied(1);
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
