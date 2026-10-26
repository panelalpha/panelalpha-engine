<?php

namespace Tests\Unit\System;

use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Projects;
use App\System\Project\Dind\CopyVolumes;
use App\System\Services\Mysql;
use App\System\Services\Mysql\Databases;
use App\System\Services\Mysql\Privileges;
use PDO;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A push that fails once the target's home has been swapped puts the previous
 * home back. The file commands run for real in a scratch directory; only the
 * database, du, df and the account start are faked.
 */
class ProjectsPushFileRollbackTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    private object $record;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir() . '/pa-push-files-' . bin2hex(random_bytes(6));
        $this->record = (object) [
            'failStartTimes' => 0,
            'failCommand' => null,
            'restores' => [],
        ];
        $this->writeHome('live', ['index.php' => 'live site', '.htaccess' => 'live rules']);
        $this->writeHome('stg', ['index.php' => 'staging site', 'new.php' => 'only on staging']);
        $this->stagingPair();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_failure_after_the_swap_puts_the_previous_home_back(): void
    {
        $this->record->failStartTimes = 1;

        $this->pushFails('start exploded');

        $this->assertLiveHomeIsUntouched();
        $this->assertSame([['live_wp', $this->live('.pre-push-db') . '/live_wp.sql.gz']], $this->record->restores);
        $this->assertSame('start exploded', User::findByUsernameOrFail('live')->getDetails()['error'] ?? null);
    }

    public function test_the_next_push_succeeds_after_a_rolled_back_one(): void
    {
        $this->record->failStartTimes = 1;
        $this->pushFails('start exploded');

        $this->push();

        $this->assertSame('staging site', file_get_contents($this->docroot('live') . '/index.php'));
        $this->assertFileExists($this->docroot('live') . '/new.php');
        $this->assertNoPushLeftovers();
    }

    public function test_a_failure_while_moving_the_home_aside_restores_it(): void
    {
        $this->record->failCommand = fn (array $argv): bool => $argv[0] === 'mv'
            && str_ends_with($argv[1], '/live.example.test')
            && str_ends_with($argv[2], '/.pre-push/');

        $this->pushFails("Failed to move 'live.example.test' aside before push swap");

        $this->assertLiveHomeIsUntouched();
    }

    public function test_a_failure_while_moving_the_incoming_in_restores_the_home(): void
    {
        $this->record->failCommand = fn (array $argv): bool => $argv[0] === 'mv'
            && str_contains($argv[1], '/.incoming/')
            && str_ends_with($argv[1], '/live.example.test');

        $this->pushFails("Failed to move incoming 'live.example.test' into target home");

        $this->assertLiveHomeIsUntouched();
    }

    public function test_a_failed_file_rollback_keeps_the_previous_home_and_says_where(): void
    {
        $this->record->failStartTimes = 1;
        $this->record->failCommand = fn (array $argv): bool => $argv[0] === 'mv'
            && str_contains($argv[1], '/.pre-push/');

        $e = $this->pushFails('start exploded');

        $this->assertStringContainsString(
            'restoring the files (the previous home is kept in ' . $this->live('.pre-push') . ')',
            $e->getMessage(),
        );
        $this->assertSame('live site', file_get_contents($this->live('.pre-push') . '/live.example.test/public_html/index.php'));

        $this->record->failCommand = null;
        $this->pushFails('Incomplete previous push (.pre-push present)');
    }

    public function test_a_failure_before_the_swap_leaves_the_home_alone(): void
    {
        $this->record->failCommand = fn (array $argv): bool => $argv[0] === 'rsync';

        $this->pushFails('Failed to rsync source home into .incoming');

        $this->assertLiveHomeIsUntouched();
    }

    private function assertLiveHomeIsUntouched(): void
    {
        $this->assertSame('live site', file_get_contents($this->docroot('live') . '/index.php'));
        $this->assertSame('live rules', file_get_contents($this->docroot('live') . '/.htaccess'));
        $this->assertFileDoesNotExist($this->docroot('live') . '/new.php');
        $this->assertNoPushLeftovers();
    }

    private function assertNoPushLeftovers(): void
    {
        $this->assertFileDoesNotExist($this->live('.pre-push'));
        $this->assertFileDoesNotExist($this->live('.incoming'));
        $this->assertFileDoesNotExist($this->live('.pre-push-db'));
    }

    private function push(): void
    {
        (new Projects($this->system()))->pushToApp('stg', 'live');
    }

    private function pushFails(string $message): \RuntimeException
    {
        try {
            $this->push();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());

            return $e;
        }

        $this->fail('the push should have failed');
    }

    private function stagingPair(): void
    {
        $live = $this->makeUser('live', ['mysql_prefix' => 'live_']);
        $live->mysqlDatabases()->create(['database' => 'live_wp']);
        $live->mysqlUsers()->create(['user' => 'live_wp']);
        $stg = $this->makeUser('stg', ['mysql_prefix' => 'stg_']);
        $stg->mysqlDatabases()->create(['database' => 'stg_wp']);
        $stg->mysqlUsers()->create(['user' => 'stg_wp']);
        $stg->staging = $live->id;
        $stg->save();
    }

    /**
     * @param array<string, string> $files
     */
    private function writeHome(string $username, array $files): void
    {
        $docroot = $this->docroot($username);
        mkdir($docroot, 0777, true);
        foreach ($files as $name => $content) {
            file_put_contents($docroot . '/' . $name, $content);
        }
    }

    private function docroot(string $username): string
    {
        return "{$this->root}/{$username}/{$username}.example.test/public_html";
    }

    private function live(string $entry): string
    {
        return "{$this->root}/live/{$entry}";
    }

    private function system(): System
    {
        return new class ($this->record, $this->root) extends System {
            public function __construct(private object $record, private string $root)
            {
            }

            public function project(User $model): Project
            {
                return new class ($this->root . '/' . $model->username, $this->record) extends Project {
                    public function __construct(private string $home, private object $record)
                    {
                    }

                    public function homeDirPath(): string
                    {
                        return $this->home;
                    }

                    public function copyVolumesForClone(): ?CopyVolumes
                    {
                        return null;
                    }

                    public function hasUserApp(): bool
                    {
                        return false;
                    }

                    public function fixPermissions(): void
                    {
                    }

                    public function handStagedHomeToAccount(string $stagedHome): void
                    {
                    }

                    public function start(): void
                    {
                        if ($this->record->failStartTimes > 0) {
                            $this->record->failStartTimes--;
                            throw new \RuntimeException('start exploded');
                        }
                    }
                };
            }

            public function mysql(): Mysql
            {
                return new PushFileRollbackMysql($this->record);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = array_values(array_filter(
                    is_array($cmd) ? $cmd : [$cmd],
                    static fn (string $part): bool => $part !== 'sudo',
                ));

                if ($this->record->failCommand !== null && ($this->record->failCommand)($argv)) {
                    return $this->finished(['false']);
                }

                return match ($argv[0]) {
                    'du' => $this->finished(['printf', "1\t%s\n", end($argv)]),
                    'df' => $this->finished(['printf', "Filesystem 1K-blocks Used Available Use%% Mounted\nfs 1 1 99999999 1%% /\n"]),
                    'rsync' => $this->finished(['cp', '-a', $argv[count($argv) - 2] . '.', end($argv)]),
                    'chown', 'chmod' => $this->finished(['true']),
                    default => $this->finished($argv),
                };
            }

            /**
             * @param list<string> $argv
             */
            private function finished(array $argv): Process
            {
                $process = new Process($argv);
                $process->run();

                return $process;
            }
        };
    }
}

class PushFileRollbackMysql extends Mysql
{
    public function __construct(private object $record)
    {
        parent::__construct(null);
    }

    public function dumpToGzip(string $name, string $dest, ?string $table = null): void
    {
        file_put_contents($dest, 'dump');
    }

    public function restoreFromGzip(string $name, string $sqlGzPath): void
    {
        $this->record->restores[] = [$name, $sqlGzPath];
    }

    public function runSitesDbScript(string $script, string $failurePrefix): void
    {
    }

    public function databases(): Databases
    {
        return new class extends Databases {
            public function __construct()
            {
                parent::__construct(new PDO('sqlite::memory:'));
            }

            public function createDatabase(string $name): void
            {
            }

            public function deleteDatabase(string $name): void
            {
            }

            public function databaseExists(string $name): bool
            {
                return true;
            }
        };
    }

    public function privileges(): Privileges
    {
        return new class extends Privileges {
            public function __construct()
            {
                parent::__construct(new PDO('sqlite::memory:'), '%');
            }

            public function showPrivileges(string $user): array
            {
                return [];
            }

            public function updatePrivileges(string $user, string $database, string $privileges): void
            {
            }
        };
    }
}
