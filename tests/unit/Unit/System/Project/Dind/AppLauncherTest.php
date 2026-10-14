<?php

namespace Tests\Unit\System\Project\Dind;

use PHPUnit\Framework\TestCase;

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

    public function test_compose_up_reads_the_deploys_registry_logins_when_there_are_any(): void
    {
        $command = ['env', 'PWD=/home/a/project', 'docker', 'compose', 'up', '-d'];

        $this->assertSame($command, \App\System\Project\Dind\AppLauncher::withDockerConfig($command, null));
        $this->assertSame(
            ['env', 'DOCKER_CONFIG=/home/a/.panelalpha-registry-x', 'PWD=/home/a/project', 'docker', 'compose', 'up', '-d'],
            \App\System\Project\Dind\AppLauncher::withDockerConfig($command, '/home/a/.panelalpha-registry-x')
        );
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
