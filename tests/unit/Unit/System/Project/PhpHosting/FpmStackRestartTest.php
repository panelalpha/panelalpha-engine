<?php

namespace Tests\Unit\System\Project\PhpHosting;

use App\System\Project\PhpHosting\FpmStack;
use PHPUnit\Framework\TestCase;
use Tests\Support\FpmStackRestartFixtures;

class FpmStackRestartTest extends TestCase
{
    use FpmStackRestartFixtures;

    public function test_script_targets_only_this_versions_master(): void
    {
        $pattern = $this->masterPattern($this->runnerScript('8.4'));

        $this->assertMatchesRegularExpression($pattern, 'php-fpm: master process (/etc/php/8.4/fpm/php-fpm.conf)');
        $this->assertDoesNotMatchRegularExpression($pattern, 'php-fpm: master process (/etc/php/8.3/fpm/php-fpm.conf)');
        $this->assertDoesNotMatchRegularExpression($pattern, 'php-fpm: master process (/etc/php/8x4/fpm/php-fpm.conf)');
        $this->assertDoesNotMatchRegularExpression($pattern, 'php-fpm: pool www');
        // The anchor is what keeps pkill off the `bash -c` running the script.
        $this->assertDoesNotMatchRegularExpression($pattern, 'bash -c ' . $this->runnerScript('8.4'));
    }

    public function test_script_stops_every_master_before_the_runner_starts_one(): void
    {
        $script = $this->runnerScript('8.4');

        $stop = strpos($script, 'entrypoint-runner.sh stop php-fpm8.4');
        $kill = strpos($script, 'pkill -QUIT');
        $start = strpos($script, 'entrypoint-runner.sh start php-fpm8.4');
        $this->assertNotFalse($stop);
        $this->assertNotFalse($kill);
        $this->assertNotFalse($start);
        $this->assertLessThan($kill, $stop);
        $this->assertLessThan($start, $kill);
    }

    public function test_a_version_the_runner_does_not_manage_is_left_alone(): void
    {
        $script = $this->runnerScript('8.3');

        // First line, so nothing is stopped for a version no domain uses yet,
        // and a status of its own rather than a success.
        $this->assertStringStartsWith(
            '[ -f /entrypoint.d/php-fpm8.3.sh ] || exit ' . FpmStack::EXIT_NOT_MANAGED,
            $script
        );
    }

    public function test_title_matched_stop_runs_only_for_a_master_the_runner_left_behind(): void
    {
        $script = $this->runnerScript('8.4');

        $this->assertStringContainsString('if [ -n "$stray" ]; then', $script);
        $this->assertLessThan(
            strpos($script, 'pkill -QUIT'),
            strpos($script, 'if [ -n "$stray" ]; then')
        );
        $this->assertStringContainsString('the runner did not start: $stray', $script);
    }

    public function test_rejects_a_version_that_is_not_major_dot_minor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->runnerScript("8.4'; rm -rf /; '");
    }

    private function masterPattern(string $script): string
    {
        $this->assertSame(1, preg_match("/pkill -QUIT -f '([^']+)'/", $script, $m));

        return '#' . $m[1] . '#';
    }
}
