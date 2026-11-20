<?php

namespace Tests\Integration\System\Services\Webserver;

use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\Setting;
use App\System;
use App\System\Filesystem;
use App\System\Privileged\RootEngineServices;
use App\System\Services\Webserver\Litespeed;
use App\System\Services\Webserver\Openlitespeed;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Integration\System\Webserver\WebserverHostDouble;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * The LiteSpeed pair: a domain change regenerates the one server config, the details read the running
 * version and the stored serial, and a serial the webserver rejects comes back as a validation error.
 */
class LiteSpeedDriverOperationsTest extends TestCase
{
    use InMemoryDatabase;

    private const LS_CONFIG = '/opt/engine/webserver-config/litespeed/httpd_config.xml';

    private const OLS_CONFIG = '/opt/engine/webserver-config/openlitespeed/httpd_config.conf';

    private WebserverHostDouble $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings(['default_ipv4' => TestNetwork::DOCUMENTATION_2]);
        config([
            'env.APP_LITE_PROXY_ENABLED' => false, 'env.APP_LITE_CA_PORT' => null, 'env.APP_LITE_AA_PORT' => null,
            'env.APP_LITE_CA_DOMAIN' => null, 'env.APP_LITE_AA_DOMAIN' => null,
        ]);
        $this->host = new WebserverHostDouble(dirname(base_path()).'/templates');
        $this->host->files[self::LS_CONFIG] = self::litespeedConfig();
        $this->host->files[self::OLS_CONFIG] = "serverName engine\n\nvirtualhost old {\n  vhRoot /old\n}\n";
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_each_domain_change_regenerates_the_litespeed_config_with_the_domain(): void
    {
        $domain = $this->makeMainDomain($this->makeUser('alice', [], 'alice.test'), 'alice.test');

        foreach (['addDomain', 'createDomainConfig', 'rebuildDomainConfig'] as $method) {
            $this->host->files[self::LS_CONFIG] = self::litespeedConfig();
            (new Litespeed($this->host))->{$method}($domain);

            $this->assertStringContainsString('<name>alice.test</name>', $this->host->files[self::LS_CONFIG], $method);
            $this->assertStringNotContainsString('<name>old</name>', $this->host->files[self::LS_CONFIG], $method);
        }
    }

    public function test_an_added_domain_regenerates_the_openlitespeed_config_with_the_domain(): void
    {
        $domain = $this->makeMainDomain($this->makeUser('alice', [], 'alice.test'), 'alice.test');

        (new Openlitespeed($this->host))->addDomain($domain);

        $this->assertStringContainsString('virtualhost alice.test', $this->host->files[self::OLS_CONFIG]);
        $this->assertStringNotContainsString('virtualhost old', $this->host->files[self::OLS_CONFIG]);
    }

    public function test_the_app_proxy_without_a_client_area_domain_answers_every_name(): void
    {
        config([
            'env.APP_LITE_PROXY_ENABLED' => true, 'env.APP_LITE_AA_DOMAIN' => 'admin.example.test',
            'env.APP_LITE_CA_PORT' => 8443, 'env.APP_LITE_AA_PORT' => 9443,
            'env.APP_LITE_PROXY_CA_HOST' => 'app-lite-ca', 'env.APP_LITE_PROXY_CA_PORT' => 8080,
            'env.APP_LITE_PROXY_AA_HOST' => 'app-lite-aa', 'env.APP_LITE_PROXY_AA_PORT' => 8081,
        ]);

        (new Litespeed($this->host))->rebuildConfig([]);

        $config = $this->host->files[self::LS_CONFIG];
        $this->assertStringContainsString('<domain>*</domain>', $config);
        $this->assertStringContainsString('<domain>admin.example.test</domain>', $config);
    }

    public function test_a_litespeed_config_with_an_unclosed_node_is_refused(): void
    {
        $this->host->files[self::LS_CONFIG] = "<httpServerConfig>\n  <listenerList>\n    <listener><name>old</name></listener>\n</httpServerConfig>\n";

        $this->expectExceptionMessage('Cannot find closing tag for XML node');

        (new Litespeed($this->host))->rebuildConfig([]);
    }

    public function test_an_openlitespeed_block_name_that_opens_no_block_is_skipped(): void
    {
        Setting::setRuntimeSettings(['default_ipv4' => TestNetwork::DOCUMENTATION_2, 'modsec' => json_encode(['mode' => 'off', 'enabled_rulesets' => []])]);
        $this->host->files[self::OLS_CONFIG] = "module mod_security }module mod_security {\n  modsecurity on\n}\n";

        (new Openlitespeed($this->host))->toggleModsecurity();

        $this->assertSame("module mod_security }\n", $this->host->files[self::OLS_CONFIG]);
    }

    public function test_an_openlitespeed_block_without_its_closing_brace_is_refused(): void
    {
        Setting::setRuntimeSettings(['default_ipv4' => TestNetwork::DOCUMENTATION_2, 'modsec' => json_encode(['mode' => 'off', 'enabled_rulesets' => []])]);
        $this->host->files[self::OLS_CONFIG] = "module mod_security {\n  modsecurity on\n";

        $this->expectExceptionMessage('Cannot find closing tag for module mod_security block');

        (new Openlitespeed($this->host))->toggleModsecurity();
    }

