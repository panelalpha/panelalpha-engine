<?php

namespace Tests\Integration\System\Services;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\Setting;
use App\System;
use App\System\Filesystem;
use App\System\Services\Modsec;
use App\System\Services\Webserver;
use App\System\Services\Webserver\WebserverInterface;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * What ModSecurity hands the webserver: main.conf in the configured engine mode, a reload or a
 * restart depending on the webserver, and the Apache check of candidate rules.
 */
class ModsecWebserverHandoffTest extends TestCase
{
    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_detection_only_is_written_into_main_conf_before_the_driver_toggles(): void
    {
        Setting::setRuntimeSettings(['modsec' => json_encode(['mode' => 'detection_only', 'enabled_rulesets' => []])]);
        $driver = $this->createMock(WebserverInterface::class);
        $host = $this->host(webserver: 'nginx-proxy', driver: $driver);
        $driver->expects($this->once())->method('toggleModsecurity')->willReturnCallback(
            fn () => $this->assertArrayHasKey('/opt/engine/config/modsecurity/main.conf', $host->files)
        );

        (new Modsec($host))->rebuildConfig();

        $this->assertStringContainsString('SecRuleEngine DetectionOnly', $host->files['/opt/engine/config/modsecurity/main.conf']);
    }

    public function test_nginx_takes_new_rules_with_a_reload(): void
    {
        foreach (['nginx', 'nginx-proxy'] as $webserver) {
            $host = $this->host(webserver: $webserver);

            (new Modsec($host))->restartWebserver();

            $this->assertSame(['reload'], $host->handoffs, $webserver);
        }
    }

    public function test_another_webserver_is_restarted_to_take_new_rules(): void
    {
        $host = $this->host(webserver: 'openlitespeed');

        (new Modsec($host))->restartWebserver();

        $this->assertSame(['restart'], $host->handoffs);
    }

    public function test_an_undetectable_webserver_is_restarted(): void
    {
        $host = $this->host(webserver: null);

        (new Modsec($host))->restartWebserver();

        $this->assertSame(['restart'], $host->handoffs);
    }

    public function test_an_undetectable_webserver_is_no_webserver_to_test_rules_on(): void
    {
        $modsec = new class ($this->host(webserver: null)) extends Modsec {
            public function current(): string
            {
                return $this->currentWebserver();
            }
        };

        $this->assertSame('', $modsec->current());
    }

    public function test_apache_checks_candidate_rules_with_its_own_config(): void
    {
        $host = $this->host(webserver: 'apache', answer: FakeProcess::ok('Syntax OK'));

        $this->assertNull($this->tester($host)->check('apache', '/opt/engine/tmp/modsec-test'));

        $this->assertArrayHasKey('/opt/engine/tmp/modsec-test/httpd.conf', $host->files);
        $this->assertArrayNotHasKey('/opt/engine/tmp/modsec-test/check.conf', $host->files);
        $this->assertSame(['compose.modsecurityApacheCheck'], $host->actions);
    }

    public function test_apache_refusing_candidate_rules_names_its_syntax_error(): void
    {
        $host = $this->host(webserver: 'apache', answer: FakeProcess::of(1, '', "AH00526: Syntax error on line 3 of custom.conf:\nInvalid command 'SecRuel'"));

        $this->assertSame(
            'AH00526: Syntax error on line 3 of custom.conf:',
            $this->tester($host)->check('apache', '/opt/engine/tmp/modsec-test')
        );
    }

    public function test_a_webserver_without_a_live_config_test_refuses_the_rules(): void
    {
        $host = $this->host(webserver: 'litespeed');

        $this->assertSame(
            'custom rules cannot be checked on the litespeed webserver, so they are not applied',
            $this->tester($host)->live('litespeed')
        );
        $this->assertSame([], $host->actions);
    }

    private function tester(System $host): Modsec
    {
        return new class ($host) extends Modsec {
            public function check(string $webserver, string $dir): ?string
            {
                return $this->configTest($webserver, $dir);
            }

            public function live(string $webserver): ?string
            {
                return $this->liveConfigTest($webserver);
            }
        };
    }

    /** A host running $webserver (null: it cannot be told), whose host commands answer $answer. */
    private function host(?string $webserver, ?WebserverInterface $driver = null, ?Process $answer = null): System
    {
        return new class ($webserver, $driver, $answer ?? FakeProcess::ok()) extends System {
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

            /** @var array<string, string> */
            public array $files = [];

            /** @var list<string> */
            public array $handoffs = [];

            /** @var list<?string> */
            public array $actions = [];

            public function __construct(public ?string $webserver, private ?WebserverInterface $driver, private Process $answer)
            {
                $this->files['/opt/engine/templates/config/modsecurity-main.conf.blade.php'] = (string) file_get_contents(
                    dirname(base_path()).'/templates/config/modsecurity-main.conf.blade.php'
                );
            }

            public function engineDirPath(): string
            {
                return '/opt/engine';
            }

            public function runProcessOnHost(array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->actions[] = LocalRoot::action($cmd);

                return $this->answer;
            }

            public function webserver(): Webserver
            {
                return new class ($this, $this->driver) extends Webserver {
                    public function __construct(private System $host, private ?WebserverInterface $fixed)
                    {
                        parent::__construct($host);
                    }

                    public function getCurrentWebserver(): string
                    {
                        return $this->host->webserver ?? throw new \Exception('Could not parse docker compose response');
                    }

                    public function detectWebserver(): string
                    {
                        return $this->getCurrentWebserver();
                    }

                    public function driver(?string $slug = null): WebserverInterface
                    {
                        return $this->fixed ?? parent::driver($slug);
                    }

                    public function scheduleWebserverReloadInBackground(bool $rebindIpListeners = false): void
                    {
                        $this->host->handoffs[] = 'reload';
                    }

                    public function restartWebserverContainerFromHost(): void
                    {
                        $this->host->handoffs[] = 'restart';
                    }
                };
            }

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function __construct(private System $host)
                    {
                        parent::__construct($host);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return $this->host->files[$path] ?? throw new \RuntimeException("no such file: {$path}");
                    }

                    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->host->files[$path] = $contents;
                    }

                    public function writeFileReplacingPath(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->host->files[$path] = $contents;
                    }
                };
            }
        };
    }
}
