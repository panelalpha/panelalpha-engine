<?php

namespace Tests\Integration\Cron;

use App\Http\Requests\CronJobStoreRequest;
use App\Http\Requests\CronJobUpdateRequest;
use App\Lib\Helpers\CronSchedule;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Cron;
use App\System\Project\CronRunner;
use App\System\Project\Dind\AccountCron;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A cron job read back has to be the job that was created. The library parser
 * cut redirections and `#` out of the command, so the list showed a shorter
 * command under a different hash, and every write appended another `2>&1`.
 */
class CronRoundTripTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-cron-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/engine/users/alice/crontabs', 0777, true);
        file_put_contents($this->crontab(), '');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function commands(): array
    {
        return [
            'append with stderr' => ['echo mcp >> /tmp/x 2>&1'],
            'truncate' => ['echo mcp > /tmp/x'],
            'separate error log' => ['php artisan schedule:run >> /tmp/out.log 2>> /tmp/err.log'],
            'hash in a url' => ['curl -fsS https://example.test/#frag > /dev/null'],
            'repeated spaces' => ['echo  "a  b"'],
            'percent, which cron reads as a newline' => ['echo "$(date +%F) 100%" >> /tmp/day'],
        ];
    }

    #[DataProvider('commands')]
    public function test_a_created_job_lists_with_the_same_command_and_hash(string $command): void
    {
        $cron = $this->cron();
        $created = $cron->create($this->schedule($command));

        $this->assertSame([$created], $cron->list());
        $this->assertSame($command, $cron->list()[0]['command']);
        $this->assertTrue($cron->exists($created['hash']));
    }

    public function test_another_write_leaves_existing_lines_as_they_were(): void
    {
        $cron = $this->cron();
        $cron->create($this->schedule('echo a >> /tmp/a 2>&1'));
        $before = file_get_contents($this->crontab());

        $cron->create($this->schedule('echo b'));
        $cron->create($this->schedule('echo c'));

        $this->assertStringStartsWith(rtrim($before), file_get_contents($this->crontab()));
        $this->assertSame(3, substr_count(file_get_contents($this->crontab()), '*/5 * * * *'));
    }

    public function test_update_and_delete_find_the_job_by_the_hash_create_returned(): void
    {
        $cron = $this->cron();
        $created = $cron->create($this->schedule('echo a >> /tmp/a 2>&1'));

        $updated = $cron->update($created['hash'], $this->schedule('echo b >> /tmp/b 2>&1'));
        $this->assertSame('echo b >> /tmp/b 2>&1', $cron->list()[0]['command']);

        $cron->delete($updated['hash']);
        $this->assertSame([], $cron->list());
    }

    public function test_a_job_in_a_service_lists_with_its_service_command_and_hash(): void
    {
        $cron = $this->cron();
        $command = 'php artisan schedule:run >> /app/storage/logs/cron.log 2>&1 # 100%';

        $created = $cron->create(['service' => 'app'] + $this->schedule($command));

        $this->assertSame(['app', $command], [$created['service'], $created['command']]);
        $this->assertSame([$created], $cron->list());
        $this->assertSame(
            'MAILTO=""'."\n".'*/5 * * * * ' . AccountCron::command('app', $command) . "\n",
            file_get_contents($this->crontab())
        );

        $updated = $cron->update($created['hash'], ['service' => 'worker'] + $this->schedule($command));
        $this->assertSame([$updated], $cron->list());
        $this->assertSame('worker', $updated['service']);
        $this->assertNotSame($created['hash'], $updated['hash']);
    }

    public function test_a_job_without_a_service_lists_a_null_service(): void
    {
        $created = $this->cron()->create($this->schedule('echo a'));

        $this->assertNull($created['service']);
    }

    /**
     * An MCP tool dispatches to the router without TrimStrings, so padded
     * fields reach the request as sent. Trimmed there, the job is written as
     * one clean line and lists back under the hash create returned.
     */
    public function test_a_padded_job_is_trimmed_and_lists_back_with_the_same_hash(): void
    {
        $params = $this->validated(CronJobStoreRequest::class, [
            'command' => " echo a >> /tmp/a 2>&1\n",
            'minute' => ' */5 ',
            'hour' => "9,17\n",
            'day_of_month' => "\t*",
            'month' => '*',
            'day_of_week' => ' mon-fri',
        ]);
        $this->assertSame([], CronSchedule::errors($params));

        $cron = $this->cron();
        $created = $cron->create($params);

        $this->assertSame(
            'MAILTO=""'."\n".'*/5 9,17 * * mon-fri '.CronRunner::command('echo a >> /tmp/a 2>&1')."\n",
            file_get_contents($this->crontab())
        );
        $this->assertSame([$created], $cron->list());
        $this->assertTrue($cron->exists($created['hash']));

        $updated = $cron->update($created['hash'], $this->validated(CronJobUpdateRequest::class, [
            'command' => 'echo b ',
            'minute' => '0 ',
            'hour' => ' 1,2',
            'day_of_month' => '*',
            'month' => '*',
            'day_of_week' => '*',
        ]));
        $this->assertSame([$updated], $cron->list());
    }

    /** cron ends a command at its first unescaped `%`, so the job ran cut off while the API listed it whole. */
    public function test_a_percent_is_written_out_of_crons_reach(): void
    {
        $created = $this->cron()->create($this->schedule('date +%F > /tmp/day'));

        $this->assertStringNotContainsString('%', file_get_contents($this->crontab()));
        $this->assertSame('date +%F > /tmp/day', $created['command']);
    }

    public function test_an_escaped_percent_written_by_hand_lists_as_a_percent(): void
    {
        file_put_contents($this->crontab(), "0 1 * * * date +\\%F > /tmp/day\n");

        $this->assertSame('date +%F > /tmp/day', $this->cron()->list()[0]['command']);
    }

    public function test_a_backslash_before_a_percent_is_refused_under_the_command(): void
    {
        try {
            $this->validated(CronJobStoreRequest::class, $this->schedule('printf "\\%s"'));
            $this->fail('expected the request to refuse the command');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('command', $e->errors());
        }
    }

    public function test_whitespace_inside_a_field_is_refused_rather_than_written(): void
    {
        // Trimming takes the ends; the spaces inside are refused under the field.
        try {
            $this->validated(CronJobStoreRequest::class, ['hour' => ' 9 , 17 '] + $this->schedule('echo a'));
            $this->fail('expected the request to refuse the schedule');
        } catch (ValidationException $e) {
            $this->assertSame(['hour' => ['Whitespace is not allowed inside a field']], $e->errors());
        }
    }

    /**
     * cron answers a failing job's output to the bare account name, which the mail server refuses: the crontab turns
     * that mail off and calls the runner, which writes the run to the account's cron log.
     */
    public function test_the_crontab_turns_mail_off_and_writes_every_job_through_the_runner(): void
    {
        $this->cron()->create($this->schedule('date +%F > /tmp/day'));

        $this->assertSame(
            'MAILTO=""'."\n".'*/5 * * * * '.CronRunner::command('date +%F > /tmp/day')."\n",
            file_get_contents($this->crontab())
        );
        $script = file_get_contents($this->root . '/engine/users/alice/entrypoint-init.d/' . CronRunner::RUNNER_FILE);
        $this->assertStringContainsString('log=' . $this->root . '/home/alice/.panelalpha/cron/cron.log', $script);
        $this->assertStringNotContainsString('__LOG__', $script);
    }

    /** A job written before the runner existed is rewritten through it, and still lists as the command it was. */
    public function test_a_job_written_before_the_runner_is_rewritten_through_it(): void
    {
        file_put_contents($this->crontab(), "*/5 * * * * date +\\%F > /tmp/day\n");

        $cron = $this->cron();
        $this->assertSame('date +%F > /tmp/day', $cron->list()[0]['command']);

        $cron->create($this->schedule('echo new'));

        $contents = file_get_contents($this->crontab());
        $this->assertStringContainsString('*/5 * * * * '.CronRunner::command('date +%F > /tmp/day'), $contents);
        $this->assertStringContainsString('*/5 * * * * '.CronRunner::command('echo new'), $contents);
        $this->assertSame(['date +%F > /tmp/day', 'echo new'], array_column($cron->list(), 'command'));
    }

    /** Debian cron reads 999 characters of a command, so a long job in base64 behind the runner would be cut and dropped. */
    public function test_a_job_too_long_for_the_runner_keeps_the_line_it_ran_on_and_says_so(): void
    {
        $long = 'php /home/alice/run.php --date=$(date +\\%F)'.str_repeat(' --flag', 100);
        file_put_contents($this->crontab(), "0 1 * * * {$long}\n");
        Log::spy();

        $cron = $this->cron();
        $cron->create($this->schedule('echo new'));

        $contents = file_get_contents($this->crontab());
        $this->assertStringContainsString("\n0 1 * * * {$long}\n", $contents);
        $this->assertStringNotContainsString(base64_encode(str_replace('\\%', '%', $long)), $contents);
        $this->assertStringContainsString('*/5 * * * * '.CronRunner::command('echo new'), $contents);
        $this->assertSame([str_replace('\\%', '%', $long), 'echo new'], array_column($cron->list(), 'command'));
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message): bool => str_starts_with($message, 'alice: a cron job longer than 640 bytes keeps its own line')
        );
    }

    public function test_hand_written_lines_still_parse(): void
    {
        file_put_contents($this->crontab(), "# a comment\n\n*/5\t* * * *   echo tab\n@daily echo daily\n");

        $jobs = $this->cron()->list();

        $this->assertSame(['*/5', 'echo tab'], [$jobs[0]['minute'], $jobs[0]['command']]);
        $this->assertSame(['0', '0', 'echo daily'], [$jobs[1]['minute'], $jobs[1]['hour'], $jobs[1]['command']]);
    }

    /** @return array{command: string, minute: string, hour: string, day_of_month: string, month: string, day_of_week: string} */
    private function schedule(string $command): array
    {
        return [
            'command' => $command,
            'minute' => '*/5',
            'hour' => '*',
            'day_of_month' => '*',
            'month' => '*',
            'day_of_week' => '*',
        ];
    }

    /**
     * The request as the router resolves it, without the HTTP middleware.
     *
     * @param class-string<FormRequest> $class
     * @param array<string, string> $payload
     * @return array{command: string, minute: string, hour: string, day_of_month: string, month: string, day_of_week: string}
     */
    private function validated(string $class, array $payload): array
    {
        $request = $class::create('/', 'POST', $payload);
        $request->setContainer($this->app)->setRedirector($this->app->make('redirect'));
        $request->validateResolved();

        /** @var array{command: string, minute: string, hour: string, day_of_month: string, month: string, day_of_week: string} */
        return $request->validated();
    }

    private function crontab(): string
    {
        return $this->root . '/engine/users/alice/crontabs/www-data';
    }

    private function cron(): Cron
    {
        $user = new User();
        $user->username = 'alice';
        $user->setDetails(['template' => 'default']);

        $system = new class ($this->root) extends System {
            /** @var list<array<int, string>> */
            public array $calls = [];

            public function __construct(private string $root)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root . '/engine';
            }

            public function homesDirPath(): string
            {
                return $this->root . '/home';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->calls[] = (array) $cmd;

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->calls[] = (array) $cmd;

                return new Process(['true']);
            }

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function fileGetContents(string $path): string
                    {
                        return (string) file_get_contents($path);
                    }

                    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        file_put_contents($path, $contents);
                    }

                    public function writeFileReplacingPath(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        @mkdir(dirname($path), 0777, true);
                        file_put_contents($path, $contents);
                    }
                };
            }
        };

        return $system->project($user)->cron();
    }
}