    public function test_openlitespeed_lists_the_domains_it_has_a_vhost_config_for(): void
    {
        $engine = sys_get_temp_dir().'/pa-ols-vhosts-'.bin2hex(random_bytes(4));
        $vhosts = $engine.'/webserver-config/openlitespeed/vhosts';
        mkdir($vhosts, 0777, true);
        foreach (['alice.test.conf', 'bob.test.conf', 'notes.txt'] as $file) {
            file_put_contents("{$vhosts}/{$file}", '');
        }
        $host = new class ($engine) extends System {
            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null
            ): Process {
                throw new \LogicException('No process may start from this test.');
            }

            public function __construct(private string $engine) {}

            public function engineDirPath(): string
            {
                return $this->engine;
            }
        };

        try {
            $domains = (new Openlitespeed($host))->listDomains();
        } finally {
            array_map('unlink', glob("{$vhosts}/*") ?: []);
            rmdir($vhosts);
            rmdir(dirname($vhosts));
            rmdir(dirname($vhosts, 2));
            rmdir($engine);
        }

        sort($domains);
        $this->assertSame(['alice.test', 'bob.test'], $domains);
    }

    public function test_litespeed_details_report_the_running_version_and_the_stored_serial(): void
    {
        $host = $this->host(fn (?string $action): string => "LiteSpeed/6.3.1 Enterprise\n");
        $host->files['/opt/engine/webserver-config/litespeed/serial.no'] = 'ABCD-1234';

        $this->assertSame([
            'name' => 'LiteSpeed',
            'version' => 'LiteSpeed/6.3.1 Enterprise',
            'slug' => 'litespeed',
            'metadata' => ['serial_number' => 'ABCD-1234', 'web_panel_port' => '7080', 'web_panel_username' => 'admin'],
        ], (new Litespeed($host))->getDetails());
        $this->assertSame(['compose.litespeedVersion'], array_column($host->execs, 0));
    }

    public function test_litespeed_details_survive_a_webserver_that_cannot_be_asked(): void
    {
        $host = $this->host(fn (?string $action): string => throw new \RuntimeException('sites-http is down'));

        $details = (new Litespeed($host))->getDetails();

        $this->assertSame('Unknown', $details['version']);
        $this->assertSame('', $details['metadata']['serial_number']);
    }

    public function test_a_reset_web_panel_password_hands_the_new_one_to_the_webserver(): void
    {
        $host = $this->host(fn (?string $action): string => '');

        $password = (new Litespeed($host))->resetWebPanelPassword();

        $this->assertSame(16, strlen($password));
        $this->assertSame([['compose.litespeedResetAdminPassword', [RootEngineServices::LITESPEED_PASSWORD => $password]]], $host->execs);
    }

    public function test_only_the_serial_number_can_be_configured(): void
    {
        $this->expectExceptionMessage('Unsupported config entry for current webserver');

        (new Litespeed($this->host(fn (?string $action): string => '')))->updateConfig('admin_email', 'x');
    }

    public function test_a_serial_the_webserver_accepts_passes_validation(): void
    {
        $host = $this->host(fn (?string $action): string => '');

        (new Litespeed($host))->validateSerialNumberForConfig('GOOD-SERIAL');

        $this->assertSame('GOOD-SERIAL', $host->files['/opt/engine/webserver-config/litespeed/serial.no.tmp']);
        $this->assertSame(['docker.checkLiteSpeedSerial'], array_column($host->execs, 0));
    }

    public function test_a_serial_the_webserver_rejects_is_a_validation_error(): void
    {
        Log::spy();
        $host = $this->host(fn (?string $action): string => throw new \RuntimeException('serial rejected'));

        try {
            (new Litespeed($host))->validateSerialNumberForConfig('BAD-SERIAL');
            $this->fail('a rejected serial has to fail validation');
        } catch (ValidationException $e) {
            $this->assertSame(['serial_number' => ['The LiteSpeed serial number is invalid.']], $e->errors());
        }
        Log::shouldHaveReceived('warning')->once()->with('LiteSpeed serial number validation failed', ['error' => 'serial rejected']);
    }

    public function test_a_docker_failure_while_validating_a_serial_is_not_blamed_on_the_serial(): void
    {
        $host = $this->host(fn (?string $action): string => throw new DockerErrorException('docker is not running'));

        $this->expectException(DockerErrorException::class);

        (new Litespeed($host))->validateSerialNumberForConfig('ANY-SERIAL');
    }

    /** A host whose broker requests through exec are answered by $answer and journaled with their environment. */
    private function host(\Closure $answer): System
    {
        return new class ($answer) extends System {
            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null
            ): Process {
                throw new \LogicException('No process may start from this test.');
            }

            /** @var list<array{?string, array<string, string>}> */
            public array $execs = [];

            /** @var array<string, string> */
            public array $files = [];

            public function __construct(private \Closure $answer) {}

            public function engineDirPath(): string
            {
                return '/opt/engine';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $action = LocalRoot::action(array_values((array) $cmd));
                $this->execs[] = [$action, $env];

                return ($this->answer)($action);
            }

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function __construct(private System $host)
                    {
                        parent::__construct($host);
                    }

                    public function fileExists(string $path): bool
                    {
                        return array_key_exists($path, $this->host->files);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return $this->host->files[$path] ?? throw new \RuntimeException("no such file: {$path}");
                    }

                    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->host->files[$path] = $contents;
                    }
                };
            }
        };
    }

    private static function litespeedConfig(): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<httpServerConfig>\n  <serverName>engine</serverName>\n"
            ."  <listenerList>\n    <listener><name>old</name></listener>\n  </listenerList>\n"
            ."  <virtualHostList>\n    <virtualHost><name>old</name></virtualHost>\n  </virtualHostList>\n</httpServerConfig>\n";
    }
}
