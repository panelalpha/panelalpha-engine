<?php

namespace Tests\Integration\System;

use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\ContainerOperations;
use App\System\Project\Dind\CopyVolumes;
use App\System\Project\Dind\Paths;
use App\System\Projects;
use App\System\Services\Mysql\Tables;
use PDO;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * Pins the whole list each create, push and table preflight returns, in order, and the commands
 * and verdict of the push's disk-space check.
 */
class ProjectsPreflightListsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_create_problems_list_every_refusal_then_the_database_names(): void
    {
        $live = $this->makeUser('live');
        $alice = $this->makeUser('alice', ['mysql_prefix' => 'alice_']);
        $alice->status = 'pending';
        $alice->staging = $live->id;
        $alice->save();
        $staging = $this->makeUser('alicestg');
        $staging->staging = $alice->id;
        $staging->save();
        $alice->mysqlDatabases()->create(['database' => 'alice_wp']);
        $alice->mysqlDatabases()->create(['database' => 'alice_wp$x']);
        $alice->mysqlDatabases()->create(['database' => 'alice_'.str_repeat('d', 60)]);
        $alice->mysqlUsers()->create(['user' => 'alice_'.str_repeat('u', 30)]);
        $alice->mysqlUsers()->create(['user' => 'alice_ok']);
        $alice->refresh();

        $refusals = [
            ['field' => 'project', 'code' => 'project_pending', 'message' => "Project 'alice' is pending."],
            ['field' => 'project', 'code' => 'project_is_staging', 'message' => "Project 'alice' is a staging project and cannot create staging."],
            ['field' => 'project', 'code' => 'project_has_staging', 'message' => 'Project already has a staging.'],
        ];
        $this->assertSame($refusals, Projects::createProblems($alice));
        $this->assertSame([
            ...$refusals,
            ['field' => 'new_username', 'code' => 'database_name_unsupported', 'message' => "Database 'alice_wp\$x' is outside the name grammar the engine can copy."],
            ['field' => 'new_username', 'code' => 'database_name_too_long', 'message' => "Database 'alice_".str_repeat('d', 60)."' maps to 'bobstaging_".str_repeat('d', 60)."', longer than 64 characters."],
            ['field' => 'new_username', 'code' => 'database_user_name_too_long', 'message' => "MySQL user 'alice_".str_repeat('u', 30)."' maps to 'bobstaging_".str_repeat('u', 30)."', longer than 32 characters."],
        ], Projects::createProblems($alice, 'bobstaging_'));
    }

    public function test_create_problems_of_a_project_not_saved_yet_ask_nothing_of_the_database(): void
    {
        $draft = new User(['username' => 'draft', 'domain' => 'draft.example.test']);
        $draft->status = 'pending';

        $this->assertSame(
            [['field' => 'project', 'code' => 'project_pending', 'message' => "Project 'draft' is pending."]],
            Projects::createProblems($draft, 'bob_'),
        );
        $this->assertSame([], Projects::createProblems(new User(['username' => 'fresh']), 'bob_'));
    }

    public function test_push_problems_list_every_refusal_then_the_database_names(): void
    {
        $alice = $this->makeUser('alice', ['mysql_prefix' => 'alice_', 'template' => 'dind']);
        $alice->status = 'pending';
        $alice->save();
        $alice->mysqlDatabases()->create(['database' => 'alice_'.str_repeat('d', 60)]);
        $bob = $this->makeUser('bob', ['mysql_prefix' => 'bobstaging_', 'template' => 'php']);
        $bob->status = 'pending';
        $bob->save();

        $this->assertSame([
            ['field' => 'target', 'code' => 'target_template_mismatch', 'message' => "Projects 'alice' (dind) and 'bob' (php) have different templates."],
            ['field' => 'project', 'code' => 'project_pending', 'message' => "Project 'alice' is pending."],
            ['field' => 'target', 'code' => 'target_pending', 'message' => "Project 'bob' is pending."],
            ['field' => 'target', 'code' => 'target_not_staging_pair', 'message' => "Projects 'alice' and 'bob' are not a staging pair."],
            ['field' => 'target', 'code' => 'database_name_too_long', 'message' => "Database 'alice_".str_repeat('d', 60)."' maps to 'bobstaging_".str_repeat('d', 60)."', longer than 64 characters."],
        ], Projects::pushProblems($alice->refresh(), $bob->refresh()));
    }

    public function test_push_problems_of_a_project_onto_itself(): void
    {
        $alice = $this->makeUser('alice', ['mysql_prefix' => 'alice_']);

        $this->assertSame([
            ['field' => 'target', 'code' => 'target_same_project', 'message' => 'Cannot push to the same project.'],
            ['field' => 'target', 'code' => 'target_not_staging_pair', 'message' => "Projects 'alice' and 'alice' are not a staging pair."],
        ], Projects::pushProblems($alice, $alice));
    }

    public function test_push_problems_of_a_staging_pair_either_way_round(): void
    {
        $alice = $this->makeUser('alice', ['mysql_prefix' => 'alice_']);
        $bob = $this->makeUser('bob', ['mysql_prefix' => 'bob_', 'template' => 'php']);
        $bob->staging = $alice->id;
        $bob->save();

        $mismatch = [['field' => 'target', 'code' => 'target_template_mismatch', 'message' => "Projects 'alice' (null) and 'bob' (php) have different templates."]];
        $this->assertSame($mismatch, Projects::pushProblems($alice->refresh(), $bob->refresh()));

        $carol = $this->makeUser('carol', ['template' => 'php']);
        $carol->staging = $bob->id;
        $carol->save();
        $this->assertSame([], Projects::pushProblems($carol->refresh(), $bob->refresh()));
    }

    public function test_table_problems_skip_malformed_selections_and_ask_each_database_once(): void
    {
        $alice = $this->makeUser('alice', ['mysql_prefix' => 'alice_']);
        $alice->mysqlDatabases()->create(['database' => 'alice_wp']);
        $alice->mysqlDatabases()->create(['database' => 'alice_shop']);
        $tables = new class(['alice_wp' => ['wp_posts', 'wp_users'], 'alice_shop' => ['orders']]) extends Tables
        {
            /** @var list<string> */
            public array $asked = [];

            /**
             * @param  array<string, list<string>>  $byDatabase
             */
            public function __construct(private array $byDatabase)
            {
                parent::__construct(new PDO('sqlite::memory:'));
            }

            public function names(string $database): array
            {
                $this->asked[] = $database;

                return $this->byDatabase[$database] ?? [];
            }
        };

        $problems = Projects::tableProblems($alice->refresh(), [
            'alice_wp.wp_posts',
            ['database' => 5, 'name' => 'wp_posts'],
            ['database' => 'alice_wp'],
            ['database' => 'other_db', 'name' => 'wp_posts'],
            ['database' => 'alice_wp', 'name' => 'wp_posts'],
            ['database' => 'alice_wp', 'name' => 'wp_absent'],
            ['database' => 'alice_shop', 'name' => 'orders'],
            ['database' => 'other_db', 'name' => 'orders'],
            ['database' => 'alice_wp', 'name' => 'wp_users'],
            ['database' => 'alice_shop', 'name' => 'refunds'],
        ], $tables);

        $this->assertSame([
            ['field' => 'tables', 'code' => 'database_not_found', 'message' => "The source project does not own database 'other_db'."],
            ['field' => 'tables', 'code' => 'table_not_found', 'message' => "Table 'alice_wp.wp_absent' does not exist in the source project."],
            ['field' => 'tables', 'code' => 'database_not_found', 'message' => "The source project does not own database 'other_db'."],
            ['field' => 'tables', 'code' => 'table_not_found', 'message' => "Table 'alice_shop.refunds' does not exist in the source project."],
        ], $problems);
        $this->assertSame(['alice_wp', 'alice_shop'], $tables->asked);
    }

    public function test_the_push_disk_check_sums_the_home_and_the_volume_data_against_df(): void
    {
        $system = $this->diskAnswers([
            'ls -1A -- /home/src' => FakeProcess::ok("index.php\n.incoming\n\n docker \n.pre-push\nwp\n"),
            'test -e /home/src/index.php' => FakeProcess::ok(),
            'du -sb -- /home/src/index.php' => FakeProcess::ok("1000\t/home/src/index.php\n"),
            'test -d /home/src/wp' => FakeProcess::ok(),
            'du -sb -- /home/src/wp' => FakeProcess::ok("20000 /home/src/wp\n"),
            'pa-root account-docker.listVolumes -- src' => FakeProcess::ok('[{"Name":"project_db"},{"Name":"project_files"}]'),
            'test -d /home/src/docker/volumes' => FakeProcess::ok(),
            'ls -1 -- /home/src/docker/volumes' => FakeProcess::ok("project_db\nmetadata.db\nproject_files\n"),
            'test -d /home/src/docker/volumes/project_db/_data' => FakeProcess::ok(),
            'du -sb -- /home/src/docker/volumes/project_db/_data' => FakeProcess::ok("300000\n"),
            'df -P -- /home/dst' => FakeProcess::ok("Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/sda1 1000 500 300 63% /\n"),
        ]);

        $this->assertSame(
            'Insufficient disk space on target for push (required 321000 B, available 307200 B).',
            $this->diskCheck($system, true) ?? 'no refusal',
        );
        $volumes = '/home/src/docker/volumes';
        $this->assertSame([
            'ls -1A -- /home/src',
            'test -d /home/src/index.php',
            'test -e /home/src/index.php',
            'du -sb -- /home/src/index.php',
            'test -d /home/src/wp',
            'du -sb -- /home/src/wp',
            'pa-root account-docker.listVolumes -- src',
            "test -d {$volumes}",
            "ls -1 -- {$volumes}",
            "test -d {$volumes}/project_db/_data",
            "test -d {$volumes}/project_db/_data",
            "du -sb -- {$volumes}/project_db/_data",
            "test -d {$volumes}/project_files/_data",
            'df -P -- /home/dst',
        ], $system->asked);
    }

    public function test_the_push_disk_check_messages_and_df_failures(): void
    {
        $home = ['ls -1A -- /home/src' => FakeProcess::ok("big\n"), 'test -d /home/src/big' => FakeProcess::ok()];
        $verdicts = [];
        foreach ([1536, 3 * 1048576, 2 * 1073741824 + 1] as $bytes) {
            $verdicts[] = $this->diskCheck($this->diskAnswers($home + [
                'du -sb -- /home/src/big' => FakeProcess::ok("{$bytes}\n"),
                'df -P -- /home/dst' => FakeProcess::ok("header\n/dev/sda1 1 1 1 1% /\n"),
            ]), false);
        }
        $verdicts[] = $this->diskCheck($this->diskAnswers($home + [
            'du -sb -- /home/src/big' => FakeProcess::ok("1024\n"),
            'df -P -- /home/dst' => FakeProcess::ok("header\n/dev/sda1 1 1 1 1% /\n"),
        ]), false);
        $verdicts[] = $this->diskCheck($this->diskAnswers($home + [
            'du -sb -- /home/src/big' => FakeProcess::ok("1024\n"),
            'df -P -- /home/dst' => FakeProcess::failed('df: no such file'),
        ]), false);
        $verdicts[] = $this->diskCheck($this->diskAnswers([
            'ls -1A -- /home/src' => FakeProcess::failed(),
            'df -P -- /home/dst' => FakeProcess::ok("only a header\n"),
        ]), true);

        $this->assertSame([
            'Insufficient disk space on target for push (required 1536 B, available 1024 B).',
            'Insufficient disk space on target for push (required 3 MiB, available 1024 B).',
            'Insufficient disk space on target for push (required 2 GiB, available 1024 B).',
            null,
            'Could not determine available disk space on target home.',
            null,
        ], $verdicts);
    }

    /**
     * @param  array<string, Process>  $answers
     */
    private function diskAnswers(array $answers): System
    {
        return new class($answers) extends System
        {
            /** @var list<string> */
            public array $asked = [];

            /**
             * @param  array<string, Process>  $answers
             */
            public function __construct(private array $answers) {}

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $line = is_array($cmd) ? implode(' ', LocalRoot::plain($cmd)) : $cmd;
                $this->asked[] = $line;

                return $this->answers[$line] ?? FakeProcess::failed();
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $process = $this->runProcess($cmd);
                if (! $process->isSuccessful()) {
                    throw new \RuntimeException('container src is not running');
                }

                return $process->getOutput();
            }
        };
    }

    private function diskCheck(System $system, bool $isDindDest): ?string
    {
        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn('src');
        $dind->method('homeDirPath')->willReturn('/home/src');
        $dind->method('system')->willReturn($system);
        $dind->method('accountDaemon')->willReturnCallback(static fn (): AccountDaemon => new AccountDaemon($dind));
        $volumes = new CopyVolumes(
            $dind,
            (new \ReflectionClass(ContainerOperations::class))->newInstanceWithoutConstructor(),
            new Paths($dind),
        );
        $fromProject = $this->createStub(Project::class);
        $fromProject->method('copyVolumesForClone')->willReturn($volumes);

        try {
            (new \ReflectionMethod(Projects::class, 'assertPushDiskSpace'))
                ->invoke(new Projects($system), $fromProject, '/home/src', '/home/dst', $isDindDest);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }
}
