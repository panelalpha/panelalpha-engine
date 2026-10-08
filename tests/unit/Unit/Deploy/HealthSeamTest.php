<?php

namespace Tests\Unit\Deploy;

use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\ShellOperations;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The seam between the health check and the check runner.
 *
 * Every other test asks one side of this directly: CheckRunnerTest calls
 * run(ProbedResponse), and PathAndJsonChecksTest calls runByPath([...]). Both
 * passed while the call between them was wrong, because nothing drove
 * AppHealth::runChecks() at all -- it was handed the *map* responses() builds
 * to an entry point that takes a single response:
 *
 *     TypeError: CheckRunner::run(): Argument #1 ($response) must be of type
 *     ProbedResponse, array given
 *
 * and its own catch (\Throwable) turned that into
 * `serving: unknown, checks: []`. So every deploy on the engine reported that
 * no check had run, and nothing said so: a site with a dead database, a
 * missing entry point and a healthy one were indistinguishable. It was found
 * by reading GET /projects/<account>/app/health on a live engine, where 29
 * applications in a row returned an empty checks list.
 *
 * What is asserted here is only that the seam carries a verdict: the port
 * answered, so the checks must have run and reported. A regression that
 * empties checks fails this, whatever its cause.
 */
class HealthSeamTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public const PROBE_OUTPUT = "8000\thttp\t200 0.012345\t"
        . 'PGh0bWw+PGhlYWQ+PHRpdGxlPkFwcGxpY2F0aW9uPC90aXRsZT48L2hlYWQ+PGJvZHk+PHA+V2VsY29tZSB0byB0aGUgYXBwPC9wPjwvYm9keT48L2h0bWw+'
        . "\n";

    /**
     * The account answers on one port and the health check is asked for its
     * verdict.
     *
     * ports() is answered from the compose file, and the two shell calls
     * (check()'s probe and composePs()) are the only subprocesses on the path,
     * so the real AppHealth runs with two stubs.
     */
    private function health(): AppHealth
    {
        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('userAppComposeFileToRun')->andReturn('/home/acme/docker-compose.yml');
        $dind->shouldReceive('userAppDirPath')->andReturn('/home/acme/project');
        // The argv of the compose call, so composePs() reaches the stub below
        // rather than throwing on an unexpected method.
        $dind->shouldReceive('userAppComposeCommand')
            ->andReturn(['docker', 'compose', '-f', '/home/acme/docker-compose.yml', 'ps', '--format', 'json', '--all']);

        // The account's own record, which is where the frozen deploy details
        // live -- the runtime whose checks are asked, and the recipe's checks
        // directory when it has one.
        $record = new \App\Models\User();
        $record->details = [AppHealth::DETAIL_RUNTIME => 'php'];
        $dind->shouldReceive('userModel')->andReturn($record);
        $dind->shouldReceive('composeFilePath')->andReturn('/home/acme/docker-compose.yml');
        $dind->shouldReceive('username')->andReturn('acme');

        $system = Mockery::mock(System::class);
        $system->shouldReceive('exec')->andReturnUsing(function (string|array $cmd): string {
            $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
            if (str_contains($line, 'curl') || str_contains($line, 'bash')) {
                return self::PROBE_OUTPUT;
            }

            return '';
        });
        $dind->shouldReceive('system')->andReturn($system);
        $dind->shouldReceive('shell')->andReturnUsing(fn (): ShellOperations => new ShellOperations($dind));

        // ports() reads the account's compose file through a subprocess, which
        // is not what this test is about: the seam under test is the one from
        // check() through runChecks() to the runner. One published port, and
        // the real check() does the rest.
        return new class ($dind) extends AppHealth {
            public function __construct(Dind $dind)
            {
                parent::__construct($dind);
            }

            public function ports(): array
            {
                return [8000];
            }
        };
    }

    public function test_the_checks_actually_run_and_are_reported(): void
    {
        $verdict = $this->health()->check();

        $this->assertNotEmpty(
            $verdict['checks'],
            'a port answered, so the checks ran -- an empty list means the seam dropped them'
        );
        // The runtime's own checks plus the baseline, each a real result
        // rather than a "could not run" placeholder.
        $ids = array_column($verdict['checks'], 'id');
        $this->assertContains('no-server-error', $ids, 'the baseline is always asked');
        $this->assertContains('php-executes', $ids, 'a php account is asked its runtime checks');
        $this->assertNotSame(
            'unknown',
            $verdict['serving'],
            'a port answered 200 with a real page, so the verdict cannot be "unknown"'
        );
    }

    /**
     * The failure the seam produced, stated as a regression test: whatever else
     * changes, runChecks() may not answer an empty check list for an account
     * whose port answered.
     */
    public function test_a_verdict_is_not_silently_emptied_by_the_seam(): void
    {
        $verdict = $this->health()->check();

        $this->assertNotSame(
            ['serving' => 'unknown', 'checks' => []],
            ['serving' => $verdict['serving'], 'checks' => $verdict['checks']],
            'this is exactly the payload every deploy reported while the call site was wrong'
        );
    }
}
