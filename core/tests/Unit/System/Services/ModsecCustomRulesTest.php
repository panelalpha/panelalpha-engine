<?php

namespace Tests\Unit\System\Services;

use App\Models\Setting;
use App\System;
use App\System\Services\Modsec;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Custom rules go live only after the webserver's config test parsed them; a
 * refused rule must leave the live file, and so every site, as it was.
 */
class ModsecCustomRulesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-modsec-custom-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config/modsecurity/rulesets/owasp-crs/rules', 0777, true);
        mkdir($this->root . '/templates/config', 0777, true);
        file_put_contents($this->root . '/config/modsecurity/rulesets/owasp-crs/setup.conf', '');
        copy(
            dirname(base_path()) . '/templates/config/modsecurity-main.conf.blade.php',
            $this->root . '/templates/config/modsecurity-main.conf.blade.php'
        );
        $this->modsecSettings('on', ['owasp-crs']);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    /** @param list<string> $enabled */
    private function modsecSettings(string $mode, array $enabled): void
    {
        Setting::setRuntimeSettings(['modsec' => json_encode(['mode' => $mode, 'enabled_rulesets' => $enabled])]);
    }

    private function live(): string
    {
        return $this->root . '/config/modsecurity/rulesets/custom/rules/custom.conf';
    }

    /** A System whose commands run for real minus sudo, inside a temp engine dir. */
    private function system(): System
    {
        return new class ($this->root) extends System {
            public function __construct(private string $root)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $cmd = (array) $cmd;
                if (($cmd[0] ?? null) === 'sudo') {
                    array_shift($cmd);
                }
                $process = new Process($cmd);
                $process->run();

                return $process;
            }
        };
    }

    /**
     * @param ?string $verdict what the webserver's config test answers
     * @return Modsec&object{tested: list<string>, restarts: int, mainConf: string}
     */
    private function modsec(System $system, ?string $verdict, string $webserver = 'nginx-proxy'): Modsec
    {
        return new class ($system, $verdict, $webserver) extends Modsec {
            /** @var list<string> */
            public array $tested = [];
            public int $restarts = 0;
            public string $mainConf = '';

            public function __construct(System $system, private ?string $verdict, private string $webserver)
            {
                parent::__construct($system);
            }

            protected function configTest(string $webserver, string $testDir): ?string
            {
                $this->tested[] = (string) file_get_contents($testDir . '/custom.conf');
                $this->mainConf = (string) file_get_contents($testDir . '/main.conf');

                return $this->verdict;
            }

            protected function currentWebserver(): string
            {
                return $this->webserver;
            }

            public function restartWebserver(): void
            {
                $this->restarts++;
            }
        };
    }

    public function test_rules_the_test_accepts_are_written_and_the_temp_copy_removed(): void
    {
        $modsec = $this->modsec($this->system(), null);
        $rules = 'SecRule REQUEST_URI "@beginsWith /xyz" "id:1100001,phase:1,deny,status:403,log"';

        $modsec->saveCustomRules($rules);

        $this->assertSame([$rules], $modsec->tested);
        $this->assertSame($rules, (string) file_get_contents($this->live()));
        $this->assertSame($rules, $modsec->customRules());
        $this->assertSame([], glob($this->root . '/config/modsecurity/.custom-rules-test-*') ?: []);
        // Not enabled yet: nothing to reload.
        $this->assertSame(0, $modsec->restarts);
    }

    public function test_the_test_parses_the_candidate_with_every_enabled_ruleset(): void
    {
        $modsec = $this->modsec($this->system(), null);

        $modsec->saveCustomRules('SecMarker END_CUSTOM');

        $this->assertStringContainsString('Include ' . $this->root . '/config/modsecurity/rulesets/owasp-crs/setup.conf', $modsec->mainConf);
        $this->assertStringContainsString('Include ' . $this->root . '/config/modsecurity/rulesets/owasp-crs/rules/*.conf', $modsec->mainConf);
        $this->assertMatchesRegularExpression('#Include \S+/\.custom-rules-test-[0-9a-f]+/custom\.conf#', $modsec->mainConf);
        $this->assertStringNotContainsString('rulesets/custom/', $modsec->mainConf);
    }

    public function test_a_refused_rule_never_reaches_the_live_file(): void
    {
        mkdir(dirname($this->live()), 0777, true);
        file_put_contents($this->live(), 'SecMarker OLD');
        $this->modsecSettings('on', ['owasp-crs', 'custom']);
        $modsec = $this->modsec($this->system(), 'nginx: [emerg] "modsecurity_rules_file" directive Rules error.');

        try {
            $modsec->saveCustomRules('SecRule REQUEST_URI "@nope x" "id:1100001,phase:1,deny"');
            $this->fail('a rule the webserver refused was accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Rules error', $e->getMessage());
        }

        $this->assertSame('SecMarker OLD', (string) file_get_contents($this->live()));
        $this->assertSame(0, $modsec->restarts);
        $this->assertSame([], glob($this->root . '/config/modsecurity/.custom-rules-test-*') ?: []);
    }

    public function test_an_enabled_ruleset_is_reloaded_once_the_rules_are_live(): void
    {
        $this->modsecSettings('on', ['owasp-crs', 'custom']);
        $modsec = $this->modsec($this->system(), null);

        $modsec->saveCustomRules('SecMarker A');

        $this->assertSame(1, $modsec->restarts);
        $this->assertTrue($modsec->customRulesEnabled());
    }

    public function test_with_modsecurity_off_nothing_is_reloaded(): void
    {
        $this->modsecSettings('off', ['custom']);
        $modsec = $this->modsec($this->system(), null);

        $modsec->saveCustomRules('SecMarker A');

        $this->assertSame(0, $modsec->restarts);
    }

    public function test_a_file_switched_off_stays_switched_off(): void
    {
        mkdir(dirname($this->live()), 0777, true);
        file_put_contents($this->live() . '.disabled', 'SecMarker OLD');
        $modsec = $this->modsec($this->system(), null);

        $modsec->saveCustomRules('SecMarker NEW');

        $this->assertFileDoesNotExist($this->live());
        $this->assertSame('SecMarker NEW', (string) file_get_contents($this->live() . '.disabled'));
    }

    public function test_rules_that_break_a_static_check_never_reach_the_webserver(): void
    {
        $modsec = $this->modsec($this->system(), null);

        try {
            $modsec->saveCustomRules('Include /etc/shadow');
            $this->fail('an Include was accepted');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame([], $modsec->tested);
        $this->assertFileDoesNotExist($this->live());
    }

    public function test_ids_must_stay_in_the_custom_range(): void
    {
        $this->assertNull(Modsec::customRulesProblem(
            "# a comment\n"
            . "SecRule REQUEST_URI \"@beginsWith /a\" \\\n    \"id:1100000,phase:1,deny,chain\"\n"
            . "    SecRule REQUEST_METHOD \"@streq POST\" \"t:none\"\n"
            . "SecAction \"id:'1199999',phase:1,pass,nolog\"\n"
            . "SecRuleRemoveById 942100\n"
            . "SecRule ARGS \"@rx x\" \"id:1100002,phase:2,pass,ctl:ruleRemoveById=949110\"\n"
        ));

        // CRS, the engine's own phpMyAdmin and WordPress rules, and just past the range.
        foreach ([942100, 1000001, 1000101, 1200000, 5] as $id) {
            $problem = Modsec::customRulesProblem("SecRule ARGS \"@rx x\" \"phase:1,id:{$id},deny\"");
            $this->assertNotNull($problem, "id {$id} was accepted");
            $this->assertStringContainsString((string) $id, (string) $problem);
        }
        $this->assertNotNull(Modsec::customRulesProblem('SecAction id:5,pass'));
        $this->assertSame(
            'Rule id 1100001 is used twice.',
            Modsec::customRulesProblem("SecAction \"id:1100001,pass\"\nSecAction \"id:1100001,pass\"")
        );
    }

    public function test_only_rule_directives_are_accepted(): void
    {
        foreach ([
            'Include /etc/passwd',
            'SecRemoteRules key https://example.test/rules.conf',
            'SecAuditLog /opt/panelalpha/shared-hosting/.env',
            'SecRuleEngine Off',
            'SecDataDir /tmp',
        ] as $rules) {
            $this->assertNotNull(Modsec::customRulesProblem($rules), "{$rules} was accepted");
        }
        $this->assertNull(Modsec::customRulesProblem(''));
        $this->assertNotNull(Modsec::customRulesProblem(str_repeat('#', Modsec::CUSTOM_RULES_MAX_BYTES + 1)));
    }

    public function test_the_config_test_loads_only_modsecurity_and_the_candidate(): void
    {
        $dir = '/opt/panelalpha/shared-hosting/config/modsecurity/.custom-rules-test-ab';

        $this->assertSame("nginx -t -c '{$dir}/nginx.conf'", Modsec::configTestCommand('nginx-proxy', $dir));
        $this->assertSame("nginx -t -c '{$dir}/nginx.conf'", Modsec::configTestCommand('nginx', $dir));
        $this->assertSame("httpd -t -f '{$dir}/httpd.conf'", Modsec::configTestCommand('apache', $dir));
        $this->assertNull(Modsec::configTestCommand('litespeed', $dir));
        $this->assertNull(Modsec::configTestCommand('openlitespeed', $dir));

        $nginx = Modsec::configTestConfig('nginx-proxy', $dir);
        $this->assertStringContainsString('load_module modules/ngx_http_modsecurity_module.so;', $nginx);
        $this->assertStringContainsString("modsecurity_rules_file {$dir}/main.conf;", $nginx);
        $this->assertStringNotContainsString('listen', $nginx);

        $apache = Modsec::configTestConfig('apache', $dir);
        $this->assertStringContainsString('LoadModule security3_module /usr/lib/apache2/modules/mod_security3.so', $apache);
        $this->assertStringContainsString("modsecurity_rules_file {$dir}/main.conf", $apache);
    }

    public function test_a_webserver_without_a_config_test_refuses_the_rules(): void
    {
        $modsec = new class ($this->system()) extends Modsec {
            protected function currentWebserver(): string
            {
                return 'litespeed';
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be checked on the litespeed webserver');
        try {
            $modsec->saveCustomRules('SecMarker A');
        } finally {
            $this->assertFileDoesNotExist($this->live());
        }
    }

    public function test_the_verdict_is_the_line_the_parser_refused_on(): void
    {
        $this->assertNull(Modsec::configTestProblem(0, "nginx: configuration file x test is successful\n"));
        $this->assertSame(
            'nginx: [emerg] "modsecurity_rules_file" directive Rules error. File: /x/custom.conf. Line: 1. Column: 40. Invalid input: @nope',
            Modsec::configTestProblem(1, "WARN[0000] compose noise\n"
                . "2026/10/03 15:49:36 [emerg] 5920#5920: \"modsecurity_rules_file\" directive Rules error.\n"
                . "nginx: [emerg] \"modsecurity_rules_file\" directive Rules error. File: /x/custom.conf. Line: 1. Column: 40. Invalid input: @nope\n"
                . "nginx: configuration file /x/nginx.conf test failed\n")
        );
        $this->assertSame(
            'AH00526: Syntax error on line 10 of /x/httpd.conf:',
            Modsec::configTestProblem(1, "AH00526: Syntax error on line 10 of /x/httpd.conf:\nRules error. File: /x/custom.conf.\n")
        );
        // A test that never reached the webserver is still not a pass.
        $this->assertSame('service "sites-http" is not running', Modsec::configTestProblem(1, 'service "sites-http" is not running'));
        $this->assertNotNull(Modsec::configTestProblem(null, ''));
    }
}
