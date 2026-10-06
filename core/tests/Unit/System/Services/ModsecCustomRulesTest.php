<?php

namespace Tests\Unit\System\Services;

use App\Models\Setting;
use App\System;
use App\System\EnginePaths;
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
     * @param ?string $liveVerdict what its test of the live config answers
     * @param ?\Throwable $liveError what that test throws instead
     * @return Modsec&object{tested: list<string>, liveTested: list<?string>, restarts: int, mainConf: string}
     */
    private function modsec(
        System $system,
        ?string $verdict,
        string $webserver = 'nginx-proxy',
        ?string $liveVerdict = null,
        ?\Throwable $liveError = null,
    ): Modsec {
        return new class ($system, $verdict, $webserver, $liveVerdict, $this->live(), $liveError) extends Modsec {
            /** @var list<string> */
            public array $tested = [];
            /** @var list<?string> the live file as each live test found it */
            public array $liveTested = [];
            public int $restarts = 0;
            public string $mainConf = '';

            public function __construct(
                System $system,
                private ?string $verdict,
                private string $webserver,
                private ?string $liveVerdict,
                private string $live,
                private ?\Throwable $liveError,
            ) {
                parent::__construct($system);
            }

            protected function configTest(string $webserver, string $testDir): ?string
            {
                $this->tested[] = (string) file_get_contents($testDir . '/custom.conf');
                $this->mainConf = (string) file_get_contents($testDir . '/main.conf');

                return $this->verdict;
            }

            protected function liveConfigTest(string $webserver): ?string
            {
                $this->liveTested[] = is_file($this->live) ? (string) file_get_contents($this->live) : null;
                if ($this->liveError !== null) {
                    throw $this->liveError;
                }

                return $this->liveVerdict;
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
        // On, so the check can see the candidate switch it off.
        $this->assertStringContainsString('SecRuleEngine On', $modsec->mainConf);
    }

    public function test_the_candidate_runs_where_the_live_config_loads_it(): void
    {
        $includes = function (array $enabled): array {
            $this->modsecSettings('on', $enabled);
            $modsec = $this->modsec($this->system(), null);
            $modsec->saveCustomRules('SecMarker A');
            preg_match_all('#^Include (\S+)$#m', $modsec->mainConf, $m);

            return array_map(fn (string $path): string => preg_replace('#^.*/(\.custom-rules-test-[0-9a-f]+/|rulesets/)#', '', $path), $m[1]);
        };

        // Enabled before CRS, live loads it before CRS: a phase 1 allow there skips CRS's setup.
        $this->assertSame(['custom.conf', 'owasp-crs/setup.conf', 'owasp-crs/rules/*.conf'], $includes(['custom', 'owasp-crs']));
        $this->assertSame(['owasp-crs/setup.conf', 'owasp-crs/rules/*.conf', 'custom.conf'], $includes(['owasp-crs', 'custom']));
        // Not enabled yet: enabling it appends it.
        $this->assertSame(['owasp-crs/setup.conf', 'owasp-crs/rules/*.conf', 'custom.conf'], $includes(['owasp-crs']));
        $this->assertSame(['custom.conf'], $includes([]));
    }

    public function test_a_skip_that_can_land_outside_these_rules_is_refused(): void
    {
        $rule = 'SecRule REQUEST_URI "@beginsWith /qa" "id:1100002,phase:1,deny,status:403"';
        // Measured on libmodsecurity 3.0.15: a skipAfter whose marker is missing, written in
        // another case or before it skips every rule after it, in CRS too and in later phases,
        // for every request it matches; skip:N counts on into the rules loaded after these.
        foreach ([
            "SecRule REQUEST_URI \"@beginsWith /qa2\" \"id:1100001,phase:1,pass,nolog,skipAfter:END_QA2X\"\n{$rule}\nSecMarker END_QA2",
            "SecRule REQUEST_URI \"@beginsWith /qa2\" \"id:1100001,phase:1,pass,nolog,skipAfter:end_qa2\"\n{$rule}\nSecMarker END_QA2",
            "SecMarker END_QA2\nSecRule REQUEST_URI \"@beginsWith /qa2\" \"id:1100001,phase:1,pass,nolog,skipAfter:END_QA2\"\n{$rule}",
            'SecAction "id:1100001,phase:1,pass,nolog,skipAfter:END-REQUEST-911-METHOD-ENFORCEMENT"',
            'SecAction "id:1100001,phase:1,pass,nolog,skipAfter:"',
            "SecRuleUpdateActionById 1100002 \"skipAfter:END\"\n{$rule}\nSecMarker END",
        ] as $rules) {
            $this->assertStringContainsString('skipAfter', (string) Modsec::customRulesProblem($rules), json_encode($rules) . ' was accepted');
        }
        foreach ([
            "SecRule REQUEST_URI \"@beginsWith /qa2\" \"id:1100001,phase:1,pass,nolog,skip:1\"\n{$rule}",
            'SecAction "id:1100001,phase:1,pass,nolog,skip:1000"',
            "SecRule REQUEST_URI \"@beginsWith /qa2\" \\\n    \"id:1100001,phase:1,pass,nolog,SKIP : 2\"\n{$rule}",
        ] as $rules) {
            $this->assertStringStartsWith('skip is not accepted', (string) Modsec::customRulesProblem($rules), json_encode($rules) . ' was accepted');
        }

        // A marker after the rule, as CRS writes them; a chain with it on the first rule.
        foreach ([
            "SecRule REQUEST_URI \"@beginsWith /qa2\" \"id:1100001,phase:1,pass,nolog,skipAfter:END_QA2\"\n{$rule}\nSecMarker END_QA2",
            "SecRule REQUEST_URI \"@beginsWith /qa2\" \"id:1100001,phase:1,pass,nolog,skipAfter:'END_QA2'\"\n{$rule}\nSecMarker \"END_QA2\"",
            "SecRule REQUEST_URI \"@beginsWith /qa2\" \"id:1100001,phase:1,pass,nolog,skipAfter:END_QA2\"\n{$rule}\nSecMarker 'END_QA2'",
            "SecRule REQUEST_URI \"@beginsWith /qa2\" \\\n    \"id:1100001,\\\n    phase:1,\\\n    pass,\\\n    nolog,\\\n    chain,\\\n    skipAfter:END-QA2\"\n"
                . "    SecRule REQUEST_METHOD \"@streq GET\" \"t:none\"\n{$rule}\nSecMarker END-QA2\nSecMarker END-QA2",
            "SecRule ARGS:skip \"@streq 1\" \"id:1100001,phase:2,deny\"",
        ] as $rules) {
            $this->assertNull(Modsec::customRulesProblem($rules), json_encode($rules) . ' was refused');
        }
    }

    public function test_a_refused_rule_never_reaches_the_live_file(): void
    {
        mkdir(dirname($this->live()), 0777, true);
        file_put_contents($this->live(), 'SecMarker OLD');
        $this->modsecSettings('on', ['owasp-crs', 'custom']);
        $modsec = $this->modsec($this->system(), 'nginx: [emerg] "modsecurity_rules_file" directive Rules error.');

        try {
            $modsec->saveCustomRules('SecRule REQUEST_URI "@beginsWith /x" "id:1100001,phase:1,nosuchaction"');
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
        $this->assertSame(['SecMarker A'], $modsec->liveTested);
        $this->assertTrue($modsec->customRulesEnabled());
    }

    public function test_with_modsecurity_off_nothing_is_reloaded(): void
    {
        $this->modsecSettings('off', ['custom']);
        $modsec = $this->modsec($this->system(), null);

        $modsec->saveCustomRules('SecMarker A');

        $this->assertSame(0, $modsec->restarts);
        $this->assertSame([], $modsec->liveTested);
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

        foreach (['nginx-proxy', 'nginx'] as $webserver) {
            $command = (string) Modsec::configTestCommand($webserver, $dir);
            $this->assertStringStartsWith('sh -c ', $command);
            $this->assertStringContainsString('nginx -t -q -c "$T/nginx.conf" || exit', $command);
            $this->assertStringEndsWith(" sh '{$dir}' '/panelalpha-modsecurity-check/' 'panelalpha_check'", $command);
            $this->assertStringContainsString('for p in first phase1 phase2 phase3; do', $command);
            $this->assertStringContainsString('ask --data "$3=1" "http://localhost$2body"', $command);
            // Last, a request like a browser's for a site's front page, which no check rule denies.
            $this->assertStringContainsString('Gecko/20100101 Firefox/128.0', $command);
            $this->assertStringContainsString('Accept-Language: en', $command);
            $this->assertStringContainsString(' http://localhost/; }', $command);
            $this->assertStringContainsString('echo "modsecurity-check plain $(plain)"', $command);
        }
        $this->assertSame("httpd -t -f '{$dir}/httpd.conf'", Modsec::configTestCommand('apache', $dir));
        $this->assertNull(Modsec::configTestCommand('litespeed', $dir));
        $this->assertNull(Modsec::configTestCommand('openlitespeed', $dir));

        $nginx = Modsec::configTestConfig('nginx-proxy', $dir);
        $this->assertStringContainsString('load_module modules/ngx_http_modsecurity_module.so;', $nginx);
        $this->assertStringContainsString("modsecurity_rules_file {$dir}/check.conf;", $nginx);
        // It runs next to the live server: a socket, pid, lock and temp dirs of its own, no port.
        $this->assertStringContainsString("listen unix:{$dir}/check.sock;", $nginx);
        preg_match_all('#\s(/\S+?);#', $nginx, $paths);
        $this->assertNotEmpty($paths[1]);
        foreach ($paths[1] as $path) {
            $this->assertStringStartsWith($dir . '/', $path);
        }
        $this->assertStringContainsString('master_process off;', $nginx);
        // On a real install the socket's path still fits a unix socket address.
        $real = EnginePaths::ENGINE_DIR . '/config/modsecurity/.custom-rules-test-' . bin2hex(random_bytes(6));
        $this->assertMatchesRegularExpression('#listen unix:(\S+);#', Modsec::configTestConfig('nginx', $real));
        preg_match('#listen unix:(\S+);#', Modsec::configTestConfig('nginx', $real), $socket);
        $this->assertLessThanOrEqual(107, strlen($socket[1]));

        // The candidate's main.conf sits between a rule before it and, after it, a
        // rule in each phase a request goes through and one on a request body.
        $check = explode("\n", Modsec::checkRules($dir));
        $this->assertSame('SecRule REQUEST_URI "@streq /panelalpha-modsecurity-check/first" "id:1099990,phase:1,deny,status:418,nolog,noauditlog"', $check[0]);
        $this->assertSame("Include {$dir}/main.conf", $check[1]);
        $this->assertSame('SecAuditEngine Off', $check[2]);
        foreach ([1, 2, 3] as $phase) {
            $this->assertSame(
                "SecRule REQUEST_URI \"@streq /panelalpha-modsecurity-check/phase{$phase}\" \"id:109999{$phase},phase:{$phase},deny,status:418,nolog,noauditlog\"",
                $check[2 + $phase]
            );
        }
        $this->assertSame('SecRule ARGS_POST:panelalpha_check "@streq 1" "id:1099994,phase:2,deny,status:418,nolog,noauditlog"', $check[6]);
        // Every check id is clear of CRS, the engine's own rules and the custom range.
        preg_match_all('/id:(\d+)/', Modsec::checkRules($dir), $ids);
        foreach ($ids[1] as $id) {
            $this->assertGreaterThan(1009999, (int) $id);
            $this->assertLessThan(Modsec::CUSTOM_ID_MIN, (int) $id);
        }

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

    public function test_an_operator_modsecurity_does_not_know_is_refused(): void
    {
        // libmodsecurity parses each of these and reads it as a regular expression:
        // the rule then blocks nothing, or with `!` every request.
        foreach ([
            'SecRule REQUEST_URI "@nosuchop /x" "id:1100001,phase:1,deny,status:403,log"',
            'SecRule REQUEST_URI "!@nosuchop /x" "id:1100001,phase:1,deny,status:403,log"',
            'SecRule REQUEST_URI "@NoSuchOp" "id:1100001,phase:1,deny"',
            'SecRule REQUEST_URI @nosuchop "id:1100001,phase:1,deny"',
            'SecRule "REQUEST_URI" "@beginWith /x" "id:1100001,phase:1,deny"',
            'SecRule REQUEST_URI "@noMatch" "id:1100001,phase:1,deny"',
            'SecRule REQUEST_URI "@" "id:1100001,phase:1,deny"',
            "SecRule REQUEST_URI \"@beginsWith /a\" \"id:1100001,phase:1,deny,chain\"\n    SecRule ARGS \"@nosuchop x\" \"t:none\"",
        ] as $rules) {
            $problem = Modsec::customRulesProblem($rules);
            $this->assertNotNull($problem, "{$rules} was accepted");
            $this->assertStringContainsString('Unknown operator @', (string) $problem);
        }
        $this->assertStringStartsWith('Unknown operator @nosuchop.', (string) Modsec::customRulesProblem(
            'SecRule REQUEST_URI "@nosuchop /x" "id:1100001,phase:1,deny,status:403,log"'
        ));

        // A space before the @ makes it a regular expression too.
        foreach (['" @beginsWith /x"', '"! @beginsWith /x"'] as $operator) {
            $problem = Modsec::customRulesProblem("SecRule REQUEST_URI {$operator} \"id:1100001,phase:1,deny\"");
            $this->assertStringContainsString('has a space before the @', (string) $problem, "{$operator} was accepted");
        }
    }

    public function test_every_operator_modsecurity_knows_is_accepted(): void
    {
        // Operators that take an argument: libmodsecurity reads them with one.
        foreach ([
            'beginsWith', 'contains', 'containsWord', 'endsWith', 'eq',
            'fuzzyHash', 'ge', 'gsbLookup', 'gt', 'inspectFile', 'ipMatch', 'ipMatchF',
            'ipMatchFromFile', 'le', 'lt', 'pm', 'pmf', 'pmFromFile', 'rbl', 'rsub', 'rx', 'rxGlobal',
            'streq', 'strmatch', 'validateByteRange', 'validateDTD', 'validateHash',
            'validateSchema', 'verifyCC', 'verifyCPF',
            'verifySSN', 'verifySVNR', 'within', 'BEGINSWITH', 'beginswith',
        ] as $name) {
            $this->assertNull(Modsec::customRulesProblem("SecRule ARGS \"@{$name} x\" \"id:1100001,phase:1,deny\""), "@{$name} was refused");
            $this->assertNull(Modsec::customRulesProblem("SecRule ARGS \"!@{$name} x\" \"id:1100001,phase:1,deny\""), "!@{$name} was refused");
        }
        // Operators that take no argument: accepted alone, refused the moment anything
        // but spaces follows the name, because libmodsecurity then reads it as a regex.
        foreach ([
            'detectSQLi', 'detectXSS', 'geoLookup', 'unconditionalMatch',
            'validateUrlEncoding', 'validateUtf8Encoding', 'DETECTSQLI',
        ] as $name) {
            $this->assertNull(Modsec::customRulesProblem("SecRule ARGS \"@{$name}\" \"id:1100001,phase:1,deny\""), "@{$name} was refused");
            $this->assertNull(Modsec::customRulesProblem("SecRule ARGS \"!@{$name}\" \"id:1100001,phase:1,deny\""), "!@{$name} was refused");
            $this->assertNull(Modsec::customRulesProblem("SecRule ARGS \"@{$name}  \" \"id:1100001,phase:1,deny\""), "@{$name} with trailing spaces was refused");
            foreach (["@{$name} x", "!@{$name} x"] as $operator) {
                $problem = Modsec::customRulesProblem("SecRule ARGS \"{$operator}\" \"id:1100001,phase:1,deny\"");
                $this->assertNotNull($problem, "{$operator} was accepted");
                $this->assertStringContainsString('takes no argument', (string) $problem);
            }
        }
        foreach ([
            // No operator named is an @rx, and an @ later in it is just a character.
            'SecRule REQUEST_URI "^/x" "id:1100001,phase:1,deny"',
            'SecRule REQUEST_URI "x@nosuchop" "id:1100001,phase:1,deny"',
            'SecRule REQUEST_URI "@rx a\"b" "id:1100001,phase:1,deny"',
            'SecRule REQUEST_URI @streq "id:1100001,phase:1,deny"',
            'SecRule "REQUEST_URI" "@rx @nosuchop" "id:1100001,phase:1,deny"',
            'SecRule ARGS|!ARGS:x "@detectSQLi" "id:1100001,phase:2,deny"',
            // Only SecRule takes an operator.
            "SecAction \"id:1100001,phase:1,pass,msg:'@nosuchop'\"",
            'SecRuleUpdateTargetById 1100001 "!ARGS:@nosuchop"',
        ] as $rules) {
            $this->assertNull(Modsec::customRulesProblem($rules), "{$rules} was refused");
        }
    }

    public function test_an_unknown_operator_never_reaches_the_webserver_or_the_live_file(): void
    {
        mkdir(dirname($this->live()), 0777, true);
        file_put_contents($this->live(), 'SecMarker OLD');
        $this->modsecSettings('on', ['custom']);
        $modsec = $this->modsec($this->system(), null);

        try {
            $modsec->saveCustomRules('SecRule REQUEST_URI "@nosuchop /x" "id:1100001,phase:1,deny,status:403,log"');
            $this->fail('an unknown operator was accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Unknown operator @nosuchop', $e->getMessage());
        }

        $this->assertSame([], $modsec->tested);
        $this->assertSame('SecMarker OLD', (string) file_get_contents($this->live()));
        $this->assertSame(0, $modsec->restarts);
    }

    public function test_rules_the_live_config_refuses_are_taken_back_before_any_reload(): void
    {
        mkdir(dirname($this->live()), 0777, true);
        file_put_contents($this->live(), 'SecMarker OLD');
        $this->modsecSettings('on', ['owasp-crs', 'custom']);
        $refusal = 'nginx: [emerg] "modsecurity_rules_file" directive Rules error. File: /x/custom.conf. Line: 1. Failed to open file: \'list.txt\'.';
        $modsec = $this->modsec($this->system(), null, 'nginx-proxy', $refusal);

        try {
            $modsec->saveCustomRules('SecRule ARGS "@pmFromFile list.txt" "id:1100001,phase:2,deny"');
            $this->fail('rules the live config refused were kept');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('previous rules stay live', $e->getMessage());
            $this->assertStringContainsString("Failed to open file: 'list.txt'", $e->getMessage());
        }

        // Tested in place, then put back; nothing was reloaded.
        $this->assertSame(['SecRule ARGS "@pmFromFile list.txt" "id:1100001,phase:2,deny"'], $modsec->liveTested);
        $this->assertSame('SecMarker OLD', (string) file_get_contents($this->live()));
        $this->assertSame(0, $modsec->restarts);
    }

    public function test_first_rules_the_live_config_refuses_leave_no_file(): void
    {
        $this->modsecSettings('on', ['custom']);
        $modsec = $this->modsec($this->system(), null, 'nginx-proxy', 'nginx: [emerg] "modsecurity_rules_file" directive Rules error.');

        try {
            $modsec->saveCustomRules('SecMarker A');
            $this->fail('rules the live config refused were kept');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame(['SecMarker A'], $modsec->liveTested);
        $this->assertFileDoesNotExist($this->live());
        $this->assertSame(0, $modsec->restarts);
    }

    public function test_a_live_test_that_cannot_run_puts_the_previous_rules_back(): void
    {
        $this->modsecSettings('on', ['custom']);
        $timeout = new \RuntimeException('The process "nginx -t" exceeded the timeout of 60 seconds.');

        $modsec = $this->modsec($this->system(), null, 'nginx-proxy', null, $timeout);
        try {
            $modsec->saveCustomRules('SecMarker A');
            $this->fail('a live test that threw let the rules stay');
        } catch (\RuntimeException $e) {
            $this->assertSame($timeout, $e);
        }
        $this->assertSame(['SecMarker A'], $modsec->liveTested);
        $this->assertFileDoesNotExist($this->live());

        file_put_contents($this->live(), 'SecMarker OLD');
        $modsec = $this->modsec($this->system(), null, 'nginx-proxy', null, $timeout);
        try {
            $modsec->saveCustomRules('SecMarker NEW');
            $this->fail('a live test that threw let the rules stay');
        } catch (\RuntimeException) {
        }
        $this->assertSame('SecMarker OLD', (string) file_get_contents($this->live()));
        $this->assertSame(0, $modsec->restarts);
    }

    public function test_a_comment_never_takes_the_next_line_out_of_the_checks(): void
    {
        // libmodsecurity ends an ordinary `# ... \` at the newline and parses the
        // next line, so a bad directive placed there is caught on its own merits.
        foreach ([
            "# note \\\nSecDefaultAction \"phase:1,pass\"",
            "# x \\\nInclude /etc/passwd",
            "   # x \\  \r\nSecRequestBodyAccess Off",
            "SecMarker A\n\n# x \\\nSecDataDir /tmp",
        ] as $rules) {
            $problem = Modsec::customRulesProblem($rules);
            $this->assertNotNull($problem, json_encode($rules) . ' was accepted');
            $this->assertStringContainsString('Only rule directives are accepted', (string) $problem);
        }
        $this->assertStringStartsWith('Unknown operator @nosuchop', (string) Modsec::customRulesProblem(
            "# note \\\nSecRule REQUEST_URI \"@nosuchop /x\" \"id:1100001,phase:1,deny,status:403\""
        ));

        // A commented-out SecRule/SecAction continued with \ is carried onto the
        // following lines by libmodsecurity, blank lines included, swallowing them as
        // comment; a real directive hidden there would never load, so it is refused.
        foreach ([
            "#SecRule REQUEST_URI \"@beginsWith /a\" \"id:1100009,phase:1,deny\" \\\nSecRule REQUEST_URI \"@beginsWith /x\" \"id:1100001,phase:1,deny\"",
            "#SecRule REQUEST_URI \"@beginsWith /x\" \\\n\nSecRule REQUEST_URI \"@beginsWith /qa\" \"id:1100001,phase:1,deny\"",
            "#SecAction \"id:1100009,phase:1,pass\" \\\nSecMarker B",
            "SecMarker A\n\n  #SecRule REQUEST_URI \"@beginsWith /x\" \\\nSecMarker B",
        ] as $rules) {
            $problem = Modsec::customRulesProblem($rules);
            $this->assertNotNull($problem, json_encode($rules) . ' was accepted');
            $this->assertStringContainsString('commented-out SecRule/SecAction', (string) $problem);
        }

        foreach ([
            // An ordinary comment ending in \ does not swallow the next line.
            "# note \\\nSecRule REQUEST_URI \"@beginsWith /x\" \"id:1100001,phase:1,deny\"",
            // A blank line, and a commented-out rule with # on every line, as CRS writes them.
            "# note \\\n\nSecRule REQUEST_URI \"@beginsWith /x\" \"id:1100001,phase:1,deny\"",
            "#SecRule REQUEST_URI \"@beginsWith /a\" \\\n#    \"id:1100009,phase:1,deny\"\nSecMarker A",
            "SecMarker A\n# last line \\",
            "# note\nSecRule REQUEST_URI \"@beginsWith /x\" \\\n    \"id:1100001,phase:1,deny\"",
        ] as $rules) {
            $this->assertNull(Modsec::customRulesProblem($rules), json_encode($rules) . ' was refused');
        }
    }

    public function test_a_second_directive_on_a_line_is_refused(): void
    {
        // libmodsecurity ends a directive at the close of its actions or a marker name
        // and reads another straight after it, so a directive name anywhere but the
        // start of a line is refused.
        foreach ([
            'SecRule REQUEST_URI "@beginsWith /qa" "id:1100001,phase:1,deny,status:403" SecRuleRemoveById 1000001',
            'SecAction "id:1100001,phase:1,pass,nolog" Include /etc/hostname',
            'SecMarker foo SecRequestBodyAccess Off',
            'SecAction "id:1100001,phase:1,pass,nolog" SecAuditLog /tmp/x',
            'SecRule REQUEST_URI "@beginsWith /qa" "id:1100001,phase:1,deny" SecRule ARGS "@nosuchop /x" "id:1100002,phase:1,deny"',
            // Glued on: libmodsecurity needs no separator after a directive name.
            'SecAction "id:1100001,phase:1,pass,nolog"SecRuleRemoveById 1000001',
            'SecAction "id:1100001,phase:1,pass,nolog" SecRequestBodyAccessOff',
            'SecRuleUpdateTargetById 1100001 "!ARGS:x" SecAuditLog /tmp/x',
            // Inside quotes as this check reads them, outside as libmodsecurity does: it
            // ends a msg at \" right after the colon, and takes %" as text.
            'SecAction "id:1100001,phase:1,pass,nolog,msg:\\" SecRequestBodyAccess Off #"',
            'SecAction "id:1100001,phase:1,pass,nolog,msg:\\" Include /etc/hostname #"',
            'SecAction "id:1100001,phase:1,pass,nolog,msg:a%"" SecRequestBodyAccess Off #"',
        ] as $rules) {
            $problem = Modsec::customRulesProblem($rules);
            $this->assertNotNull($problem, json_encode($rules) . ' was accepted');
            $this->assertStringContainsString('only one directive', (string) $problem);
        }

        // A chained rule, each SecRule on its own line, stays accepted.
        $this->assertNull(Modsec::customRulesProblem(
            "SecRule REQUEST_URI \"@beginsWith /a\" \"id:1100001,phase:1,deny,chain\"\n"
            . "    SecRule REQUEST_METHOD \"@streq POST\" \"t:none\""
        ));
    }

    public function test_a_directive_glued_onto_a_complete_one_is_refused(): void
    {
        // The review's seven sets: each passed every check and turned ModSecurity off
        // or to detection-only for every site, because libmodsecurity lexes
        // SecRuleEngineOff as SecRuleEngine Off.
        $qa = 'SecRule REQUEST_URI "@beginsWith /qa" "id:1100001,phase:1,deny,status:403"';
        $shapes = [
            fn (string $d): string => "{$qa} {$d}\n",
            fn (string $d): string => "SecAction \"id:1100001,phase:1,pass,nolog\" {$d}\n",
            fn (string $d): string => "SecAction \"id:1100001,phase:1,pass,nolog\"{$d}\n",
            fn (string $d): string => "{$qa} \\\n{$d}\n",
            fn (string $d): string => "{$qa} {$d}\r\n",
            fn (string $d): string => "{$qa} \\\r\n{$d}\r\n",
        ];
        foreach ($shapes as $shape) {
            foreach (['SecRuleEngineOff', 'SecRuleEngineDetectionOnly'] as $glued) {
                $this->assertNotNull(Modsec::customRulesProblem($shape($glued)), json_encode($shape($glued)) . ' was accepted');
            }
            // Refused by the line's structure as well, not only by the engine's name.
            foreach (['SecRuleRemoveById1000001', 'SecRequestBodyAccessOff', 'SecMarker B'] as $glued) {
                $this->assertNotNull(Modsec::customRulesProblem($shape($glued)), json_encode($shape($glued)) . ' was accepted');
            }
        }
    }

    public function test_the_engine_mode_is_refused_in_any_spelling(): void
    {
        foreach ([
            'SecRuleEngine Off',
            'secruleengine DetectionOnly',
            'SECRULEENGINE On',
            'SecAction "id:1100001,phase:1,pass,nolog,ctl:ruleEngine=Off"',
            'SecRule REQUEST_URI "@beginsWith /x" "id:1100001,phase:1,pass,nolog,ctl:ruleEngine=DetectionOnly"',
            'SecRule REQUEST_URI "@beginsWith /admin" "id:1100001,phase:1,pass,nolog,ctl:ruleengine=On"',
            "SecAction \"id:1100001,phase:1,pass,nolog,ctl:rule\\\n    Engine=Off\"",
            "SecRule REQUEST_URI \"@beginsWith /x\" \"id:1100001,phase:1,deny,msg:'SecRuleEngine Off'\"",
            "# SecRuleEngine is set in main.conf\nSecMarker A",
        ] as $rules) {
            $problem = Modsec::customRulesProblem($rules);
            $this->assertNotNull($problem, json_encode($rules) . ' was accepted');
            $this->assertStringStartsWith('SecRuleEngine and ctl:ruleEngine are not accepted', (string) $problem);
        }
        // Exempting requests from a rule stays possible.
        $this->assertNull(Modsec::customRulesProblem(
            'SecRule REQUEST_FILENAME "@endsWith /wp-login.php" "id:1100001,phase:1,pass,nolog,ctl:ruleRemoveById=941100"'
        ));
    }

    public function test_nothing_but_a_comment_follows_the_last_argument(): void
    {
        foreach ([
            'SecAction "id:1100001,phase:1,pass" x',
            'SecRule REQUEST_URI "@beginsWith /qa" "id:1100001,phase:1,deny" "t:none"',
            'SecMarker A B',
            'SecRuleUpdateTargetById 1100001 "!ARGS:x" REQUEST_URI',
            'SecRuleUpdateActionById 1100001 "pass" x',
            'SecRuleRemoveByTag "a" "b"',
            'SecRuleRemoveByMsg "a" b',
            // libmodsecurity takes this one; refused all the same, the operator goes in quotes.
            'SecRule REQUEST_URI @beginsWith /qa "id:1100001,phase:1,deny"',
        ] as $rules) {
            $problem = Modsec::customRulesProblem($rules);
            $this->assertNotNull($problem, json_encode($rules) . ' was accepted');
            $this->assertStringContainsString('more on this line than its directive takes', (string) $problem);
        }

        foreach ([
            'SecRule REQUEST_URI "@beginsWith /qa" "id:1100001,phase:1,deny,status:403" # block /qa',
            'SecMarker END_CUSTOM # end of the custom rules',
            'SecRuleRemoveById 942100 942110-942120',
            'SecRuleRemoveByMsg SQL Injection Attack',
            'SecRuleRemoveByTag "attack-sqli"',
            'SecRuleUpdateTargetById 942100 "!ARGS:comment"',
            'SecRuleUpdateActionById 1100001 "pass"',
            'SecRule ARGS "@rx [\"\']\s*or" "id:1100001,phase:2,deny,status:403"',
        ] as $rules) {
            $this->assertNull(Modsec::customRulesProblem($rules), json_encode($rules) . ' was refused');
        }
    }

    public function test_a_backslash_after_a_complete_directive_is_refused(): void
    {
        $qa = 'SecRule REQUEST_URI "@beginsWith /qa" "id:1100001,phase:1,deny,status:403"';
        foreach ([
            "{$qa} \\\nSecMarker B",
            "{$qa}\\\nSecMarker B",
            "{$qa} \\\r\nSecMarker B",
            "SecAction \"id:1100001,phase:1,pass\"\\\nSecMarker B",
            "SecMarker A \\\nSecMarker B",
            "SecRuleRemoveById 942100 \\\nSecMarker B",
        ] as $rules) {
            $problem = Modsec::customRulesProblem($rules);
            $this->assertNotNull($problem, json_encode($rules) . ' was accepted');
            $this->assertStringStartsWith('Line 1 ends in \\ after a complete directive', (string) $problem);
        }
        $this->assertStringStartsWith('Line 2 ends in \\', (string) Modsec::customRulesProblem("SecMarker A\nSecMarker B \\\nSecMarker C"));

        // A directive still missing an argument, or inside its quoted actions, continues.
        foreach ([
            "SecRule REQUEST_URI \"@beginsWith /qa\" \\\n    \"id:1100001,\\\n    phase:1,\\\n    deny,\\\n    status:403,\\\n    msg:'custom block'\"",
            "SecRule REQUEST_URI \\\n    \"@beginsWith /qa\" \\\n    \"id:1100001,phase:1,deny\"",
            "SecRule REQUEST_URI \"@beginsWith /qa\" \"id:1100001,phase:1,deny,status:403,chain\"\n    SecRule REQUEST_METHOD \"@streq GET\" \\\n        \"t:none\"",
        ] as $rules) {
            $this->assertNull(Modsec::customRulesProblem($rules), json_encode($rules) . ' was refused');
        }
    }

    public function test_a_directive_name_in_the_data_is_refused_too(): void
    {
        // The check does not read quotes exactly as libmodsecurity does, so a
        // directive name counts wherever it is. These parse as plain rules there.
        foreach ([
            'SecRule ARGS "@pm include require" "id:1100001,phase:2,deny"',
            "SecRule REQUEST_URI \"@beginsWith /x\" \"id:1100001,phase:1,deny,msg:'see SecRuleRemoveById'\"",
        ] as $rules) {
            $this->assertStringContainsString('only one directive', (string) Modsec::customRulesProblem($rules));
        }
        // Words that only contain a name, and Include without a blank after it, are not one.
        foreach ([
            "SecRule ARGS \"@rx (?i)include\\s\" \"id:1100001,phase:2,deny,msg:'includes, insecure'\"",
            'SecMarker END_SECRULES',
            'SecRule ARGS:sec_action "@streq x" "id:1100001,phase:2,deny"',
        ] as $rules) {
            $this->assertNull(Modsec::customRulesProblem($rules), json_encode($rules) . ' was refused');
        }
    }

    public function test_the_check_reads_what_the_scratch_server_answered(): void
    {
        $noise = "2026/10/05 18:13:08 [notice] 19#19: ModSecurity-nginx v1.0.4 (rules loaded inline/local/remote: 0/7/0)\n";
        $answers = function (array $changed = []) use ($noise): string {
            $output = $noise;
            foreach ($changed + ['first' => '418', 'phase1' => '418', 'phase2' => '418', 'phase3' => '418', 'body' => '418', 'plain' => '204'] as $request => $code) {
                if ($code !== null) {
                    $output .= "modsecurity-check {$request} {$code}\n";
                }
            }

            return $output;
        };

        $this->assertNull(Modsec::checkProblem($answers()));
        // A candidate that denies a check request itself still blocks it.
        $this->assertNull(Modsec::checkProblem($answers(['phase1' => '403', 'phase2' => '403', 'phase3' => '403', 'body' => '403'])));

        // Unless it denies every request: custom before CRS with a phase 1 allow:phase
        // skips CRS's setup, and CRS then denies everything (measured on 3.0.15).
        $denyAll = ['phase1' => '403', 'phase2' => '403', 'phase3' => '403', 'body' => '403', 'plain' => '403'];
        $this->assertSame(
            'with these rules in place, an ordinary request with nothing in it to block is answered 403 instead of reaching the site. '
                . 'These rules would deny ordinary requests, on every site.',
            Modsec::checkProblem($answers($denyAll))
        );
        foreach (['403', '302', '200', '418', '404'] as $plain) {
            $this->assertStringContainsString(
                "is answered {$plain} instead of reaching the site. These rules would deny ordinary requests",
                (string) Modsec::checkProblem($answers(['plain' => $plain]))
            );
        }

        foreach (['204', '403', '200'] as $first) {
            $this->assertStringContainsString('a rule loaded before them denies gets through', (string) Modsec::checkProblem($answers(['first' => $first])));
        }
        // An allow:phase or a skipAfter in phase 1 leaves phase 2 running, and an allow in phase 3 the request phases.
        foreach ([1, 2, 3] as $phase) {
            $this->assertStringContainsString(
                "a request that a phase {$phase} rule loaded after them denies gets through",
                (string) Modsec::checkProblem($answers(["phase{$phase}" => '204']))
            );
        }
        // A ctl:requestBodyAccess=Off on every request: no GET shows it.
        $this->assertStringContainsString('a request body that a rule loaded after them denies gets through', (string) Modsec::checkProblem($answers(['body' => '204'])));

        foreach (['first', 'phase1', 'phase2', 'phase3', 'body', 'plain'] as $request) {
            $this->assertStringContainsString('could not run', (string) Modsec::checkProblem($answers([$request => '000'])));
            $this->assertStringContainsString('could not run', (string) Modsec::checkProblem($answers([$request => null])));
        }
        foreach ([$noise, "modsecurity-check first 418\nmodsecurity-check last 418\n", ''] as $output) {
            $this->assertStringContainsString('could not run', (string) Modsec::checkProblem($output));
        }
    }

    public function test_rules_the_check_finds_switching_protection_off_never_reach_the_live_file(): void
    {
        mkdir(dirname($this->live()), 0777, true);
        file_put_contents($this->live(), 'SecMarker OLD');
        $this->modsecSettings('on', ['owasp-crs', 'custom']);
        $run = $this->checkedSave(...);

        $answers = fn (string $first, string $phase1): string => "modsecurity-check first {$first}\nmodsecurity-check phase1 {$phase1}\n"
            . "modsecurity-check phase2 418\nmodsecurity-check phase3 418\nmodsecurity-check body 418\nmodsecurity-check plain 204\n";
        [$error, $restarts, $seen] = $run($answers('204', '204'), 'SecMarker NEW');
        $this->assertStringStartsWith('The webserver refused the rules: with these rules in place, a request that a rule loaded before them', (string) $error);
        $this->assertSame('SecMarker OLD', (string) file_get_contents($this->live()));
        $this->assertSame(0, $restarts);
        $this->assertSame([], glob($this->root . '/config/modsecurity/.custom-rules-test-*') ?: []);
        // One run in sites-http, over the files the check needs, and no live test.
        $this->assertCount(1, $seen);
        $this->assertStringContainsString('exec -T sites-http sh -c ', $seen[0]['command']);
        $this->assertStringContainsString('Include ' . $seen[0]['dir'] . '/main.conf', $seen[0]['check.conf']);
        $this->assertStringContainsString("modsecurity_rules_file {$seen[0]['dir']}/check.conf;", $seen[0]['nginx.conf']);
        $this->assertStringContainsString('SecRuleEngine On', $seen[0]['main.conf']);
        $this->assertSame('SecMarker NEW', $seen[0]['custom.conf']);

        [$error] = $run($answers('418', '204'), 'SecMarker NEW');
        $this->assertStringContainsString('a request that a phase 1 rule loaded after them denies gets through', (string) $error);
        $this->assertSame('SecMarker OLD', (string) file_get_contents($this->live()));

        [$error, $restarts, $seen] = $run($answers('418', '418'), 'SecMarker NEW');
        $this->assertNull($error);
        $this->assertSame('SecMarker NEW', (string) file_get_contents($this->live()));
        $this->assertSame(1, $restarts);
        $this->assertCount(2, $seen);
        $this->assertStringEndsWith('exec -T sites-http nginx -t', $seen[1]['command']);
    }

    public function test_rules_that_deny_ordinary_requests_never_reach_the_live_file(): void
    {
        $old = 'SecRule REQUEST_URI "@beginsWith /blocked" "id:1100001,phase:1,deny,status:403,log"';
        mkdir(dirname($this->live()), 0777, true);
        file_put_contents($this->live(), $old);
        // custom enabled before CRS: its rules load first.
        $this->modsecSettings('on', ['custom', 'owasp-crs']);
        $save = $this->checkedSave(...);

        // Skips CRS's phase 1 setup, so CRS denies every request: what the scratch server answered.
        $allowPhase = 'SecAction "id:1100002,phase:1,pass,nolog,allow:phase"' . "\n" . $old;
        $denied = "modsecurity-check first 418\nmodsecurity-check phase1 403\nmodsecurity-check phase2 403\n"
            . "modsecurity-check phase3 403\nmodsecurity-check body 403\nmodsecurity-check plain 403\n";
        [$error, $restarts, $seen] = $save($denied, $allowPhase);
        $this->assertSame(
            'The webserver refused the rules: with these rules in place, an ordinary request with nothing in it to block '
                . 'is answered 403 instead of reaching the site. These rules would deny ordinary requests, on every site.',
            $error
        );
        $this->assertSame($old, (string) file_get_contents($this->live()));
        $this->assertSame(0, $restarts);
        $this->assertCount(1, $seen);
        $this->assertSame([], glob($this->root . '/config/modsecurity/.custom-rules-test-*') ?: []);
        // The check ran the candidate where the live config loads it, before CRS.
        $this->assertSame($allowPhase, $seen[0]['custom.conf']);
        $candidate = strpos($seen[0]['main.conf'], 'Include ' . $seen[0]['dir'] . '/custom.conf');
        $crs = strpos($seen[0]['main.conf'], '/rulesets/owasp-crs/setup.conf');
        $this->assertNotFalse($candidate);
        $this->assertNotFalse($crs);
        $this->assertLessThan($crs, $candidate);

        // A set that blocks only an attack lets the plain request through, and goes live.
        $attackOnly = 'SecRule ARGS "@contains <script" "id:1100003,phase:2,deny,status:403,log"';
        $passed = "modsecurity-check first 418\nmodsecurity-check phase1 418\nmodsecurity-check phase2 418\n"
            . "modsecurity-check phase3 418\nmodsecurity-check body 418\nmodsecurity-check plain 204\n";
        [$error, $restarts, $seen] = $save($passed, $attackOnly);
        $this->assertNull($error);
        $this->assertSame($attackOnly, (string) file_get_contents($this->live()));
        $this->assertSame(1, $restarts);
        $this->assertCount(2, $seen);
        $this->assertStringEndsWith('exec -T sites-http nginx -t', $seen[1]['command']);
    }

    /**
     * Saves $rules on nginx with the scratch check answering $answers.
     *
     * @return array{0: ?string, 1: int, 2: list<array<string, string>>} the refusal, reloads, sites-http commands
     */
    private function checkedSave(string $answers, string $rules): array
    {
        $system = $this->checkingSystem($answers);
        $modsec = new class ($system) extends Modsec {
            public int $restarts = 0;

            protected function currentWebserver(): string
            {
                return 'nginx-proxy';
            }

            public function restartWebserver(): void
            {
                $this->restarts++;
            }
        };
        try {
            $modsec->saveCustomRules($rules);
            $error = null;
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        }

        return [$error, $modsec->restarts, $system->seen];
    }

    /**
     * A System that runs file commands for real minus sudo, and answers the
     * scratch check with $answers, recording each sites-http command and the
     * test dir's files as it found them.
     *
     * @return System&object{seen: list<array<string, string>>}
     */
    private function checkingSystem(string $answers): System
    {
        return new class ($this->root, $answers) extends System {
            /** @var list<array<string, string>> */
            public array $seen = [];

            public function __construct(private string $root, private string $answers)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root;
            }

            public function composeFilePath(): string
            {
                return $this->root . '/docker-compose.yml';
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

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $seen = ['command' => (string) $cmd];
                $dirs = glob($this->root . '/config/modsecurity/.custom-rules-test-*') ?: [];
                if ($dirs !== []) {
                    $seen['dir'] = $dirs[0];
                    foreach (['check.conf', 'nginx.conf', 'main.conf', 'custom.conf'] as $file) {
                        $seen[$file] = (string) @file_get_contents($dirs[0] . '/' . $file);
                    }
                }
                $this->seen[] = $seen;
                $output = str_contains((string) $cmd, ' sh -c ') ? $this->answers : '';
                $process = new Process(['sh', '-c', 'printf %s "$0"', $output]);
                $process->run();

                return $process;
            }
        };
    }

    public function test_the_live_config_test_names_only_a_rules_error(): void
    {
        $live = function (string $output): ?string {
            $host = new class ($this->root, $output) extends System {
                public function __construct(private string $root, private string $output)
                {
                }

                public function engineDirPath(): string
                {
                    return $this->root;
                }

                public function composeFilePath(): string
                {
                    return $this->root . '/docker-compose.yml';
                }

                public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
                {
                    $process = new Process(['sh', '-c', 'printf %s "$0" >&2; exit 1', $this->output]);
                    $process->run();

                    return $process;
                }
            };

            return (new class ($host) extends Modsec {
                public function live(): ?string
                {
                    return $this->liveConfigTest('nginx-proxy');
                }
            })->live();
        };

        $this->assertSame(
            'nginx: [emerg] "modsecurity_rules_file" directive Rules error. File: /x/custom.conf. Line: 2.',
            $live("nginx: [emerg] \"modsecurity_rules_file\" directive Rules error. File: /x/custom.conf. Line: 2.\n")
        );
        // What nginx says about another site's vhost goes to the log, not to the caller.
        $this->assertSame(
            'the webserver configuration does not pass its own test; what it said is in the engine log',
            $live("nginx: [emerg] cannot load certificate \"/home/someone/ssl/site.pem\" in /etc/nginx/conf.d/site.conf:9\n"
                . "nginx: configuration file /etc/nginx/nginx.conf test failed\n")
        );
    }

    public function test_the_live_config_test_is_the_webservers_own(): void
    {
        $this->assertSame('nginx -t', Modsec::liveConfigTestCommand('nginx-proxy'));
        $this->assertSame('nginx -t', Modsec::liveConfigTestCommand('nginx'));
        $this->assertSame('apachectl -t', Modsec::liveConfigTestCommand('apache'));
        $this->assertNull(Modsec::liveConfigTestCommand('litespeed'));
    }

    public function test_the_verdict_is_the_line_the_parser_refused_on(): void
    {
        $this->assertNull(Modsec::configTestProblem(0, "nginx: configuration file x test is successful\n"));
        $this->assertSame(
            'nginx: [emerg] "modsecurity_rules_file" directive Rules error. File: /x/custom.conf. Line: 1. Column: 50. Expecting an action, got:  nosuchaction"',
            Modsec::configTestProblem(1, "WARN[0000] compose noise\n"
                . "2026/10/03 15:49:36 [emerg] 5920#5920: \"modsecurity_rules_file\" directive Rules error.\n"
                . "nginx: [emerg] \"modsecurity_rules_file\" directive Rules error. File: /x/custom.conf. Line: 1. Column: 50. Expecting an action, got:  nosuchaction\"\n"
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
