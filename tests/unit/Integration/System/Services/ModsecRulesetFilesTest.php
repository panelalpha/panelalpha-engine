<?php

namespace Tests\Integration\System\Services;

use App\Models\Setting;
use App\System;
use App\System\Filesystem;
use App\System\Services\Modsec;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/** What the rule set listing, the toggle, main.conf and a custom rules save do, step by step, on a recorded system. */
class ModsecRulesetFilesTest extends TestCase
{
    private string $root;

    private string $sets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pa-modsec-files-'.bin2hex(random_bytes(4));
        $this->sets = $this->root.'/config/modsecurity/rulesets';
        mkdir($this->root.'/templates/config', 0777, true);
        copy(
            dirname(base_path()).'/templates/config/modsecurity-main.conf.blade.php',
            $this->root.'/templates/config/modsecurity-main.conf.blade.php'
        );
        $this->settings('off', []);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_the_listing_skips_sets_without_rules_and_what_is_not_a_rule_file(): void
    {
        mkdir($this->sets.'/alpha/rules/dir.conf', 0777, true);
        touch($this->sets.'/alpha/rules/x.conf');
        touch($this->sets.'/alpha/rules/y.conf.disabled');
        touch($this->sets.'/alpha/rules/z.conf.bak');
        touch($this->sets.'/alpha/rules/notes.txt');
        mkdir($this->sets.'/beta', 0777, true);
        touch($this->sets.'/beta/setup.conf');
        mkdir($this->sets.'/gamma/rules', 0777, true);
        touch($this->sets.'/plain-file');

        $this->assertSame([
            ['name' => 'alpha', 'config_files' => ['x.conf', 'y.conf.disabled']],
            ['name' => 'gamma', 'config_files' => []],
            ['name' => 'custom', 'config_files' => []],
        ], (new Modsec($this->system()))->getRulesets());
    }

    public function test_toggling_a_set_that_is_not_there_does_nothing(): void
    {
        $system = $this->system();

        $this->modsec($system)->toggleConfigFiles('missing', ['a.conf'], ['b.conf']);

        $this->assertSame([], $system->journal);
    }

    public function test_toggling_renames_what_is_there_enables_first_and_restarts_once(): void
    {
        $rules = $this->sets.'/owasp/rules';
        mkdir($rules, 0777, true);
        touch($rules.'/a.conf');
        touch($rules.'/b.conf.disabled');
        touch($rules.'/c.conf');
        $system = $this->system();

        $this->modsec($system)->toggleConfigFiles('owasp', ['b.conf.disabled', 'c.conf'], ['a.conf', 'b.conf.disabled']);

        $this->assertSame([
            "run mv -- {$rules}/b.conf.disabled {$rules}/b.conf",
            "run mv -- {$rules}/a.conf {$rules}/a.conf.disabled",
            'restart',
        ], $system->journal);
    }

    public function test_toggling_nothing_that_exists_does_not_restart(): void
    {
        mkdir($this->sets.'/owasp/rules', 0777, true);
        touch($this->sets.'/owasp/rules/a.conf');
        touch($this->sets.'/owasp/rules/b.conf.disabled');
        $system = $this->system();

        $this->modsec($system)->toggleConfigFiles('owasp', ['a.conf'], ['b.conf']);

        $this->assertSame([], $system->journal);
    }

    public function test_a_file_name_the_set_does_not_hold_is_refused_before_anything_renames(): void
    {
        $rules = $this->sets.'/owasp/rules';
        mkdir($rules, 0777, true);
        touch($rules.'/a.conf');

        foreach ([['NOPE.conf'], ['a.conf', 'NOPE.conf']] as $disabled) {
            $system = $this->system();

            try {
                $this->modsec($system)->toggleConfigFiles('owasp', [], $disabled);
                $this->fail('An unknown config file must be refused: '.json_encode($disabled));
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Unknown ModSecurity config file: NOPE.conf', $e->getMessage());
            }

            $this->assertSame([], $system->journal);
        }
    }

    public function test_a_rename_that_fails_says_why(): void
    {
        mkdir($this->sets.'/owasp/rules', 0777, true);
        touch($this->sets.'/owasp/rules/a.conf');
        touch($this->sets.'/owasp/rules/b.conf');

        foreach (['mv: denied' => 'mv: denied', '' => 'ModSecurity config file toggle failed'] as $stderr => $message) {
            $system = $this->system(failing: ['mv' => (string) $stderr]);
            try {
                $this->modsec($system)->toggleConfigFiles('owasp', [], ['a.conf', 'b.conf']);
                $this->fail('a failed rename should throw');
            } catch (\RuntimeException $e) {
                $this->assertSame($message, $e->getMessage());
            }
            $this->assertSame(['run mv -- '.$this->sets.'/owasp/rules/a.conf '.$this->sets.'/owasp/rules/a.conf.disabled'], $system->journal);
        }
    }

