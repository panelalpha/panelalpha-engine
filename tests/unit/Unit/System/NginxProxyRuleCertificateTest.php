<?php

namespace Tests\Unit\System;

use App\Models\ProxyRule;
use App\System;
use App\System\Services\Webserver\NginxProxy;
use App\System\Services\Webserver\ProxyRuleCertificate;
use App\System\Services\Webserver\ProxyRuleServerName;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;
use Tests\Unit\Ssl\TestCertificates;

/**
 * A proxy rule on 443 that no domain or alias is named after got the host's default certificate
 * even when the server held a trusted one for its name, `*.shop.test` say. It is served with the
 * engine's own certificate or a Let's Encrypt lineage now, when that is publicly trusted and covers
 * the name; never with a certificate a project installed on its domain, which names what it likes.
 */
class NginxProxyRuleCertificateTest extends TestCase
{
    use InMemoryDatabase;

    private string $dir;

    /** @var array{cert: string, key: \OpenSSLAsymmetricKey} */
    private array $authority;

    private ?int $now = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->dir = sys_get_temp_dir().'/pa-proxy-rule-certs-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/vhosts', 0777, true);
        mkdir($this->dir.'/letsencrypt/live', 0777, true);
        $this->authority = TestCertificates::authority();
        file_put_contents($this->dir.'/ca.pem', $this->authority['cert']);
        $this->makeMainDomain($this->makeUser('alice'), 'shop.test');
        $this->app->instance(System::class, $this->system());
        $this->app->bind(ProxyRuleCertificate::class, fn ($app, array $parameters): ProxyRuleCertificate => new ProxyRuleCertificate(
            $parameters['system'] ?? $this->system(),
            $this->dir.'/letsencrypt/live',
            $this->dir.'/ca.pem',
            $this->now,
        ));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_wildcard_rule_on_443_is_served_with_the_trusted_lineage_that_covers_it(): void
    {
        $this->lineage('wild', ['*.shop.test']);

        $conf = $this->render([$this->rule(3, 443, '*.shop.test')])['proxy-rule-443___shop_test.conf'];

        $lineage = $this->dir.'/letsencrypt/live/wild';
        $this->assertStringContainsString('listen 443 ssl;', $conf);
        $this->assertStringContainsString("ssl_certificate {$lineage}/fullchain.pem;", $conf);
        $this->assertStringContainsString("ssl_certificate_key {$lineage}/privkey.pem;", $conf);
    }

    public function test_the_engines_own_trusted_certificate_counts_too(): void
    {
        $pair = TestCertificates::signedPairBy(['shop.test', '*.shop.test'], $this->authority);
        $this->write($this->dir.'/crt/server.cert', $pair['cert']);
        $this->write($this->dir.'/crt/server.key', $pair['key']);

        $conf = $this->render([$this->rule(3, 443, '*.shop.test')])['proxy-rule-443___shop_test.conf'];

        $this->assertStringContainsString("ssl_certificate {$this->dir}/crt/server.cert;", $conf);
    }

    public function test_a_certificate_a_project_installed_on_its_domain_is_never_used(): void
    {
        foreach ([TestCertificates::selfSigned(['*.shop.test']), TestCertificates::signedPairBy(['*.shop.test'], $this->authority)] as $pair) {
            $this->write($this->dir.'/users/alice/ssl-certs/shop.test.pem', $pair['cert']);
            $this->write($this->dir.'/users/alice/ssl-certs/shop.test.key', $pair['key']);

            $conf = $this->render([$this->rule(3, 443, '*.shop.test')])['proxy-rule-443___shop_test.conf'];

            $this->assertStringNotContainsString('ssl_certificate', $conf);
            $this->assertNotNull(ProxyRuleServerName::defaultCertificateWarning($this->storedRule('*.shop.test')));
        }
    }

    public function test_a_self_signed_or_otherwise_untrusted_lineage_is_ignored(): void
    {
        $this->lineage('self-signed', ['*.shop.test'], TestCertificates::selfSigned(['*.shop.test']));
        $this->lineage('other-ca', ['*.shop.test'], TestCertificates::signedPairBy(['*.shop.test'], TestCertificates::authority('Unknown CA')));

        $this->assertNull($this->certificates()->covering(['*.shop.test']));
    }

    public function test_the_most_specific_then_the_latest_expiring_certificate_wins(): void
    {
        $this->lineage('a-wild', ['*.shop.test'], TestCertificates::signedPairBy(['*.shop.test'], $this->authority, 90));
        $this->lineage('b-exact', ['api.shop.test'], TestCertificates::signedPairBy(['api.shop.test'], $this->authority, 30));
        $this->lineage('c-wild-longer', ['*.shop.test'], TestCertificates::signedPairBy(['*.shop.test'], $this->authority, 200));
        $live = $this->dir.'/letsencrypt/live';

        $this->assertSame("{$live}/b-exact/fullchain.pem", $this->certificates()->covering(['api.shop.test'])['pem'] ?? null);
        $this->assertSame("{$live}/c-wild-longer/fullchain.pem", $this->certificates()->covering(['*.shop.test'])['pem'] ?? null);
        $this->assertSame("{$live}/c-wild-longer/fullchain.pem", $this->certificates()->covering(['www.shop.test'])['pem'] ?? null);
    }

    public function test_an_expired_certificate_or_one_whose_key_does_not_match_does_not_count(): void
    {
        $this->lineage('wild', ['*.shop.test']);
        $this->now = (int) strtotime('+2 years');
        $this->assertNull($this->certificates()->covering(['*.shop.test']));

        $this->now = null;
        $this->write($this->dir.'/letsencrypt/live/wild/privkey.pem', TestCertificates::selfSigned(['other.test'])['key']);
        $this->assertNull($this->certificates()->covering(['*.shop.test']));
    }

    public function test_a_dotted_name_needs_the_domain_and_every_name_under_it(): void
    {
        $this->lineage('wild', ['*.shop.test']);
        $this->assertNull($this->certificates()->covering(['.shop.test']));

        $this->lineage('both', ['shop.test', '*.shop.test']);
        $this->assertSame($this->dir.'/letsencrypt/live/both/fullchain.pem', $this->certificates()->covering(['.shop.test'])['pem'] ?? null);
    }

    public function test_a_rule_off_443_or_without_a_name_never_takes_one(): void
    {
        $this->lineage('wild', ['*.shop.test']);

        $files = $this->render([$this->rule(4, 443, null), $this->rule(5, 8443, '*.shop.test')]);

        $this->assertCount(2, $files);
        foreach ($files as $conf) {
            $this->assertStringNotContainsString('ssl_certificate', $conf);
        }
    }

    public function test_the_default_certificate_warning_is_given_only_without_a_trusted_covering_certificate(): void
    {
        $this->assertSame(
            'No domain or alias on this engine is named *.shop.test and no trusted certificate on the server covers it, so this rule '
            ."on port 443 is served with the host's default self-signed certificate and clients will warn. Name a container "
            ."project's domain, or put a publicly trusted certificate for *.shop.test in /etc/letsencrypt/live on the server.",
            ProxyRuleServerName::defaultCertificateWarning($this->storedRule('*.shop.test'))
        );

        $this->lineage('wild', ['*.shop.test']);
        $this->assertNull(ProxyRuleServerName::defaultCertificateWarning($this->storedRule('*.shop.test')));
    }

    /**
     * @param  list<string>  $names
     * @param  ?array{cert: string, key: string}  $pair
     */
    private function lineage(string $name, array $names, ?array $pair = null): void
    {
        $pair ??= TestCertificates::signedPairBy($names, $this->authority);
        $this->write($this->dir."/letsencrypt/live/{$name}/fullchain.pem", $pair['cert']);
        $this->write($this->dir."/letsencrypt/live/{$name}/privkey.pem", $pair['key']);
    }

    private function write(string $path, string $contents): void
    {
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $contents);
    }

    private function certificates(): ProxyRuleCertificate
    {
        return app(ProxyRuleCertificate::class);
    }

    private function storedRule(string $serverName): ProxyRule
    {
        return new ProxyRule(['enabled' => true, 'transport' => 'http', 'listen_port' => 443, 'server_name' => $serverName]);
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @return array<string, string> rendered vhost by file name
     */
    private function render(array $rules): array
    {
        $proxy = new class($this->system()) extends NginxProxy
        {
            public function domainsConfigsDirPath(): string
            {
                return $this->system->engineDirPath().'/vhosts';
            }

            /** @param list<array<string, mixed>> $rules */
            public function render(array $rules): void
            {
                $this->renderProxyRuleVhosts($rules, false);
            }
        };
        $proxy->render($rules);

        $files = [];
        foreach (glob($this->dir.'/vhosts/proxy-rule-*.conf') ?: [] as $path) {
            $files[basename($path)] = (string) file_get_contents($path);
        }

        return $files;
    }

    /** The engine under a temp dir, running what it would run with sudo as the test's own user. */
    private function system(): System
    {
        return new class($this->dir) extends System
        {
            public function __construct(private string $dir) {}

            public function engineDirPath(): string
            {
                return $this->dir;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $process = is_array($cmd)
                    ? new Process(array_values(array_filter($cmd, static fn (string $part): bool => $part !== 'sudo')))
                    : Process::fromShellCommandline((string) preg_replace('/^sudo /', '', $cmd));
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

    /** @return array<string, mixed> */
    private function rule(int $id, int $port, ?string $serverName): array
    {
        return [
            'id' => $id,
            'transport' => 'http',
            'listen_ip' => '*',
            'listen_port' => $port,
            'server_name' => $serverName ?? '_',
            'server_names' => [],
            'upstream_host' => '10.0.0.5',
            'upstream_port' => 3000,
            'upstream_protocol' => 'http',
            'is_generated' => false,
        ];
    }
}
