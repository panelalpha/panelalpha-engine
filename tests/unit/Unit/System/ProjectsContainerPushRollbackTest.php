<?php

namespace Tests\Unit\System;

use App\Lib\Deploy\Platform\Strategies;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Dind\AppDatabase;
use App\System\Projects;
use App\System\Services\Mysql;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;
use Tests\Unit\System\Project\Dind\RecordingAppMysql;

/**
 * A container push whose pushed app does not come up is rolled back while
 * that app may already run: its containers are stopped before the target's
 * volumes and home are put back, and started again in new containers, so
 * none keeps running on the pushed data the rollback removed.
 */
class ProjectsContainerPushRollbackTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    private object $record;

    private ?RecordingAppMysql $mysql = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        Facade::clearResolvedInstances();
        $this->root = sys_get_temp_dir().'/pa-push-dind-rollback-'.bin2hex(random_bytes(6));
        $this->record = (object) ['events' => [], 'failStarts' => 1, 'notRunning' => null, 'checks' => 0, 'failedRuns' => []];
        $this->containerHome('live', 'live data', 'live code');
        $this->containerHome('stg', 'staging data', 'staging code');
        $live = $this->containerProject('live', 1001);
        $stg = $this->containerProject('stg', 2002);
        $stg->staging = $live->id;
        $stg->save();
        putenv('PA_ROOT_LOCAL_ROOTS='.json_encode(['homes' => $this->root]));
    }

    protected function tearDown(): void
    {
        putenv('PA_ROOT_LOCAL_ROOTS');
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_the_target_is_stopped_before_its_volumes_and_home_are_put_back(): void
    {
        $this->pushFails();

        $events = $this->record->events;
        $failedStart = array_search('start live recreate=no', $events, true);
        $stop = $this->firstAfter($events, 'stop live', (int) $failedStart);
        $volumeBack = $this->firstAfter($events, 'replace live project_db with _data.pre-push', (int) $failedStart);
        $homeBack = $this->firstAfter($events, 'mv '.$this->root.'/live/.pre-push/project '.$this->root.'/live/', (int) $failedStart);

        $this->assertNotFalse($failedStart);
        $this->assertLessThan($volumeBack, $stop);
        $this->assertLessThan($homeBack, $stop);
    }

    public function test_the_target_starts_again_in_new_containers_on_its_own_data(): void
    {
        $this->pushFails();

        $starts = array_values(preg_grep('/^start live /', $this->record->events) ?: []);
        $this->assertSame(['start live recreate=no', 'start live recreate=yes'], $starts);
        $this->assertSame('live data', file_get_contents($this->volume('live').'/_data/marker'));
        $this->assertSame('live code', file_get_contents($this->root.'/live/project/index.js'));
        $this->assertDirectoryDoesNotExist($this->root.'/live/.panelalpha-volumes');
        $this->assertDirectoryDoesNotExist($this->root.'/live/.pre-push');
    }

    /** Up, then crash-looping: as failed a start as one `up` refused, and rolled back the same way. */
    public function test_a_pushed_app_that_does_not_run_is_rolled_back(): void
    {
        $this->record->failStarts = 0;
        $this->record->notRunning = 'The application is restarting, not running.';

        try {
            (new Projects($this->system()))->pushToApp('stg', 'live');
            $this->fail('the push should have failed');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('The pushed application did not start: The application is restarting, not running.', $e->getMessage());
        }

        $starts = array_values(preg_grep('/^start live /', $this->record->events) ?: []);
        $this->assertSame(['start live recreate=no', 'start live recreate=yes'], $starts);
        $this->assertSame(1, $this->record->checks, 'the start the rollback makes is not refused in turn');
        $this->assertSame('live data', file_get_contents($this->volume('live').'/_data/marker'));
        $this->assertSame('live code', file_get_contents($this->root.'/live/project/index.js'));
    }

    /** A database-only push swaps no files, but the app it started may have crashed on the pushed database. */
    public function test_a_database_only_push_that_does_not_run_starts_the_target_again(): void
    {
        $mysql = $this->giveAppDatabases();
        $this->record->failStarts = 0;
        $this->record->notRunning = 'The application is stopped, not running.';

        try {
            (new Projects($this->system()))->pushToApp('stg', 'live', files: false);
            $this->fail('the push should have failed');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('The pushed application did not start: The application is stopped, not running.', $e->getMessage());
        }

        $starts = array_values(preg_grep('/^start live /', $this->record->events) ?: []);
        $this->assertSame(['start live recreate=no', 'start live recreate=yes'], $starts);
        $this->assertSame('live code', file_get_contents($this->root.'/live/project/index.js'));
        $this->assertContains('restore live_app from live_app.sql.gz', $mysql->log);
    }

    public function test_a_pushed_app_that_runs_completes_the_push(): void
    {
        $this->record->failStarts = 0;

        (new Projects($this->system()))->pushToApp('stg', 'live');

        $this->assertSame(['start live recreate=no'], array_values(preg_grep('/^start live /', $this->record->events) ?: []));
        $this->assertSame(1, $this->record->checks);
        $this->assertSame('staging code', file_get_contents($this->root.'/live/project/index.js'));
        $this->assertSame('staging data', file_get_contents($this->volume('live').'/_data/marker'));
        $this->assertDirectoryDoesNotExist($this->root.'/live/.panelalpha-volumes');
    }

    public function test_a_swap_that_fails_at_a_volume_rolls_the_whole_target_back(): void
    {
        $this->record->failStarts = 0;
        $this->record->failedRuns = ['replace live project_db with _data.incoming'];

        $message = $this->failedPush();

        $this->assertStringStartsWith("Failed to swap incoming volume data for 'project_db': tar: short read", $message);
        $this->assertStringNotContainsString('not fully restored', $message);
        $this->assertTargetIsItsOwn();
        $this->assertContains('start live recreate=yes', $this->record->events);
    }

    public function test_a_volume_that_cannot_be_put_back_still_gets_the_home_back_and_is_named(): void
    {
        $this->record->failedRuns = ['replace live project_db with _data.pre-push'];

        $message = $this->failedPush();

        $staging = $this->root.'/live/.panelalpha-volumes';
        $this->assertStringContainsString("not fully restored (failed: restoring the volumes (their data before the push is kept in {$staging})", $message);
        $this->assertStringNotContainsString('restoring the files', $message);
        $this->assertSame('live code', file_get_contents($this->root.'/live/project/index.js'));
        $this->assertDirectoryDoesNotExist($this->root.'/live/.pre-push');
        $this->assertFileExists($staging.'/project_db/_data.pre-push');
    }

    public function test_a_push_that_fails_while_staging_a_volume_leaves_nothing_staged(): void
    {
        $this->record->failedRuns = ['archive stg project_db as _data.incoming'];

        $message = $this->failedPush();

        $this->assertStringStartsWith("Failed to rsync volume data for 'project_db': tar: short read", $message);
        $this->assertStringNotContainsString('not fully restored', $message);
        $this->assertTargetIsItsOwn();
    }

    public function test_volume_data_a_failed_push_kept_refuses_the_next_push_and_stays(): void
    {
        $kept = $this->root.'/live/.panelalpha-volumes/project_db';
        mkdir($kept, 0777, true);
        file_put_contents($kept.'/_data.pre-push', 'the target before the last push');

        $message = $this->failedPush();

        $this->assertStringStartsWith('Incomplete previous push (.panelalpha-volumes present); refuse to continue.', $message);
        $this->assertSame('the target before the last push', file_get_contents($kept.'/_data.pre-push'));
        $this->assertSame('live data', file_get_contents($this->volume('live').'/_data/marker'));
        $this->assertNotContains('replace live project_db with _data.pre-push', $this->record->events);
    }

    private function failedPush(): string
    {
        try {
            (new Projects($this->system()))->pushToApp('stg', 'live');
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        $this->fail('the push should have failed');
    }

    private function assertTargetIsItsOwn(): void
    {
        $this->assertSame('live data', file_get_contents($this->volume('live').'/_data/marker'));
        $this->assertSame('live code', file_get_contents($this->root.'/live/project/index.js'));
        $this->assertDirectoryDoesNotExist($this->root.'/live/.panelalpha-volumes');
        $this->assertDirectoryDoesNotExist($this->root.'/live/.pre-push');
        $this->assertDirectoryDoesNotExist($this->root.'/live/.incoming');
    }

    private function pushFails(): void
    {
        try {
            (new Projects($this->system()))->pushToApp('stg', 'live');
            $this->fail('the push should have failed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('health check failed', $e->getMessage());
        }
    }

    /**
     * @param  list<string>  $events
     */
    private function firstAfter(array $events, string $event, int $after): int
    {
        foreach ($events as $index => $candidate) {
            if ($index > $after && $candidate === $event) {
                return $index;
            }
        }
        $this->fail("'{$event}' was never recorded after event {$after}: ".implode(' | ', $events));
    }

    /** Both sides get the application database a database-only container push carries. */
    private function giveAppDatabases(): RecordingAppMysql
    {
        $this->mysql = new RecordingAppMysql;
        foreach (['live', 'stg'] as $username) {
            $user = User::findByUsernameOrFail($username);
            $user->setDetails([AppDatabase::PASSWORD_DETAIL => $username.'-secret']);
            $user->save();
            $user->mysqlDatabases()->create(['database' => $username.'_app']);
            $user->mysqlUsers()->create(['user' => $username.'_app']);
            $this->mysql->databases[] = $username.'_app';
            $this->mysql->users[$username.'_app'] = $username.'-secret';
        }

        return $this->mysql;
    }

    private function containerProject(string $username, int $uid): User
    {
        return $this->makeUser($username, [
            'template' => 'dind',
            'deploy_strategy' => Strategies::DOCKERFILE,
            'UID' => $uid,
            'GID' => $uid,
            'mysql_prefix' => $username.'_',
        ]);
    }

    private function containerHome(string $username, string $data, string $code): void
    {
        mkdir($this->volume($username).'/_data', 0777, true);
        file_put_contents($this->volume($username).'/_data/marker', $data);
        mkdir($this->root."/{$username}/project", 0777, true);
        file_put_contents($this->root."/{$username}/project/index.js", $code);
    }

    private function volume(string $username): string
    {
        return "{$this->root}/{$username}/docker/volumes/project_db";
    }

    private function system(): System
    {
        return new class($this->root, $this->record, $this->mysql) extends System
        {
            public function __construct(private string $root, private object $record, private ?Mysql $server) {}

            public function pushRollbacksDirPath(): string
            {
                return $this->root.'/rollbacks';
            }

            public function mysql(): Mysql
            {
                return $this->server ?? parent::mysql();
            }

            public function project(User $model): Project
            {
                return new class($this, $model, $this->root.'/'.$model->username, $this->record) extends Project
                {
                    public function __construct(System $system, User $model, private string $home, private object $record)
                    {
                        parent::__construct($system, $model);
                    }

                    public function homeDirPath(): string
                    {
                        return $this->home;
                    }

                    public function fixPermissions(): void {}

                    public function startUserApp(bool $forceRecreate = false): array
                    {
                        $this->record->events[] = 'start '.basename($this->home).' recreate='.($forceRecreate ? 'yes' : 'no');
                        if ($this->record->failStarts > 0) {
                            $this->record->failStarts--;

                            return ['stdout' => '', 'stderr' => 'health check failed', 'exit_code' => 1];
                        }

                        return ['stdout' => '', 'stderr' => '', 'exit_code' => 0];
                    }

                    public function startedAppFailure(): ?string
                    {
                        $this->record->checks++;

                        return $this->record->notRunning;
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $process = $this->runProcess($cmd);
                if (! $process->isSuccessful()) {
                    throw new \RuntimeException($process->getErrorOutput());
                }

                return $process->getOutput();
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return is_array($cmd) ? $this->apply(LocalRoot::checked($cmd)) : new ContainerPushRollbackProcess(0);
            }

            /**
             * @param  list<string>  $argv
             */
            private function apply(array $argv): Process
            {
                if (LocalRoot::composeCommand($argv) !== []) {
                    return $this->compose(LocalRoot::values($argv)[0], LocalRoot::composeCommand($argv));
                }
                if (($argv[0] ?? '') === 'pa-root') {
                    return $this->daemon($argv[1], array_slice($argv, 3));
                }
                $path = (string) end($argv);

                return match (array_slice($argv, 0, 2)) {
                    ['test', '-d'] => new ContainerPushRollbackProcess(is_dir($path) ? 0 : 1),
                    ['test', '-e'] => new ContainerPushRollbackProcess(file_exists($path) ? 0 : 1),
                    ['test', '-f'] => new ContainerPushRollbackProcess(is_file($path) ? 0 : 1),
                    ['test', '-L'] => new ContainerPushRollbackProcess(is_link($path) ? 0 : 1),
                    ['ls', '-A'], ['ls', '-1A'], ['ls', '-1'] => new ContainerPushRollbackProcess(0, implode("\n", array_diff(scandir($path) ?: [], ['.', '..']))),
                    ['df', '-P'] => new ContainerPushRollbackProcess(0, "Filesystem 1024-blocks Used Available Capacity Mounted\n/dev/sda1 1 1 999999999 1% /"),
                    default => new ContainerPushRollbackProcess($this->change($argv)),
                };
            }

            /**
             * The account's daemon: its volumes are the directories under its docker/volumes, and a run is the archive or
             * the replace its tar container would make there.
             *
             * @param  list<string>  $values
             */
            private function daemon(string $action, array $values): Process
            {
                $volumes = "{$this->root}/{$values[0]}/docker/volumes";
                if ($action === 'account-docker.listVolumes') {
                    $names = array_values(array_diff(scandir($volumes) ?: [], ['.', '..', 'metadata.db']));

                    return new ContainerPushRollbackProcess(0, (string) json_encode(array_map(static fn (string $name): array => ['Name' => $name], $names)));
                }
                if ($action !== 'account-docker.run') {
                    return new ContainerPushRollbackProcess(0);
                }
                [$account, , , $volume, , , $mounted] = $values;
                $data = "{$volumes}/{$volume}/_data";
                $archives = $values[9] === 'tar';
                $event = $archives ? "archive {$account} {$volume} as ".basename($values[13]) : "replace {$account} {$volume} with ".basename($mounted);
                $this->record->events[] = $event;
                if (in_array($event, $this->record->failedRuns, true)) {
                    return new ContainerPushRollbackProcess(1, '', 'tar: short read');
                }
                $script = $archives
                    ? 'tar -czf '.escapeshellarg($mounted.'/'.basename($values[13])).' -C '.escapeshellarg($data).' .'
                    : 'sh -c '.escapeshellarg(str_replace(['/data', '/archive'], [$data, $mounted], $values[11]));
                exec($script, $output, $code);

                return new ContainerPushRollbackProcess($code);
            }

            /**
             * @param  list<string>  $argv
             */
            private function compose(string $username, array $argv): Process
            {
                foreach (['stop', 'create', 'up'] as $action) {
                    if (in_array($action, $argv, true)) {
                        $this->record->events[] = "{$action} {$username}";
                    }
                }

                return new ContainerPushRollbackProcess(0);
            }

            /**
             * @param  list<string>  $argv
             */
            private function change(array $argv): int
            {
                $args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => ! str_starts_with($a, '-')));
                if (in_array($argv[0] ?? '', ['rm', 'mv'], true)) {
                    $this->record->events[] = $argv[0].' '.implode(' ', $args);
                }
                match ($argv[0] ?? '') {
                    'mkdir' => is_dir($args[0]) || mkdir($args[0], 0777, true),
                    'rm' => array_map(static fn (string $p) => exec('rm -rf '.escapeshellarg($p)), $args),
                    'rmdir' => rmdir($args[0]),
                    'rsync' => $this->rsync($argv, $args[0], $args[1]),
                    'mv' => rename($args[0], str_ends_with($args[1], '/') ? $args[1].basename($args[0]) : $args[1]),
                    default => null,
                };

                return 0;
            }

            /**
             * @param  list<string>  $argv
             */
            private function rsync(array $argv, string $from, string $to): void
            {
                $excluded = array_map(
                    static fn (string $arg): string => rtrim(substr($arg, strlen('--exclude=')), '/'),
                    array_filter($argv, static fn (string $arg): bool => str_starts_with($arg, '--exclude=')),
                );
                foreach (array_diff(scandir($from) ?: [], ['.', '..', ...$excluded]) as $entry) {
                    exec('cp -a '.escapeshellarg(rtrim($from, '/').'/'.$entry).' '.escapeshellarg($to));
                }
            }
        };
    }
}

class ContainerPushRollbackProcess extends Process
{
    public function __construct(private int $code, private string $output = '', private string $error = '')
    {
        parent::__construct(['true']);
    }

    public function isSuccessful(): bool
    {
        return $this->code === 0;
    }

    public function getExitCode(): ?int
    {
        return $this->code;
    }

    public function getErrorOutput(): string
    {
        return $this->error;
    }

    public function getOutput(): string
    {
        return $this->output;
    }
}
