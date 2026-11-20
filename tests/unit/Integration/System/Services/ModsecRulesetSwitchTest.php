<?php

namespace Tests\Integration\System\Services;

use App\Models\Setting;
use App\System;
use App\System\Services\Modsec;
use App\System\Services\Webserver;
use App\System\Services\Webserver\WebserverInterface;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;

class ModsecRulesetSwitchTest extends TestCase
{
    private string $root;

    /** @var object{applies: int, lockFreeDuringApply: list<bool>} */
    private object $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-modsec-switch-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/templates/config', 0777, true);
        mkdir($this->root . '/config/modsecurity/rulesets/owasp-crs/rules', 0777, true);
        mkdir($this->root . '/config/modsecurity/rulesets/panelalpha-wordpress/rules', 0777, true);
        copy(
            dirname(__DIR__, 5) . '/templates/config/modsecurity-main.conf.blade.php',
            $this->root . '/templates/config/modsecurity-main.conf.blade.php'
        );
        Setting::setRuntimeSettings(['modsec' => json_encode(['mode' => 'on', 'enabled_rulesets' => ['owasp-crs']])]);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_failed_apply_keeps_the_live_setting_and_a_retry_applies_it(): void
    {
        $modsec = $this->modsec(failures: 1);

        try {
            $modsec->setRulesetEnabled('owasp-crs', false);
            $this->fail('The failed apply was not reported');
        } catch (\RuntimeException) {
        }

        $this->assertSame(['owasp-crs'], Setting::getModsecConfig()['enabled_rulesets']);
        $this->assertStringContainsString('rulesets/owasp-crs/rules/*.conf', $this->mainConf());

        $modsec->setRulesetEnabled('owasp-crs', false);

        $this->assertSame(2, $this->probe->applies);
        $this->assertSame([], Setting::getModsecConfig()['enabled_rulesets']);
        $this->assertStringNotContainsString('rulesets/owasp-crs/', $this->mainConf());
    }

    public function test_the_setting_is_written_and_applied_while_the_lock_is_held(): void
    {
        $modsec = $this->modsec(failures: 0);

        $modsec->setRulesetEnabled('panelalpha-wordpress', true);

        $this->assertSame([false], $this->probe->lockFreeDuringApply);
        $this->assertSame(['owasp-crs', 'panelalpha-wordpress'], Setting::getModsecConfig()['enabled_rulesets']);
        $this->assertTrue(Cache::lock(Modsec::RULESETS_LOCK, 1)->get());
    }

    public function test_switching_to_the_current_state_applies_nothing(): void
    {
        $modsec = $this->modsec(failures: 0);

        $modsec->setRulesetEnabled('owasp-crs', true);
        $modsec->setRulesetEnabled('panelalpha-wordpress', false);

        $this->assertSame(0, $this->probe->applies);
    }

    private function mainConf(): string
    {
        return (string) file_get_contents($this->root . '/config/modsecurity/main.conf');
    }

    /** A Modsec whose webserver apply is the probe, failing its first $failures calls. */
    private function modsec(int $failures): Modsec
    {
        $this->probe = new class ($failures) {
            public int $applies = 0;

            /** @var list<bool> */
            public array $lockFreeDuringApply = [];

            public function __construct(private int $failures)
            {
            }

            public function __invoke(): void
            {
                $this->applies++;
                $lock = Cache::lock(Modsec::RULESETS_LOCK, 1);
                $this->lockFreeDuringApply[] = $lock->get();
                $lock->release();
                if ($this->applies <= $this->failures) {
                    throw new \RuntimeException('Refusing to restart sites-http');
                }
            }
        };

        return new Modsec($this->system($this->probe));
    }

    /** The engine dir is the temp root; the webserver applying main.conf is the probe. */
    private function system(callable $apply): System
    {
        return new class ($this->root, $apply) extends System {
            /** @var callable */
            private $apply;

            public function __construct(private string $root, callable $apply)
            {
                $this->apply = $apply;
            }

            public function engineDirPath(): string
            {
                return $this->root;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return $this->runProcess($cmd)->getOutput();
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $process = new Process(LocalRoot::argv((array) $cmd));
                $process->run();

                return $process;
            }

            public function webserver(): Webserver
            {
                return new class ($this, $this->apply) extends Webserver {
                    /** @var callable */
                    private $apply;

                    public function __construct(System $system, callable $apply)
                    {
                        parent::__construct($system);
                        $this->apply = $apply;
                    }

                    public function driver(?string $slug = null): WebserverInterface
                    {
                        return $this;
                    }

                    public function toggleModsecurity(): void
                    {
                        ($this->apply)();
                    }
                };
            }
        };
    }
}
