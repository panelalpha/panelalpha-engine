<?php

namespace Tests\Unit;

use App\Lib\Lighthouse\LighthouseFailed;
use App\Lib\Lighthouse\LighthouseRunner;
use App\Lib\Lighthouse\LighthouseTarget;
use App\System;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;

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
        putenv('PA_ROOT_LOCAL_ROOTS=' . json_encode(['engine' => $this->root]));
    }

    protected function tearDown(): void
    {
        putenv('PA_ROOT_LOCAL_ROOTS');
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_the_report_is_read_and_removed(): void
    {
        $system = $this->system((string) json_encode(['categories' => ['performance' => ['score' => 0.9]]]));

        $report = (new LighthouseRunner($system))->report('https://a.test/', true, 'MAP a.test '.TestNetwork::private(1));

        $this->assertSame(['categories' => ['performance' => ['score' => 0.9]]], $report);
        $this->assertCount(1, $system->commands, 'only lighthouse: the engine removes the report itself');
        $this->assertFileDoesNotExist($this->reportPath(true));
        $this->assertSame([
            LocalRoot::CLIENT, 'compose.lighthouseAudit', '--', $this->root . '/docker-compose.yml',
            'https://a.test/', 'desktop', 'MAP a.test ' . TestNetwork::private(1),
        ], $system->commands[0]);
    }

    public function test_a_run_holds_the_lock_of_its_url_and_form_factor_only(): void
    {
        $system = $this->system((string) json_encode(['ok' => true]));

        (new LighthouseRunner($system))->report('https://a.test/', true, LighthouseTarget::resolverRules(null, null));

        $this->assertSame(['desktop' => false, 'mobile' => true], $system->locksFreeDuringRun);
        $this->assertTrue($this->lockIsFree(true), 'the lock is let go once the report is read');
    }

    public function test_a_failed_run_lets_go_of_the_lock(): void
    {
        $system = $this->system(null, 'service "lighthouse" is not running');

        $this->assertFailure('service "lighthouse" is not running', $system);
        $this->assertTrue($this->lockIsFree(false));
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
        $this->assertFileDoesNotExist($this->reportPath(false));
    }

    public function test_a_failed_run_carries_its_own_message(): void
    {
        $system = $this->system(null, 'service "lighthouse" is not running');

        $this->assertFailure('service "lighthouse" is not running', $system);
    }

    private function assertFailure(string $message, System $system): void
    {
        try {
            (new LighthouseRunner($system))->report('https://a.test/', false, LighthouseTarget::resolverRules(null, null));
            $this->fail('Expected LighthouseFailed');
        } catch (LighthouseFailed $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function reportPath(bool $desktop): string
    {
        return $this->root . '/data/lighthouse/' . md5('https://a.test/') . ($desktop ? '-desktop' : '-mobile') . '.json';
    }

    private function lockIsFree(bool $desktop): bool
    {
        return self::freeLock(substr($this->reportPath($desktop), 0, -strlen('.json')) . '.lock');
    }

    public static function freeLock(string $path): bool
    {
        $probe = @fopen($path, 'c');
        if ($probe === false) {
            return true;
        }
        $free = flock($probe, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($probe, LOCK_UN);
        }
        fclose($probe);

        return $free;
    }

    /**
     * Plays the lighthouse container: writes $report where the runner looks, or nothing when null.
     *
     * @return System&object{commands: list<list<string>>, locksFreeDuringRun: array<string, bool>}
     */
    private function system(?string $report, ?string $runError = null): System
    {
        return new class ($this->root, $report, $runError) extends System {
            /** @var list<list<string>> */
            public array $commands = [];

            /** @var array<string, bool> whether each form factor's lock for the URL was free while lighthouse ran */
            public array $locksFreeDuringRun = [];

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
                $argv = array_values((array) $cmd);
                $this->commands[] = $argv;
                if (LocalRoot::action($argv) === 'compose.lighthouseAudit') {
                    foreach (['desktop', 'mobile'] as $form) {
                        $lock = $this->root . '/data/lighthouse/' . md5('https://a.test/') . "-{$form}.lock";
                        $this->locksFreeDuringRun[$form] = LighthouseRunnerTest::freeLock($lock);
                    }
                    if ($this->runError !== null) {
                        throw new \RuntimeException($this->runError);
                    }
                    if ($this->report !== null) {
                        $form = ($argv[5] ?? '') === 'desktop' ? '-desktop' : '-mobile';
                        file_put_contents($this->root . '/data/lighthouse/' . md5('https://a.test/') . $form . '.json', $this->report);
                    }
                }

                return '';
            }
        };
    }
}
