<?php

namespace Tests\Unit\System;

use App\Models\Domain;
use App\Models\ProxyRule;
use App\System;
use App\System\Services\Webserver;
use App\System\Services\Webserver\NginxProxy;
use App\System\Services\Webserver\ProxyRuleCertificate;
use App\System\Services\Webserver\RoutingCompiler;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;
use Tests\Unit\Ssl\TestCertificates;

/**
 * A wildcard rule on 443 names a certificate on the server, which certbot renews and an
 * administrator removes outside the engine. The rule kept naming it until some proxy rule
 * changed: nginx went on serving the old certificate, or failed to reload on a missing file.
 * The rules follow it now, and nginx reloads only when what a rule serves changed.
 */
class ProxyRuleCertificateRefreshTest extends TestCase
{
    use InMemoryDatabase;

    private const VHOST = '/webserver-config/nginx-proxy/vhosts/proxy-rule-443___shop_test.conf';

    private string $dir;

    private string $live;

    /** @var array{cert: string, key: \OpenSSLAsymmetricKey} */
    private array $authority;

    private ?int $now = null;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $commands;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->dir = sys_get_temp_dir().'/pa-proxy-rule-refresh-'.bin2hex(random_bytes(4));
        $this->live = $this->dir.'/letsencrypt/live';
        mkdir($this->live, 0777, true);
        $this->commands = new \ArrayObject;
        $this->authority = TestCertificates::authority();
        file_put_contents($this->dir.'/ca.pem', $this->authority['cert']);
        $this->makeMainDomain($this->makeUser('alice'), 'shop.test');
        $this->app->bind(ProxyRuleCertificate::class, fn ($app, array $parameters): ProxyRuleCertificate => new ProxyRuleCertificate(
            $parameters['system'] ?? $this->system(),
            $this->live,
            $this->dir.'/ca.pem',
            $this->now,
        ));
        ProxyRule::query()->create([
            'owner_scope' => 'system', 'transport' => 'http', 'listen_ip' => '*', 'listen_port' => 443,
            'server_name' => '*.shop.test', 'upstream_host' => TestNetwork::private(5), 'upstream_port' => 3000, 'enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_renewed_certificate_is_served_once_the_rules_are_refreshed(): void
    {
        $first = $this->lineage('wild', 30);
        $this->renderAsARebuildDoes();
        $this->assertStringContainsString('# certificate sha256 '.hash('sha256', $first['cert']), $this->vhost());

        $renewed = $this->lineage('wild', 90);

        $this->assertTrue($this->refresh());
        $this->assertStringContainsString('# certificate sha256 '.hash('sha256', $renewed['cert']), $this->vhost());
        $this->assertStringContainsString("ssl_certificate {$this->live}/wild/fullchain.pem;", $this->vhost());
        $this->assertTrue($this->reloaded());
    }

    public function test_nothing_is_rewritten_or_reloaded_while_the_certificate_stays_the_same(): void
    {
        $this->lineage('wild', 30);
        $this->renderAsARebuildDoes();
        $this->commands->exchangeArray([]);

        $this->assertFalse($this->refresh());

        $this->assertFalse($this->reloaded());
        $this->assertSame([], array_values(array_filter((array) $this->commands, static fn (string $c): bool => str_contains($c, 'rm -f'))));
    }

    public function test_a_removed_certificate_gives_way_to_another_that_covers_the_rule_then_to_the_default(): void
    {
        $this->lineage('a-wild', 200);
        $this->lineage('b-wild', 90);
        $this->renderAsARebuildDoes();
        $this->assertStringContainsString("ssl_certificate {$this->live}/a-wild/fullchain.pem;", $this->vhost());

        exec('rm -rf '.escapeshellarg("{$this->live}/a-wild"));
        $this->assertTrue($this->refresh());
        $this->assertStringContainsString("ssl_certificate {$this->live}/b-wild/fullchain.pem;", $this->vhost());

        exec('rm -rf '.escapeshellarg("{$this->live}/b-wild"));
        $this->assertTrue($this->refresh());
        $this->assertStringNotContainsString('ssl_certificate', $this->vhost());
        $this->assertStringNotContainsString('certificate sha256', $this->vhost());
        $this->assertTrue($this->reloaded());
    }

    public function test_an_expired_certificate_is_dropped(): void
    {
        $this->lineage('wild', 30);
        $this->renderAsARebuildDoes();

        $this->now = (int) strtotime('+2 years');

        $this->assertTrue($this->refresh());
        $this->assertStringNotContainsString('ssl_certificate', $this->vhost());
    }

    public function test_the_scheduled_command_says_what_it_did(): void
    {
        $this->lineage('wild', 30);
        $this->renderAsARebuildDoes();
        $this->app->instance(System::class, $this->system());
        $this->lineage('wild', 90);

        $this->artisan('proxy:rule:refresh-certificates')
            ->expectsOutput('Proxy rule certificates changed; the webserver was reloaded.')
            ->assertExitCode(0);
        $this->artisan('proxy:rule:refresh-certificates')
            ->expectsOutput('Proxy rule certificates are up to date.')
            ->assertExitCode(0);
    }

    /** @return array{cert: string, key: string} */
    private function lineage(string $name, int $days): array
    {
        $pair = TestCertificates::signedPairBy(['*.shop.test'], $this->authority, $days);
        @mkdir("{$this->live}/{$name}", 0777, true);
        file_put_contents("{$this->live}/{$name}/fullchain.pem", $pair['cert']);
        file_put_contents("{$this->live}/{$name}/privkey.pem", $pair['key']);

        return $pair;
    }

    private function refresh(): bool
    {
        return $this->system()->webserver()->refreshProxyRuleCertificates();
    }

    /** What a full rebuild renders, with every domain compiled. */
    private function renderAsARebuildDoes(): void
    {
        $proxy = new class($this->system()) extends NginxProxy
        {
            public function render(): void
            {
                $ips = $this->getAllIpsVars();
                $rules = (new RoutingCompiler($this->system))->compile(Domain::getAll())['http'];
                $this->renderProxyRuleVhosts($rules, $ips['ips_v6'] !== [], [...$ips['ips_v4'], ...$ips['ips_v6']]);
            }
        };
        $proxy->render();
    }

    private function reloaded(): bool
    {
        foreach ($this->commands as $command) {
            if (str_contains($command, '/etc/init.d/nginx reload')) {
                return true;
            }
        }

        return false;
    }

    private function vhost(): string
    {
        return (string) file_get_contents($this->dir.self::VHOST);
    }

    /** The engine under a temp dir on nginx-proxy, running what it would run with sudo as the test's own user, docker excepted. */
    private function system(): System
    {
        return new class($this->dir, $this->commands) extends System
        {
            /** @param \ArrayObject<int, string> $commands */
            public function __construct(private string $dir, private \ArrayObject $commands) {}

            public function engineDirPath(): string
            {
                return $this->dir;
            }

            public function webserver(): Webserver
            {
                return new class($this) extends Webserver
                {
                    public function getCurrentWebserver(): string
                    {
                        return 'nginx-proxy';
                    }
                };
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                $this->commands[] = $line;
                $process = match (true) {
                    str_contains($line, 'docker') || str_contains($line, 'init.d') => new Process(['true']),
                    is_array($cmd) => new Process(array_values(array_filter($cmd, static fn (string $part): bool => $part !== 'sudo'))),
                    default => Process::fromShellCommandline((string) preg_replace('/^sudo /', '', $cmd)),
                };
                $process->run();

                return $process;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $process = $this->runProcess($cmd);
                if (! $process->isSuccessful()) {
                    throw new \RuntimeException($process->getErrorOutput());
                }

                return $process->getOutput();
            }
        };
    }
}
