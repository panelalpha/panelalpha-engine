<?php

namespace Tests\Unit\System\Services;

use App\System;
use App\System\Services\Modsec;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Rule file names are joined to rules/ and renamed as root: nothing may climb out. */
class ModsecToggleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-modsec-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config/modsecurity/rulesets/owasp/rules', 0777, true);
        touch($this->root . '/config/modsecurity/rulesets/owasp/rules/REQUEST-901.conf');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    private function system(): System
    {
        return new class ($this->root) extends System {
            /** @var list<list<string>> */
            public array $ran = [];

            public function __construct(private string $root)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->ran[] = (array) $cmd;
                $process = Process::fromShellCommandline('true');
                $process->run();

                return $process;
            }
        };
    }

    public function test_a_traversal_is_refused_before_anything_runs(): void
    {
        $system = $this->system();

        foreach (['../../../../etc/nginx/conf.d/x.conf', '..', 'rules/../x.conf'] as $name) {
            try {
                (new Modsec($system))->toggleConfigFiles('owasp', [], [$name]);
                $this->fail("{$name} was accepted");
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertSame([], $system->ran);
    }

    public function test_a_plain_file_name_is_toggled(): void
    {
        $system = $this->system();
        $modsec = new class ($system) extends Modsec {
            public function restartWebserver(): void
            {
            }
        };

        $modsec->toggleConfigFiles('owasp', [], ['REQUEST-901.conf']);

        $this->assertSame('mv', $system->ran[0][1] ?? null);
        $this->assertStringEndsWith('/rules/REQUEST-901.conf.disabled', $system->ran[0][3] ?? '');
    }

    public function test_the_request_pattern(): void
    {
        $this->assertSame(1, preg_match(Modsec::CONFIG_FILE_NAME, 'REQUEST-901-INITIALIZATION.conf'));
        $this->assertSame(0, preg_match(Modsec::CONFIG_FILE_NAME, '../x.conf'));
        $this->assertSame(0, preg_match(Modsec::CONFIG_FILE_NAME, '.hidden.conf'));
        $this->assertSame(0, preg_match(Modsec::CONFIG_FILE_NAME, 'x.conf.disabled'));
    }
}
