<?php

namespace Tests\Unit\Console;

use App\Models\ProxyRule;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordsWebserverApply;
use Tests\TestCase;

/**
 * proxy:rule:create run the way a script runs it: every value on the
 * command line, --force, no prompts. It writes the proxy_rules row, then
 * rebuilds and reloads the webserver the way the API does.
 */
class ProxyRuleCreateCommandTest extends TestCase
{
    use InMemoryDatabase;
    use RecordsWebserverApply;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->makeUser('alice');
        $this->makeUser('bob');
        $this->recordWebserverApply();
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
        $this->assertWebserverApplied(1);
    }

    public function test_a_given_listen_ip_is_stored(): void
    {
        $this->create('--scope=user --project=alice --transport=tcp --listen-ip=10.0.0.5 --listen-port=5432 --upstream-host=alice --upstream-port=5432')
            ->expectsOutputToContain('Listen: 10.0.0.5:5432')
            ->assertExitCode(0);

        $this->assertSame('10.0.0.5', ProxyRule::query()->sole()->listen_ip);
    }

    public function test_a_stream_rule_has_no_server_name_or_protocol(): void
    {
        $this->create('--scope=user --project=alice --transport=tcp --listen-port=5432 --server-name=db.test --upstream-host=alice --upstream-port=5432 --upstream-protocol=https')
            ->assertExitCode(0);

        $rule = ProxyRule::query()->sole();
        $this->assertNull($rule->server_name);
        $this->assertNull($rule->upstream_protocol);
    }

    public function test_a_system_rule_needs_no_project(): void
    {
        $this->create('--scope=system --transport=tcp --listen-port=5432 --upstream-host=db --upstream-port=5432')
            ->expectsOutputToContain('Rule created successfully')
            ->assertExitCode(0);

        $rule = ProxyRule::query()->sole();
        $this->assertSame('system', $rule->owner_scope);
        $this->assertNull($rule->username);
    }

    public function test_a_user_rule_needs_an_existing_project(): void
    {
        $args = '--transport=tcp --listen-port=5432 --upstream-host=db --upstream-port=5432';

        $this->create("--scope=user {$args}")
            ->expectsQuestion('Username (for user-owned rule)', '')
            ->expectsOutputToContain('A user-owned rule needs an existing project (--project).')
            ->assertExitCode(1);
        $this->create("--scope=user --project=nobody {$args}")
            ->expectsOutputToContain('A user-owned rule needs an existing project (--project).')
            ->assertExitCode(1);

        $this->assertSame(0, ProxyRule::query()->count());
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

    /** The values the API validates before they reach the shared nginx config verbatim. */
    public function test_values_written_into_the_proxy_config_are_validated(): void
    {
        $base = '--scope=user --project=alice --transport=http --listen-port=80 --upstream-port=80';
        $cases = [
            ["{$base} --listen-ip=10.0.0.999 --server-name=s.test --upstream-host=h", 'The listen ip must be "*" or an IP address.'],
            ["{$base} --server-name='s.test; return 200' --upstream-host=h", 'The server name must be "_" or a hostname.'],
            ["{$base} --server-name=s.test --upstream-host='h;'", 'The upstream host must be a hostname or an IP address.'],
            ["{$base} --server-name=s.test --upstream-host=h --upstream-protocol=ftp", 'The selected upstream protocol is invalid.'],
            ['--scope=user --project=alice --transport=HTTP --listen-port=80 --upstream-host=h --upstream-port=80', 'The selected transport is invalid.'],
            ['--scope=user --project=alice --transport=tcp4 --listen-port=80 --upstream-host=h --upstream-port=80', 'The selected transport is invalid.'],
        ];

        foreach ($cases as [$args, $message]) {
            $this->create($args)->expectsOutputToContain($message)->assertExitCode(1);
        }

        $this->assertSame(0, ProxyRule::query()->count());
    }

    public function test_bad_input_is_refused_before_anything_is_stored(): void
    {
        $http = '--scope=user --project=alice --transport=http --server-name=s.test';
        $cases = [
            ['--scope=global --project=alice --transport=http --listen-port=80 --upstream-host=h --upstream-port=80', [], "Scope must be 'system' or 'user'."],
            ["{$http} --listen-port=0 --upstream-host=h --upstream-port=80", [], 'Invalid port number.'],
            ["{$http} --listen-port=80 --upstream-host=h --upstream-port=0", [], 'Invalid upstream port number.'],
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
        $this->assertWebserverApplied(0);
    }

    /** A plain line and exit 1, before anything is stored or asked. */
    public function test_a_port_the_engine_or_the_host_already_uses_is_refused(): void
    {
        $this->hostListens('LISTEN 0 80 127.0.0.1:3306 0.0.0.0:*');
        $cases = [
            ['--transport=tcp --listen-port=22', 'Port 22 is reserved by the engine.'],
            ['--transport=udp --listen-port=2011', 'Port 2011 is reserved by the engine.'],
            ['--transport=tcp --listen-port=3306', 'Port 3306 is already in use on this host.'],
            ['--transport=tcp --listen-port=80', "Port 80 is the webserver's own; only an http rule can listen on it."],
        ];

        foreach ($cases as [$args, $message]) {
            $this->artisan("proxy:rule:create --no-interaction --scope=system {$args} --upstream-host=db --upstream-port=5432")
                ->expectsOutput($message)
                ->assertExitCode(1);
        }

        $this->assertSame(0, ProxyRule::query()->count());
        $this->assertWebserverApplied(0);
    }

    public function test_a_cancelled_create_stores_and_applies_nothing(): void
    {
        $this->artisan('proxy:rule:create --scope=system --transport=tcp --listen-port=5432 --upstream-host=db --upstream-port=5432')
            ->expectsConfirmation('Create this rule?', 'no')
            ->expectsOutputToContain('Cancelled.')
            ->assertExitCode(0);

        $this->assertSame(0, ProxyRule::query()->count());
        $this->assertWebserverApplied(0);
    }
}
