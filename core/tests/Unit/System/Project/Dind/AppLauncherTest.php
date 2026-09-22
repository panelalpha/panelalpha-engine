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
        $source = file_get_contents(dirname(__DIR__, 5) . '/app/System/Project/Dind/AppLauncher.php');

        $start = $this->methodBody($source, 'start');
        $success = strpos($start, 'getExitCode() === 0');
        $this->assertNotFalse($success, 'start() no longer branches on a successful up.');

        $afterUp = substr($start, $success);
        $this->assertStringContainsString('appHealth()->report()', $afterUp);
        $this->assertStringContainsString('appCertificate()->remember()', $afterUp);
        $this->assertLessThan(
            strpos($afterUp, 'appCertificate()->remember()'),
            strpos($afterUp, 'appHealth()->report()'),
            'The health report is written before the certificate snapshot.'
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
