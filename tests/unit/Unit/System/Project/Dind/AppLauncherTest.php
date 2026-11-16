<?php

namespace Tests\Unit\System\Project\Dind;

use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\AppLauncher;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

class AppLauncherTest extends TestCase
{
    // The serving verdict (AppHealth::report) and the certificate snapshot are recorded by the
    // launcher right after a successful `up`. Losing that call in a refactor left every deploy
    // path without a verdict and no test noticed, so pin it.
    public function test_start_records_health_and_certificate_after_a_successful_up(): void
    {
        $source = file_get_contents(dirname(__DIR__, 6) . '/core/app/System/Project/Dind/AppLauncher.php');

        $this->assertStringContainsString('$this->outcome(', $this->methodBody($source, 'startWithLogins'));

        $outcome = $this->methodBody($source, 'outcome');
        $failure = strpos($outcome, 'getExitCode() !== 0');
        $this->assertNotFalse($failure, 'start() no longer branches on a successful up.');
        $this->assertStringContainsString('$this->recordServingVerdict()', substr($outcome, $failure));

        $verdict = $this->methodBody($source, 'recordServingVerdict');
        $this->assertStringContainsString('appHealth()->report()', $verdict);
        $this->assertStringContainsString('appCertificate()->remember()', $verdict);
        $this->assertLessThan(
            strpos($verdict, 'appCertificate()->remember()'),
            strpos($verdict, 'appHealth()->report()'),
            'The health report is written before the certificate snapshot.'
        );
    }

    // A failed push puts the target's files and volumes back and starts it asking for new containers:
    // one only started again would keep a mount of the pushed data the rollback removed.
    public function test_a_start_asked_to_recreate_recreates_whatever_the_strategy(): void
    {
        $source = file_get_contents(dirname(__DIR__, 6) . '/core/app/System/Project/Dind/AppLauncher.php');

        $this->assertStringContainsString('$this->startWithLogins($forceRecreate)', $this->methodBody($source, 'start'));
        $this->assertStringContainsString(
            '$this->releaseThenBringUp($skipBuild, $forceRecreate,',
            $this->methodBody($source, 'startWithLogins')
        );
        $this->assertStringContainsString(
            '$forceRecreate || $this->mustForceRecreate($strategy, $runtime)',
            $this->methodBody($source, 'releaseThenBringUp')
        );
    }

    public function test_compose_up_reads_the_deploys_registry_logins_when_there_are_any(): void
    {
        $command = ['env', 'PWD=/home/a/project', 'docker', 'compose', 'up', '-d'];

        $this->assertSame($command, \App\System\Project\Dind\AppLauncher::withDockerConfig($command, null));
        $this->assertSame(
            ['env', 'DOCKER_CONFIG=/home/a/.panelalpha-registry-x', 'PWD=/home/a/project', 'docker', 'compose', 'up', '-d'],
            \App\System\Project\Dind\AppLauncher::withDockerConfig($command, '/home/a/.panelalpha-registry-x')
        );
    }

    /** All of it comes from the account's dockerd, a service's output laid out as compose logs. */
    public function test_a_failed_dependency_is_named_with_what_it_printed_and_its_health_check_said(): void
    {
        $requests = [];
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (string|array $cmd) use (&$requests): string {
            $line = implode(' ', (array) $cmd);
            $requests[] = $line;

            return match (LocalRoot::action((array) $cmd)) {
                'account-docker.logTail' => LocalRoot::values((array) $cmd)[1] === 'project-db-1' ? "mysqld: ready for connections.\n" : "2026-11-10T08:00:00.000000001Z Error: no database\n",
                'account-docker.containers' => '[{"State":{"Health":{"Status":"unhealthy","FailingStreak":3,"Log":['
                    . '{"Start":"","End":"","ExitCode":1,"Output":"ERROR 1045 (28000): Access denied"}]}}}]',
                'account-docker.listContainers' => '[{"Id":"' . str_repeat('e', 64) . '","Names":["/project-migrate-1"]}]',
                default => '',
            };
        });
        $project = $this->createStub(Dind::class);
        $project->method('username')->willReturn('acme');
        $project->method('system')->willReturn($system);
        $project->method('composeFilePath')->willReturn('/home/acme/docker-compose.yml');
        $project->method('userAppComposeProjectName')->willReturn('project');
        $project->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($project));
        $project->method('accountDaemon')->willReturnCallback(fn (): AccountDaemon => new AccountDaemon($project));

        $cause = (new \ReflectionMethod(AppLauncher::class, 'failedDependencyCause'))->invoke(
            new AppLauncher($project),
            "dependency failed to start: container project-db-1 is unhealthy\nservice \"migrate\" didn't complete successfully: exit 1"
        );

        $this->assertSame(
            "error: service project-db-1 did not start (unhealthy) [health check: ERROR 1045 (28000): Access denied]: mysqld: ready for connections.\n"
            . 'error: service migrate did not start (exited with code 1): Error: no database',
            $cause
        );
        $this->assertContains(LocalRoot::CLIENT . ' account-docker.logTail -- acme project-db-1 60 ', $requests);
        $this->assertContains(LocalRoot::CLIENT . ' account-docker.containers -- acme project-db-1', $requests);
        $this->assertContains(
            LocalRoot::CLIENT . ' account-docker.listContainers -- acme all 4 label com.docker.compose.project=project label com.docker.compose.config-hash label com.docker.compose.oneoff=False label com.docker.compose.service=migrate',
            $requests
        );
        $this->assertContains(LocalRoot::CLIENT . ' account-docker.logTail -- acme ' . str_repeat('e', 64) . ' 60 timestamps', $requests);
    }

    private function methodBody(string $source, string $method): string
    {
        $begin = strpos($source, "function {$method}(");
        $this->assertNotFalse($begin, $method);
        preg_match('/\n    (?:public|protected|private)(?: static)? function /', $source, $m, PREG_OFFSET_CAPTURE, $begin + 10);
        $end = $m[0][1] ?? strlen($source);

        return substr($source, $begin, $end - $begin);
    }
}
