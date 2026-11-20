<?php

namespace Tests\Integration\System\Project\PhpHosting;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Privileged;
use App\System\Project as ProjectAggregate;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\FpmStack;
use App\System\Project\PhpHosting\PhpErrorLogConfig;
use App\System\Project\PhpHosting\PhpHandlerNotRunning;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The reload script runs for real against a fake pgrep, pkill, ps, stat and date that play the account's
 * php-fpm: one master, two workers, and a pool file the grep shim reads with the real grep.
 */
class FpmStackReloadTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-fpm-reload-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/bin', 0777, true);
        mkdir($this->dir.'/state', 0777, true);
        $this->shim('pgrep', <<<'BASH'
            state="$(dirname "$0")/../state"
            delim=$'\n'
            mode=''
            while [ $# -gt 0 ]; do
              case "$1" in
                -d) delim="$2"; shift 2 ;;
                -f) mode=pattern; shift 2 ;;
                -P) mode=children; shift 2 ;;
                *) shift ;;
              esac
            done
            case "$mode" in
              pattern)
                [ -f "$state/master" ] || exit 1
                cat "$state/master"
                ;;
              children)
                [ -f "$state/no_workers" ] && exit 1
                ids="11 12"
                if [ "$delim" = $'\n' ]; then printf '%s\n' $ids; else printf '%s' "${ids// /$delim}"; fi
                ;;
              *) exit 1 ;;
            esac
            BASH);
        $this->shim('pkill', <<<'BASH'
            state="$(dirname "$0")/../state"
            echo "$*" >> "$state/kills"
            [ -f "$state/pkill_status" ] && exit "$(cat "$state/pkill_status")"
            exit 0
            BASH);
        $this->shim('ps', <<<'BASH'
            state="$(dirname "$0")/../state"
            [ -f "$state/age" ] || exit 1
            cat "$state/age"
            BASH);
        $this->shim('stat', <<<'BASH'
            state="$(dirname "$0")/../state"
            [ -f "$state/written" ] || exit 1
            cat "$state/written"
            BASH);
        $this->shim('date', <<<'BASH'
            state="$(dirname "$0")/../state"
            [ -f "$state/now" ] || exit 1
            cat "$state/now"
            BASH);
        $this->shim('grep', sprintf(
            "exec %s \"\$1\" \"\$2\" \"\$(dirname \"\$0\")/../state/pool.conf\"\n",
            escapeshellarg((string) (new ExecutableFinder())->find('grep', '/bin/grep'))
        ));
        file_put_contents($this->dir.'/state/master', '4242');
        $this->pool('');
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();
        parent::tearDown();
    }

    /** A handler started after the pool gained the short cache reads a saved user INI within seconds itself. */
    public function test_a_master_that_started_after_the_pool_gained_the_short_cache_is_not_signalled(): void
    {
        $this->pool(PhpErrorLogConfig::POOL_USER_INI_CACHE_TTL_LINE."\n");
        $this->clocks(2000, 10, 1000);

        $this->assertSame(0, $this->reload());
        $this->assertFileDoesNotExist($this->dir.'/state/kills', 'nothing was signalled');
    }

    /** Until its handler starts again, an account keeps PHP's five minute cache, so its workers are replaced. */
    public function test_a_master_that_started_before_the_pool_gained_the_short_cache_gets_new_workers(): void
    {
        $this->pool(PhpErrorLogConfig::POOL_USER_INI_CACHE_TTL_LINE."\n");
        $this->clocks(2000, 10, 1995);

        $this->assertSame(0, $this->reload());
        $this->assertSame('-QUIT -P 4242', trim($this->state('kills')));
    }

    /** The write and the start share one second, so which one PHP read is not certain and the save takes the slow way. */
    public function test_a_master_that_started_in_the_second_the_pool_was_written_gets_new_workers(): void
    {
        $this->pool(PhpErrorLogConfig::POOL_USER_INI_CACHE_TTL_LINE."\n");
        $this->clocks(2000, 10, 1990);

        $this->assertSame(0, $this->reload());
        $this->assertSame('-QUIT -P 4242', trim($this->state('kills')));
    }

    /** An account the refresh has not reached yet runs on five minutes whatever its master's age. */
    public function test_a_pool_without_the_short_cache_gets_new_workers(): void
    {
        $this->pool("catch_workers_output = yes\n");
        $this->clocks(2000, 10, 1000);

        $this->assertSame(0, $this->reload());
        $this->assertSame('-QUIT -P 4242', trim($this->state('kills')));
    }

    /** A pool that carries another value, or the line in another shape, is not trusted. */
    public function test_a_pool_that_carries_another_cache_value_gets_new_workers(): void
    {
        foreach (['php_admin_value[user_ini.cache_ttl] = 300', 'php_admin_value[user_ini.cache_ttl]=3', ';php_admin_value[user_ini.cache_ttl] = 3'] as $line) {
            $this->reset();
            $this->pool($line."\n");
            $this->clocks(2000, 10, 1000);

            $this->assertSame(0, $this->reload(), $line);
            $this->assertSame('-QUIT -P 4242', trim($this->state('kills')), $line);
        }
    }

    public function test_a_master_whose_age_cannot_be_read_gets_new_workers(): void
    {
        $this->pool(PhpErrorLogConfig::POOL_USER_INI_CACHE_TTL_LINE."\n");
        $this->clocks(2000, null, 1000);

        $this->assertSame(0, $this->reload());
        $this->assertSame('-QUIT -P 4242', trim($this->state('kills')));
    }

    public function test_a_pool_whose_age_cannot_be_read_gets_new_workers(): void
    {
        $this->pool(PhpErrorLogConfig::POOL_USER_INI_CACHE_TTL_LINE."\n");
        $this->clocks(2000, 10, null);

        $this->assertSame(0, $this->reload());
        $this->assertSame('-QUIT -P 4242', trim($this->state('kills')));
    }

    public function test_a_version_with_no_master_is_reported_as_not_managed(): void
    {
        unlink($this->dir.'/state/master');

        $this->assertSame(FpmStack::EXIT_NOT_MANAGED, $this->reload());
        $this->assertFileDoesNotExist($this->dir.'/state/kills', 'nothing was signalled');
    }

    public function test_a_pool_that_started_no_worker_yet_has_nothing_to_replace(): void
    {
        $this->clocks(2000, 10, 1995);
        touch($this->dir.'/state/no_workers');

        $this->assertSame(0, $this->reload());
        $this->assertFileDoesNotExist($this->dir.'/state/kills', 'nothing was signalled');
    }

    /** Every worker busy past the save: each finishes its request first and the master cannot spawn past its limit. */
    public function test_a_saturated_pool_whose_workers_are_still_busy_is_a_successful_swap(): void
    {
        $this->clocks(2000, 10, 1995);

        [, $exitCode, $stderr] = $this->execute(FpmStack::reloadScript('8.4'));

        $this->assertSame(0, $exitCode);
        $this->assertSame('', trim($stderr));
        $this->assertSame('-QUIT -P 4242', trim($this->state('kills')));
    }

    public function test_workers_that_left_before_the_signal_have_nothing_left_to_replace(): void
    {
        $this->clocks(2000, 10, 1995);
        file_put_contents($this->dir.'/state/pkill_status', '1');

        $this->assertSame(0, $this->reload());
    }

    public function test_a_signal_that_could_not_be_sent_is_reported_and_not_swallowed(): void
    {
        $this->clocks(2000, 10, 1995);
        file_put_contents($this->dir.'/state/pkill_status', '3');

        [, $exitCode, $stderr] = $this->execute(FpmStack::reloadScript('8.4'));

        $this->assertSame(1, $exitCode);
        $this->assertSame('php-fpm8.4 could not signal its workers', trim($stderr));
    }

    public function test_the_script_quits_only_this_versions_workers(): void
    {
        $script = FpmStack::reloadScript('8.4');

        $this->assertSame(1, preg_match("/pgrep -f '([^']+)'/", $script, $m));
        $pattern = '#'.$m[1].'#';
        $this->assertMatchesRegularExpression($pattern, 'php-fpm: master process (/etc/php/8.4/fpm/php-fpm.conf)');
        $this->assertDoesNotMatchRegularExpression($pattern, 'php-fpm: master process (/etc/php/8.3/fpm/php-fpm.conf)');
        $this->assertDoesNotMatchRegularExpression($pattern, 'php-fpm: pool www');
        $this->assertDoesNotMatchRegularExpression($pattern, 'bash -c '.$script);
        $this->assertStringContainsString('pkill -QUIT -P "$master"', $script);
    }

    /** The value it trusts is the one the pool gets, in the file the pool is mounted as. */
    public function test_it_checks_the_pool_line_the_engine_writes_for_that_version(): void
    {
        $script = FpmStack::reloadScript('8.4');

        $this->assertStringContainsString("grep -Fxq '".PhpErrorLogConfig::POOL_USER_INI_CACHE_TTL_LINE."'", $script);
        $this->assertStringContainsString("'/etc/php/8.4/fpm/pool.d/www.conf'", $script);
        $this->assertStringContainsString("'/etc/php/8.2/fpm/pool.d/www.conf'", FpmStack::reloadScript('8.2'));
    }

    public function test_rejects_a_version_that_is_not_major_dot_minor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FpmStack::reloadScript("8.4'; rm -rf /; '");
    }

    public function test_a_version_the_account_does_not_run_is_not_reported_as_a_failed_reload(): void
    {
        [$system, $model, $project] = $this->accountRunning('exit '.FpmStack::EXIT_NOT_MANAGED);

        $this->expectException(PhpHandlerNotRunning::class);
        (new FpmStack($system, $model))->reloadPhpHandler($project, '8.3');
    }

    public function test_a_failed_reload_is_reported_not_swallowed(): void
    {
        [$system, $model, $project] = $this->accountRunning('echo "php-fpm8.4 could not signal its workers" >&2; exit 1');

        try {
            (new FpmStack($system, $model))->reloadPhpHandler($project, '8.4');
            $this->fail('A reload that could not signal the workers must throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Could not reload php-fpm8.4: php-fpm8.4 could not signal its workers', $e->getMessage());
        }

        $this->assertSame(
            Privileged::accountContainer('alice')->exec(['bash', '-c', FpmStack::reloadScript('8.4')]),
            $system->commands[0]
        );
    }

    private function reload(): int
    {
        return $this->execute(FpmStack::reloadScript('8.4'))[1];
    }

    private function reset(): void
    {
        foreach (['kills', 'pkill_status', 'no_workers'] as $file) {
            @unlink($this->dir.'/state/'.$file);
        }
    }

    private function clocks(int $now, ?int $age, ?int $written): void
    {
        file_put_contents($this->dir.'/state/now', (string) $now);
        foreach (['age' => $age, 'written' => $written] as $file => $value) {
            $value === null ? @unlink($this->dir.'/state/'.$file) : file_put_contents($this->dir.'/state/'.$file, (string) $value);
        }
    }

    private function pool(string $contents): void
    {
        file_put_contents($this->dir.'/state/pool.conf', "[www]\npm = dynamic\n".$contents);
    }

    /**
     * @return array{0: string, 1: int, 2: string}
     */
    private function execute(string $script): array
    {
        $process = new Process(['bash', '-c', $script], $this->dir, ['PATH' => $this->dir.'/bin:'.getenv('PATH')]);
        $process->run();

        return [$process->getOutput(), (int) $process->getExitCode(), $process->getErrorOutput()];
    }

    private function state(string $file): string
    {
        $path = $this->dir.'/state/'.$file;

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @return array{0: System&object{commands: list<array<int, string>>}, 1: ModelsUser, 2: PhpHosting}
     */
    private function accountRunning(string $shell): array
    {
        $system = new class extends System
        {
            /** @var list<array<int, string>> */
            public array $commands = [];

            public string $shell = '';

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = is_array($cmd) ? $cmd : [$cmd];
                $process = new Process(['sh', '-c', $this->shell]);
                $process->run();

                return $process;
            }
        };
        $system->shell = $shell;
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(['template' => 'default']);
        $project = new PhpHosting(new ProjectAggregate($system, $model));

        return [$system, $model, $project];
    }

    private function shim(string $name, string $body): void
    {
        $path = $this->dir.'/bin/'.$name;
        file_put_contents($path, "#!/bin/bash\n".$body."\n");
        chmod($path, 0755);
    }
}