    public function test_disabling_the_last_file_of_a_loaded_set_is_refused_and_renamed_back(): void
    {
        $rules = $this->loadedSet();
        $system = $this->system();

        try {
            $this->modsec($system, null, 'nginx-proxy', 'Rules error. File: /opt/modsecurity/main.conf. Line: 21.')
                ->toggleConfigFiles('owasp', [], ['a.conf']);
            $this->fail('a toggle the webserver would not load should be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(
                'The webserver would not load the rule set with these files, so nothing changed: '
                .'Rules error. File: /opt/modsecurity/main.conf. Line: 21.',
                $e->getMessage()
            );
        }

        $this->assertSame([
            "run mv -- {$rules}/a.conf {$rules}/a.conf.disabled",
            'currentWebserver',
            'liveConfigTest nginx-proxy',
            "run mv -- {$rules}/a.conf.disabled {$rules}/a.conf",
        ], $system->journal);
    }

    public function test_a_live_test_that_throws_renames_every_file_back_in_reverse(): void
    {
        $rules = $this->loadedSet();
        touch($rules.'/b.conf.disabled');
        $failure = new \RuntimeException('live test timed out');
        $system = $this->system();

        try {
            $this->modsec($system, null, 'nginx-proxy', $failure)->toggleConfigFiles('owasp', ['b.conf'], ['a.conf']);
            $this->fail('the live test failure should come through');
        } catch (\RuntimeException $e) {
            $this->assertSame($failure, $e);
        }

        $this->assertSame([
            "run mv -- {$rules}/b.conf.disabled {$rules}/b.conf",
            "run mv -- {$rules}/a.conf {$rules}/a.conf.disabled",
            'currentWebserver',
            'liveConfigTest nginx-proxy',
            "run mv -- {$rules}/a.conf.disabled {$rules}/a.conf",
            "run mv -- {$rules}/b.conf {$rules}/b.conf.disabled",
        ], $system->journal);
    }

    public function test_a_toggle_the_loaded_set_passes_with_is_tested_then_restarts(): void
    {
        $rules = $this->loadedSet();
        $system = $this->system();

        $this->modsec($system)->toggleConfigFiles('owasp', [], ['a.conf']);

        $this->assertSame([
            "run mv -- {$rules}/a.conf {$rules}/a.conf.disabled",
            'currentWebserver',
            'liveConfigTest nginx-proxy',
            'restart',
        ], $system->journal);
    }

    public function test_a_webserver_with_no_config_test_is_toggled_untested(): void
    {
        $rules = $this->loadedSet();
        $system = $this->system();

        $this->modsec($system, null, 'openlitespeed', 'never asked')->toggleConfigFiles('owasp', [], ['a.conf']);

        $this->assertSame([
            "run mv -- {$rules}/a.conf {$rules}/a.conf.disabled",
            'currentWebserver',
            'restart',
        ], $system->journal);
    }

    public function test_a_set_that_is_not_loaded_is_toggled_untested(): void
    {
        foreach ([['on', ['custom']], ['off', ['owasp']]] as [$mode, $enabled]) {
            $rules = $this->loadedSet();
            $this->settings($mode, $enabled);
            $system = $this->system();

            $this->modsec($system, null, 'nginx-proxy', 'never asked')->toggleConfigFiles('owasp', [], ['a.conf']);

            $this->assertSame(["run mv -- {$rules}/a.conf {$rules}/a.conf.disabled", 'restart'], $system->journal, $mode);
        }
    }

    public function test_main_conf_includes_the_enabled_sets_in_order(): void
    {
        $this->rulesets();
        $this->settings('on', ['owasp-crs', 'missing', 'custom', 'plain']);

        $this->assertSame([
            'SecRuleEngine Off',
            "Include {$this->sets}/owasp-crs/setup.conf",
            "Include {$this->sets}/owasp-crs/rules/*.conf",
            "Include {$this->sets}/custom/rules/*.conf",
            "Include {$this->sets}/plain/rules/*.conf",
        ], $this->directives($this->renderMainConf('Off', null)));

        $this->assertSame([
            'SecRuleEngine On',
            "Include {$this->sets}/owasp-crs/setup.conf",
            "Include {$this->sets}/owasp-crs/rules/*.conf",
            'Include /scratch/custom.conf',
            "Include {$this->sets}/plain/rules/*.conf",
        ], $this->directives($this->renderMainConf('On', '/scratch/custom.conf')));
    }

