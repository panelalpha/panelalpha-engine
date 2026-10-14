<?php

namespace Tests\Unit;

use App\Lib\Lighthouse\LighthouseFailed;
use App\Lib\Lighthouse\LighthouseRunner;
use App\System;
use PHPUnit\Framework\TestCase;

/**
 * The report is looked for, read and removed by the runner; the controller only
 * turns a failure into a 502 with the runner's message.
 */
class LighthouseRunnerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-lighthouse-run-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/data/lighthouse', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_the_report_is_read_and_removed(): void
    {
        $system = $this->system((string) json_encode(['categories' => ['performance' => ['score' => 0.9]]]));

        $report = (new LighthouseRunner($system))->report('https://a.test/', true, 'MAP a.test 10.0.0.1');

        $this->assertSame(['categories' => ['performance' => ['score' => 0.9]]], $report);
        $this->assertCount(2, $system->commands, 'lighthouse, then removing the report');
        $this->assertSame(['sudo', 'rm', '-rf', $this->reportPath(true)], $system->commands[1]);
        $run = $system->commands[0];
        $this->assertContains('/data/' . basename($this->reportPath(true)), $run);
        $this->assertContains('--preset=desktop', $run);
        $this->assertStringContainsString('--host-resolver-rules="MAP a.test 10.0.0.1"', implode(' ', $run));
    }

    public function test_no_report_is_report_file_not_found_and_nothing_is_removed(): void
    {
        $system = $this->system(null);

        $this->assertFailure('report file not found', $system);
        $this->assertCount(1, $system->commands);
    }

    public function test_an_unparseable_report_is_still_removed(): void
    {
        $system = $this->system('not json');

        $this->assertFailure('cannot parse report file', $system);
        $this->assertSame(['sudo', 'rm', '-rf', $this->reportPath(false)], $system->commands[1]);
    }

    public function test_a_failed_run_carries_its_own_message(): void
    {
        $system = $this->system(null, 'service "lighthouse" is not running');

        $this->assertFailure('service "lighthouse" is not running', $system);
    }

    private function assertFailure(string $message, System $system): void
    {
        try {
            (new LighthouseRunner($system))->report('https://a.test/', false, '');
            $this->fail('Expected LighthouseFailed');
        } catch (LighthouseFailed $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function reportPath(bool $desktop): string
    {
        return $this->root . '/data/lighthouse/' . md5('https://a.test/') . ($desktop ? '-desktop' : '-mobile') . '.json';
    }

    /**
     * Plays the lighthouse container: writes $report where the runner looks, or nothing when null.
     *
     * @return System&object{commands: list<list<string>>}
     */
    private function system(?string $report, ?string $runError = null): System
    {
        return new class ($this->root, $report, $runError) extends System {
            /** @var list<list<string>> */
            public array $commands = [];

            public function __construct(private string $root, private ?string $report, private ?string $runError)
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

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = (array) $cmd;
                $this->commands[] = $cmd;
                $i = array_search('--output-path', $cmd, true);
                if ($i !== false) {
                    if ($this->runError !== null) {
                        throw new \RuntimeException($this->runError);
                    }
                    if ($this->report !== null) {
                        file_put_contents($this->root . '/data/lighthouse/' . basename($cmd[$i + 1]), $this->report);
                    }
                }

                return '';
            }
        };
    }
}
