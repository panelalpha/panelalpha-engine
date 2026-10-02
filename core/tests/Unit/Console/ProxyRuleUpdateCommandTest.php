<?php

namespace Tests\Unit\Console;

use App\Models\ProxyRule;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * proxy:rule:update run the way a script runs it: --force, no prompts.
 * It only writes the proxy_rules row.
 */
class ProxyRuleUpdateCommandTest extends TestCase
{
    use InMemoryDatabase;

    private ProxyRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
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
            ->expectsOutputToContain('Rule updated successfully')
            ->assertExitCode(0);

        $rule = $this->rule->fresh();
        $this->assertSame(['10.0.0.9', 8443, 'https'], [$rule->upstream_host, $rule->upstream_port, $rule->upstream_protocol]);
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
    }
}