    public function test_a_candidate_for_a_set_that_is_not_enabled_comes_last(): void
    {
        $this->rulesets();
        $this->settings('detection_only', ['plain', 'owasp-crs']);

        $this->assertSame([
            'SecRuleEngine DetectionOnly',
            "Include {$this->sets}/plain/rules/*.conf",
            "Include {$this->sets}/owasp-crs/setup.conf",
            "Include {$this->sets}/owasp-crs/rules/*.conf",
            'Include /scratch/custom.conf',
        ], $this->directives($this->renderMainConf('DetectionOnly', '/scratch/custom.conf')));

        $this->assertSame([
            'SecRuleEngine DetectionOnly',
            "Include {$this->sets}/plain/rules/*.conf",
            "Include {$this->sets}/owasp-crs/setup.conf",
            "Include {$this->sets}/owasp-crs/rules/*.conf",
        ], $this->directives($this->renderMainConf('DetectionOnly', null)));
    }

    public function test_main_conf_is_the_template_around_the_includes(): void
    {
        $this->rulesets();
        $this->settings('on', ['plain']);

        $rendered = str_replace($this->sets, '{sets}', $this->renderMainConf('On', null));

        $this->assertSame(
            "# Turn the engine on globally\nSecRuleEngine On\n\n# Request body settings\nSecRequestBodyAccess On\n"
            ."SecRequestBodyLimit 13107200\n\n# Response body settings (disable unless you really need it)\n"
            ."SecResponseBodyAccess Off\n\n# Audit logging\nSecAuditEngine RelevantOnly\n"
            ."SecAuditLog /opt/panelalpha/shared-hosting/logs/modsecurity/audit.log\nSecAuditLogFormat JSON\n"
            ."SecAuditLogParts ABFHZ\n\n# Debug logging (optional, noisy, enable only for troubleshooting)\n"
            ."# SecDebugLog /opt/panelalpha/shared-hosting/logs/modsecurity/debug.log\n# SecDebugLogLevel 3\n\n"
            ."Include {sets}/plain/rules/*.conf\n"
            .'SecRule REQUEST_URI "@beginsWith /phpmyadmin" "id:1000001,phase:1,pass,nolog,ctl:ruleRemoveById=949110"',
            rtrim($rendered, "\n")
        );
    }

    public function test_a_save_tests_a_scratch_copy_then_the_live_file_then_restarts(): void
    {
        $this->rulesets();
        $this->settings('on', ['custom']);
        $system = $this->system();
        $modsec = $this->modsec($system);

        $modsec->saveCustomRules('SecMarker A');

        $scratch = $this->root.'/config/modsecurity/.custom-rules-test-X';
        $live = $this->sets.'/custom/rules/custom.conf';
        $this->assertSame([
            'currentWebserver',
            "write {$scratch}/custom.conf",
            "write {$scratch}/main.conf",
            "configTest nginx-proxy {$scratch}",
            "run rm -rf -- {$scratch}",
            "exists {$live}",
            "exists {$live}.disabled",
            "exists {$live}",
            "write {$live}",
            'liveConfigTest nginx-proxy',
            'restart',
        ], $this->journal($system));
        $this->assertSame('SecMarker A', file_get_contents($live));
        $this->assertStringContainsString(
            "Include {$scratch}/custom.conf",
            preg_replace('/\.custom-rules-test-[0-9a-f]{12}/', '.custom-rules-test-X', $modsec->mainConfs[0])
        );
        $this->assertStringContainsString('SecRuleEngine On', $modsec->mainConfs[0]);
    }

