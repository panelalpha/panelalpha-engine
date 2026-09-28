<?php

namespace Tests\Unit\Console;

use App\Models\ProxyRule;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * proxy:rule:create run the way a script runs it: every value on the
 * command line, --force, no prompts. It only writes the proxy_rules row.
 */
class ProxyRuleCreateCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->makeUser('alice');
        $this->makeUser('bob');
    }

    private function create(string $args): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('proxy:rule:create --no-interaction --force ' . $args);
    }

    public function test_an_http_rule_is_stored_as_given(): void
    {
        $this->create('--scope=user --project=alice --transport=http --listen-port=443 --server-name=shop.test --upstream-host=alice --upstream-port=8080 --upstream-protocol=https')
            ->expectsOutputToContain('Listen: *:443 [shop.test]')
            ->expectsOutputToContain('Upstream: alice:8080 (https)')
            ->expectsOutputToContain('Rule created successfully')
            ->assertExitCode(0);

        $rule = ProxyRule::query()->sole();
        $this->assertSame(
            ['user', 'alice', 'http', '*', 443, 'shop.test', 'alice', 8080, 'https', true, false, ['created_via' => 'artisan-command']],
            [$rule->owner_scope, $rule->username, $rule->transport, $rule->listen_ip, $rule->listen_port, $rule->server_name,
             $rule->upstream_host, $rule->upstream_port, $rule->upstream_protocol, (bool) $rule->enabled, (bool) $rule->is_generated, $rule->metadata]
        );
    }

    public function test_a_stream_rule_has_no_server_name_or_protocol(): void
    {
        $this->create('--scope=user --project=alice --transport=tcp --listen-port=5432 --server-name=db.test --upstream-host=alice --upstream-port=5432 --upstream-protocol=https')
            ->assertExitCode(0);

        $rule = ProxyRule::query()->sole();
        $this->assertNull($rule->server_name);
        $this->assertNull($rule->upstream_protocol);
    }

    public function test_an_http_rule_with_no_server_name_is_a_wildcard(): void
    {
        $this->create('--scope=user --project=alice --transport=http --listen-port=80 --upstream-host=alice --upstream-port=8080')
            ->expectsQuestion('Server name/hostname (or leave blank for wildcard)', '')
            ->assertExitCode(0);

        $this->assertNull(ProxyRule::query()->sole()->server_name);
    }

    /** A duplicate is judged per owner: the same listener for another project is a separate rule. */
    public function test_a_duplicate_is_refused_for_the_same_owner_only(): void
    {
        $args = '--transport=http --listen-port=443 --server-name=shop.test --upstream-host=h --upstream-port=8080';

        $this->create("--scope=user --project=alice {$args}")->assertExitCode(0);
        $this->create("--scope=user --project=alice {$args}")
            ->expectsOutputToContain('A rule with this configuration already exists.')
            ->assertExitCode(1);
        $this->create("--scope=user --project=bob {$args}")->assertExitCode(0);

        $this->assertSame(2, ProxyRule::query()->count());
    }

    public function test_bad_input_is_refused_before_anything_is_stored(): void
    {
        $http = '--scope=user --project=alice --transport=http --server-name=s.test';
        $cases = [
            ['--scope=global --project=alice --transport=http --listen-port=80 --upstream-host=h --upstream-port=80', [], "Scope must be 'system' or 'user'."],
            // 0 is falsy, so it is asked for rather than refused outright.
            ["{$http} --listen-port=0 --upstream-host=h --upstream-port=80", ['Listen port (1-65535)' => '0'], 'Invalid port number.'],
            ["{$http} --listen-port=http --upstream-host=h --upstream-port=80", [], 'Invalid port number.'],
            ["{$http} --listen-port=80 --upstream-port=80", ['Upstream host' => ''], 'Invalid upstream host.'],
            ["{$http} --listen-port=80 --upstream-host=h --upstream-port=70000", [], 'Invalid upstream port number.'],
        ];

        foreach ($cases as [$args, $questions, $message]) {
            $run = $this->create($args);
            foreach ($questions as $question => $answer) {
                $run->expectsQuestion($question, $answer);
            }
            $run->expectsOutputToContain($message)->assertExitCode(1);
        }

        $this->assertSame(0, ProxyRule::query()->count());
    }
}
