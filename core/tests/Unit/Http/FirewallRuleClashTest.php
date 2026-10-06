<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Mcp\Tools\Api\Firewall\FirewallRuleCreateTool;
use App\System\Firewall\Firewall;
use App\System\Firewall\FirewallFactory;
use App\System\Firewall\FirewallRule;
use App\System\Firewall\Ufw\UfwFirewall;
use App\System\ProcessRunner;
use Laravel\Mcp\Request;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** A rule that clashes with one on the host, through the API and the MCP tool, against ufw's own provider. */
class FirewallRuleClashTest extends TestCase
{
    use InMemoryDatabase;

    /** The engine's SSH allow and an operator's rules, as /etc/ufw/user.rules holds them. */
    public const RULES = "### tuple ### allow tcp 22 0.0.0.0/0 any 0.0.0.0/0 in comment=70616e656c616c7068613a20737368\n"
        . "### tuple ### allow tcp 3306 0.0.0.0/0 any 203.0.113.7 in comment=6f6666696365\n"
        . "### tuple ### deny tcp 80,443 0.0.0.0/0 any 192.0.2.1 in\n"
        . "### tuple ### route:deny tcp 80,443 0.0.0.0/0 any 192.0.2.1 in\n";

    private object $host;
    private string $lock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->lock = (string) tempnam(sys_get_temp_dir(), 'ufw-lock');

        $this->host = new class implements ProcessRunner {
            /** @var list<list<string>> */
            public array $ufw = [];

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $cmd = (array) $cmd;
                if ($cmd[0] === 'ufw') {
                    $this->ufw[] = $cmd;

                    return FakeProcess::ok('Rule updated');
                }

                return $cmd === ['cat', '/etc/ufw/user.rules'] ? FakeProcess::ok(FirewallRuleClashTest::RULES) : FakeProcess::failed('No such file');
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function execOnHost(string|array $cmd, array $env = []): string
            {
                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return FakeProcess::ok();
            }

            public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
            {
                return FakeProcess::ok();
            }
        };
        $firewall = new UfwFirewall($this->host, $this->lock);
        FirewallFactory::register('ufw-on-a-fake-host', fn (): Firewall => $firewall);
        config(['env.FIREWALL_PROVIDER' => 'ufw-on-a-fake-host']);
    }

    protected function tearDown(): void
    {
        FirewallFactory::reset();
        @unlink($this->lock);
        parent::tearDown();
    }

    private function sshId(): string
    {
        return FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22'])->id();
    }

    public function test_the_api_refuses_a_duplicate_of_an_engine_rule_naming_it(): void
    {
        $response = $this->postJson('/api/firewall/rules', ['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'comment' => 'qa211-fw09-dup-ssh']);

        $response->assertStatus(422);
        $this->assertSame(
            ["Rule {$this->sshId()} (allow in tcp 22) already matches this traffic and is left as it is; the engine opened it and needs it."],
            $response->json('errors.rule')
        );
        $this->assertSame([], $this->host->ufw);
        $this->getJson('/api/firewall/rules')->assertOk()
            ->assertJsonPath('data.0.comment', 'panelalpha: ssh')->assertJsonPath('data.0.managed', true);
    }

    public function test_the_api_refuses_an_edit_onto_another_rules_match(): void
    {
        $office = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '3306', 'source' => '203.0.113.7']);

        $response = $this->putJson("/api/firewall/rules/{$office->id()}", ['port' => '22', 'source' => null]);

        $response->assertStatus(422);
        $this->assertStringStartsWith("Rule {$this->sshId()} ", (string) $response->json('errors.rule.0'));
        $this->assertSame([], $this->host->ufw);
    }

    public function test_an_address_that_is_every_address_is_refused_before_ufw_is_asked(): void
    {
        $office = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '3306', 'source' => '203.0.113.7']);
        $cases = [
            ['POST', ['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '0.0.0.0/0', 'comment' => 'x'], 'source'],
            ['POST', ['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '::/0', 'comment' => 'x'], 'source'],
            ['POST', ['action' => 'allow', 'protocol' => 'tcp', 'port' => '2011', 'source' => '1.2.3.4/0', 'scope' => 'published'], 'source'],
            ['POST', ['action' => 'deny', 'protocol' => 'tcp', 'port' => '22', 'destination' => '0.0.0.0/0'], 'destination'],
            ['POST', ['action' => 'deny', 'source' => '0.0.0.0/0'], 'source'],
            ['PUT', ['port' => '22', 'source' => '0.0.0.0/0'], 'source'],
        ];
        foreach ($cases as [$method, $body, $field]) {
            $uri = $method === 'PUT' ? "/api/firewall/rules/{$office->id()}" : '/api/firewall/rules';
            $this->json($method, $uri, $body)->assertStatus(422)->assertJsonValidationErrors([$field]);
        }
        $response = (new FirewallRuleCreateTool())->handle(new Request(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => '::/0']));
        $this->assertTrue($response->isError());
        $payload = json_decode((string) $response->content(), true);
        $this->assertSame(422, $payload['status']);
        $this->assertArrayHasKey('source', $payload['data']['errors']);

        $this->assertSame([], $this->host->ufw);
        $this->getJson('/api/firewall/rules')->assertOk()
            ->assertJsonPath('data.0.comment', 'panelalpha: ssh')->assertJsonPath('data.0.managed', true);
    }

    public function test_a_port_list_in_another_order_is_the_rule_already_there(): void
    {
        $deny = FirewallRule::fromArray(['action' => 'deny', 'protocol' => 'tcp', 'port' => '80,443', 'source' => '192.0.2.1']);

        foreach (['allow', 'deny'] as $action) {
            $response = $this->postJson('/api/firewall/rules', ['action' => $action, 'protocol' => 'tcp', 'port' => '443,80', 'source' => '192.0.2.1']);

            $response->assertStatus(422);
            $this->assertStringStartsWith("Rule {$deny->id()} ", (string) $response->json('errors.rule.0'));
        }
        $this->assertSame([], $this->host->ufw);
    }

    public function test_the_mcp_tool_reports_the_refusal_as_a_tool_error(): void
    {
        $response = (new FirewallRuleCreateTool())->handle(new Request(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'comment' => 'qa211-fw09-dup-ssh']));

        $this->assertTrue($response->isError());
        $payload = json_decode((string) $response->content(), true);
        $this->assertSame(422, $payload['status']);
        $this->assertStringStartsWith("Rule {$this->sshId()} ", $payload['data']['errors']['rule'][0]);
        $this->assertSame([], $this->host->ufw);
    }
}