    public function test_a_rule_the_static_checks_refuse_touches_nothing(): void
    {
        $system = $this->system();

        try {
            $this->modsec($system)->saveCustomRules('Include /etc/passwd');
            $this->fail('the rule should be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(Modsec::customRulesProblem('Include /etc/passwd'), $e->getMessage());
        }

        $this->assertSame([], $system->journal);
    }

    public function test_a_scratch_refusal_or_failure_leaves_the_live_file_alone(): void
    {
        $this->rulesets();
        $this->settings('on', ['custom']);
        $scratch = $this->root.'/config/modsecurity/.custom-rules-test-X';
        $failure = new \RuntimeException('test exploded');

        foreach (['refused' => 'the parser said no', 'failed' => $failure] as $verdict) {
            $system = $this->system();
            try {
                $this->modsec($system, $verdict)->saveCustomRules('SecMarker A');
                $this->fail('the save should not go through');
            } catch (\Throwable $e) {
                if ($verdict instanceof \Throwable) {
                    $this->assertSame($failure, $e);
                } else {
                    $this->assertInstanceOf(\InvalidArgumentException::class, $e);
                    $this->assertSame('The webserver refused the rules: the parser said no', $e->getMessage());
                }
            }

            $this->assertSame([
                'currentWebserver',
                "write {$scratch}/custom.conf",
                "write {$scratch}/main.conf",
                "configTest nginx-proxy {$scratch}",
                "run rm -rf -- {$scratch}",
            ], $this->journal($system));
        }
    }

    public function test_rules_that_are_not_loaded_are_only_written(): void
    {
        $this->rulesets();
        $live = $this->sets.'/custom/rules/custom.conf';

        foreach ([['on', ['owasp-crs']], ['off', ['custom']]] as [$mode, $enabled]) {
            $this->settings($mode, $enabled);
            @unlink($live);
            $system = $this->system();

            $this->modsec($system, null, 'apache')->saveCustomRules('SecMarker B');

            $scratch = $this->root.'/config/modsecurity/.custom-rules-test-X';
            $this->assertSame([
                'currentWebserver',
                "write {$scratch}/custom.conf",
                "write {$scratch}/main.conf",
                "configTest apache {$scratch}",
                "run rm -rf -- {$scratch}",
                "exists {$live}",
                "exists {$live}.disabled",
                "exists {$live}",
                "write {$live}",
            ], $this->journal($system), $mode);
        }
    }

    public function test_a_live_test_that_throws_puts_the_previous_rules_back(): void
    {
        $this->rulesets();
        $this->settings('on', ['custom']);
        $live = $this->sets.'/custom/rules/custom.conf';
        file_put_contents($live, 'SecMarker OLD');
        $failure = new \RuntimeException('live test timed out');
        $system = $this->system();

        try {
            $this->modsec($system, null, 'nginx-proxy', $failure)->saveCustomRules('SecMarker NEW');
            $this->fail('the live test failure should come through');
        } catch (\RuntimeException $e) {
            $this->assertSame($failure, $e);
        }

        $this->assertSame([
            "exists {$live}",
            "exists {$live}",
            "read {$live}",
            "write {$live}",
            'liveConfigTest nginx-proxy',
            "write {$live}",
        ], array_slice($this->journal($system), 5));
        $this->assertSame('SecMarker OLD', file_get_contents($live));
    }

    public function test_a_live_refusal_removes_first_rules_and_says_why(): void
    {
        $this->rulesets();
        $this->settings('on', ['custom']);
        $live = $this->sets.'/custom/rules/custom.conf';
        $system = $this->system();

        try {
            $this->modsec($system, null, 'nginx-proxy', 'Rules error at line 1')->saveCustomRules('SecMarker NEW');
            $this->fail('the live refusal should throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('The webserver refused the rules in place, so the previous rules stay live: Rules error at line 1', $e->getMessage());
        }

        $this->assertSame([
            "exists {$live}",
            "write {$live}",
            'liveConfigTest nginx-proxy',
            "run rm -f -- {$live}",
        ], array_slice($this->journal($system), 7));
    }

    public function test_a_restore_that_cannot_remove_the_file_throws_its_own_failure(): void
    {
        $this->rulesets();
        $this->settings('on', ['custom']);
        $system = $this->system(failing: ['rm -f' => 'rm: denied']);

        try {
            $this->modsec($system, null, 'nginx-proxy', 'Rules error')->saveCustomRules('SecMarker NEW');
            $this->fail('the restore failure should throw');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(\InvalidArgumentException::class, $e);
            $this->assertSame('rm: denied', $e->getMessage());
        }
    }

    private function loadedSet(): string
    {
        $rules = $this->sets.'/owasp/rules';
        if (! is_dir($rules)) {
            mkdir($rules, 0777, true);
        }
        touch($rules.'/a.conf');
        $this->settings('on', ['owasp']);

        return $rules;
    }

    private function rulesets(): void
    {
        mkdir($this->sets.'/owasp-crs/rules', 0777, true);
        touch($this->sets.'/owasp-crs/setup.conf');
        mkdir($this->sets.'/custom/rules', 0777, true);
        mkdir($this->sets.'/plain/rules', 0777, true);
    }

    /** @param list<string> $enabled */
    private function settings(string $mode, array $enabled): void
    {
        Setting::setRuntimeSettings(['modsec' => json_encode(['mode' => $mode, 'enabled_rulesets' => $enabled])]);
    }

    private function renderMainConf(string $mode, ?string $customRules): string
    {
        $render = new \ReflectionMethod(Modsec::class, 'renderMainConf');

        return $render->invoke(new Modsec($this->system()), $mode, $customRules);
    }

    /** @return list<string> */
    private function directives(string $mainConf): array
    {
        preg_match_all('/^(?:SecRuleEngine|Include) .*$/m', $mainConf, $m);

        return $m[0];
    }

    /** @return list<string> */
    private function journal(System $system): array
    {
        return array_map(
            fn (string $entry): string => preg_replace('/\.custom-rules-test-[0-9a-f]{12}/', '.custom-rules-test-X', $entry),
            $system->journal
        );
    }

    private function modsec(
        System $system,
        string|\Throwable|null $verdict = null,
        string $webserver = 'nginx-proxy',
        string|\Throwable|null $liveVerdict = null,
    ): Modsec {
        return new class($system, $verdict, $webserver, $liveVerdict) extends Modsec
        {
            /** @var list<string> */
            public array $mainConfs = [];

            public function __construct(
                private System $recording,
                private string|\Throwable|null $verdict,
                private string $webserver,
                private string|\Throwable|null $liveVerdict,
            ) {
                parent::__construct($recording);
            }

            protected function configTest(string $webserver, string $testDir): ?string
            {
                $this->recording->journal[] = "configTest {$webserver} {$testDir}";
                $this->mainConfs[] = (string) file_get_contents($testDir.'/main.conf');
                if ($this->verdict instanceof \Throwable) {
                    throw $this->verdict;
                }

                return $this->verdict;
            }

            protected function liveConfigTest(string $webserver): ?string
            {
                $this->recording->journal[] = "liveConfigTest {$webserver}";
                if ($this->liveVerdict instanceof \Throwable) {
                    throw $this->liveVerdict;
                }

                return $this->liveVerdict;
            }

            protected function currentWebserver(): string
            {
                $this->recording->journal[] = 'currentWebserver';

                return $this->webserver;
            }

            public function restartWebserver(): void
            {
                $this->recording->journal[] = 'restart';
            }
        };
    }

    /**
     * @param  array<string, string>  $failing  prefix of the command the broker runs => what it prints on stderr
     * @return System&object{journal: list<string>}
     */
    private function system(array $failing = []): System
    {
        return new class($this->root, $failing) extends System
        {
            /** @var list<string> */
            public array $journal = [];

            /** @param array<string, string> $failing */
            public function __construct(private string $root, private array $failing) {}

            public function engineDirPath(): string
            {
                return $this->root;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $line = implode(' ', LocalRoot::plain((array) $cmd));
                $this->journal[] = "run {$line}";
                foreach ($this->failing as $prefix => $stderr) {
                    if (str_starts_with($line, $prefix)) {
                        return $this->finished(1, $stderr);
                    }
                }
                if (str_starts_with($line, 'rm -f -- ')) {
                    @unlink(substr($line, strlen('rm -f -- ')));
                }

                return $this->finished(0, '');
            }

            private function finished(int $exitCode, string $stderr): Process
            {
                return new class($exitCode, $stderr) extends Process
                {
                    public function __construct(private int $code, private string $stderr)
                    {
                        parent::__construct(['true']);
                    }

                    public function getExitCode(): ?int
                    {
                        return $this->code;
                    }

                    public function isSuccessful(): bool
                    {
                        return $this->code === 0;
                    }

                    public function getOutput(): string
                    {
                        return '';
                    }

                    public function getErrorOutput(): string
                    {
                        return $this->stderr;
                    }
                };
            }

            public function filesystem(): Filesystem
            {
                return new class($this) extends Filesystem
                {
                    public function __construct(private System $recording)
                    {
                        parent::__construct($recording);
                    }

                    public function writeFileReplacingPath(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->recording->journal[] = "write {$path}";
                        if (! is_dir(dirname($path))) {
                            mkdir(dirname($path), 0777, true);
                        }
                        file_put_contents($path, $contents);
                    }

                    public function fileExists(string $path): bool
                    {
                        $this->recording->journal[] = "exists {$path}";

                        return is_file($path);
                    }

                    public function fileGetContents(string $path): string
                    {
                        if (! str_ends_with($path, '.blade.php')) {
                            $this->recording->journal[] = "read {$path}";
                        }

                        return (string) file_get_contents($path);
                    }

                    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        throw new \LogicException("unexpected filePutContents {$path}");
                    }
                };
            }
        };
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path.'/'.$entry);
            }
        }
        rmdir($path);
    }
}
