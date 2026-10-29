<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Project\PhpHosting\WordPress\Database;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\WpCli;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Contract for named database export/import: success body, failure as
 * Exception, and the WP-CLI arguments the runner received (including resolved
 * --path). Import strips DEFINER clauses in the same WP-CLI run that imports.
 */
class DatabaseTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/database-test-'.bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->remove($this->tmp);
        Mockery::close();
        parent::tearDown();
    }

    public function test_export_returns_body_and_passes_default_path(): void
    {
        $sequence = [];
        $op = $this->databaseWithFakeRunner(
            $sequence,
            results: [['exit_code' => 0, 'stdout' => "Success: Exported to 'backup.sql'.\n", 'stderr' => '']],
        );

        $body = $op->export('/home/alice/backup.sql');

        $this->assertSame(
            [
                'db',
                'export',
                '/home/alice/backup.sql',
                '--exec='.$this->ownerOnlyCodeOf('/home/alice/backup.sql'),
                '--path=/home/alice/example.test/public_html',
            ],
            $sequence[0]
        );
        $this->assertSame(
            [
                'file' => '/home/alice/backup.sql',
                'message' => "Success: Exported to 'backup.sql'.",
            ],
            $body
        );
    }

    public function test_export_with_explicit_path(): void
    {
        $sequence = [];
        $op = $this->databaseWithFakeRunner(
            $sequence,
            results: [['exit_code' => 0, 'stdout' => "Success: Exported.\n", 'stderr' => '']],
            path: '/home/alice/other.test/public_html',
        );

        $op->export('/home/alice/dump.sql');

        $this->assertSame(
            [
                'db',
                'export',
                '/home/alice/dump.sql',
                '--exec='.$this->ownerOnlyCodeOf('/home/alice/dump.sql'),
                '--path=/home/alice/other.test/public_html',
            ],
            $sequence[0]
        );
    }

    public function test_import_strips_definer_in_the_same_wp_cli_run_that_imports(): void
    {
        $sequence = [];
        $op = $this->databaseWithFakeRunner(
            $sequence,
            results: [['exit_code' => 0, 'stdout' => "Success: Imported from 'backup.sql'.\n", 'stderr' => '']],
        );

        $file = '/home/alice/backup.sql';
        $body = $op->import($file);

        $this->assertCount(1, $sequence, 'one WP-CLI run: the same container and user strip and read the file');
        $this->assertSame(['db', 'import', $file], array_slice($sequence[0], 0, 3));
        $this->assertStringStartsWith('--exec=', $sequence[0][3]);
        $this->assertSame('--path=/home/alice/example.test/public_html', $sequence[0][4]);
        $this->assertSame(
            [
                'file' => $file,
                'message' => "Success: Imported from 'backup.sql'.",
            ],
            $body
        );
    }

    public function test_the_import_run_removes_definer_clauses_from_the_file(): void
    {
        $file = $this->tmp.'/dump.sql';
        file_put_contents($file, "/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */\nINSERT INTO t VALUES ('DEFINER=x');\n");

        $run = $this->runRemoveDefinerCode($file);

        $this->assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $this->assertSame(
            "/*!50013 */\nINSERT INTO t VALUES ('DEFINER=x');\n",
            file_get_contents($file)
        );
    }

    public function test_the_import_run_stops_when_definer_clauses_cannot_be_removed(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes a read-only directory');
        }
        $file = $this->tmp.'/locked/dump.sql';
        mkdir(dirname($file));
        file_put_contents($file, "/*!50017 DEFINER=`old`@`%`*/ CREATE TRIGGER t;\n");
        chmod(dirname($file), 0555);

        $run = $this->runRemoveDefinerCode($file);
        chmod(dirname($file), 0755);

        $this->assertSame(1, $run->getExitCode());
        $this->assertStringStartsWith(
            "Error: Could not remove the DEFINER clauses from {$file}, so it was not imported: ",
            $run->getErrorOutput()
        );
        $this->assertStringContainsString('DEFINER=`old`', (string) file_get_contents($file));
    }

    public function test_a_file_without_definer_is_imported_from_a_read_only_directory(): void
    {
        $file = $this->tmp.'/locked/dump.sql';
        mkdir(dirname($file));
        file_put_contents($file, "INSERT INTO t VALUES (1);\n");
        chmod(dirname($file), 0555);

        $run = $this->runRemoveDefinerCode($file);
        chmod(dirname($file), 0755);

        $this->assertSame(0, $run->getExitCode(), $run->getErrorOutput());
    }

    public function test_a_file_path_with_a_quote_is_one_shell_argument(): void
    {
        $file = $this->tmp."/it's here.sql";
        file_put_contents($file, "/*!50013 DEFINER=`root`@`localhost`*/\n");

        $run = $this->runRemoveDefinerCode($file);

        $this->assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $this->assertSame("/*!50013 */\n", file_get_contents($file));
    }

    /** Runs the import's --exec code the way WP-CLI evaluates it, with WP_CLI::error exiting 1. */
    private function runRemoveDefinerCode(string $file): Process
    {
        $sequence = [];
        $this->databaseWithFakeRunner($sequence, home: $this->tmp)->import($file);
        $code = substr($sequence[0][3], strlen('--exec='));
        $runner = 'class WP_CLI { public static function error(string $m): void { fwrite(STDERR, "Error: $m\n"); exit(1); } }';
        $process = new Process([PHP_BINARY, '-r', $runner.$code]);
        $process->run();

        return $process;
    }

    public function test_export_only_the_given_tables(): void
    {
        $sequence = [];
        $op = $this->databaseWithFakeRunner($sequence);

        $op->export('/home/alice/views.sql', ['wp_view_a', 'wp_view_b']);

        $this->assertSame(
            ['db', 'export', '/home/alice/views.sql', '--tables=wp_view_a,wp_view_b', '--exec='.$this->ownerOnlyCodeOf('/home/alice/views.sql'), '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
    }

    public function test_the_export_run_creates_the_dump_readable_by_its_owner_only(): void
    {
        $file = $this->tmp.'/dump.sql';

        $run = $this->runOwnerOnlyCodeThenWrite($file);

        $this->assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $this->assertSame(0600, fileperms($file) & 0777);
    }

    public function test_the_export_run_narrows_a_dump_already_there(): void
    {
        $file = $this->tmp.'/dump.sql';
        file_put_contents($file, 'old');
        chmod($file, 0666);

        $run = $this->runOwnerOnlyCodeThenWrite($file);

        $this->assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $this->assertSame(0600, fileperms($file) & 0777);
    }

    public function test_the_export_run_stops_before_writing_into_a_file_of_another_user(): void
    {
        if (function_exists('posix_geteuid') && (posix_geteuid() === 0 || fileowner('/etc/passwd') !== 0)) {
            $this->markTestSkipped('needs a file owned by another user');
        }

        $run = $this->runOwnerOnlyCodeThenWrite('/etc/passwd');

        $this->assertSame(1, $run->getExitCode());
        $this->assertStringStartsWith('Error: Could not make /etc/passwd private to its owner', $run->getErrorOutput());
    }

    private function ownerOnlyCodeOf(string $file): string
    {
        $sequence = [];
        $this->databaseWithFakeRunner($sequence, home: dirname($file))->export($file);
        $exec = array_values(array_filter($sequence[0], fn (string $arg): bool => str_starts_with($arg, '--exec=')));

        return substr($exec[0], strlen('--exec='));
    }

    /** Runs the export's --exec code the way WP-CLI evaluates it, then writes the file from a child process as mysqldump does. */
    private function runOwnerOnlyCodeThenWrite(string $file): Process
    {
        $runner = 'class WP_CLI { public static function error(string $m): void { fwrite(STDERR, "Error: $m\n"); exit(1); } }';
        $write = 'exec("echo dump > " . escapeshellarg('.var_export($file, true).'));';
        $process = new Process([PHP_BINARY, '-r', $runner.$this->ownerOnlyCodeOf($file).$write]);
        $process->run();

        return $process;
    }

    public function test_import_with_explicit_path(): void
    {
        $sequence = [];
        $op = $this->databaseWithFakeRunner(
            $sequence,
            results: [['exit_code' => 0, 'stdout' => "Success: Imported.\n", 'stderr' => '']],
            path: '/home/alice/other.test/public_html',
        );

        $op->import('/home/alice/dump.sql');

        $this->assertCount(1, $sequence);
        $this->assertSame(['db', 'import', '/home/alice/dump.sql'], array_slice($sequence[0], 0, 3));
        $this->assertSame('--path=/home/alice/other.test/public_html', $sequence[0][4]);
    }

    public function test_export_nonzero_exit_throws_with_command_output(): void
    {
        $sequence = [];
        $op = $this->databaseWithFakeRunner(
            $sequence,
            results: [['exit_code' => 1, 'stdout' => 'Error: Failed to export.', 'stderr' => '']],
        );

        try {
            $op->export('/home/alice/x.sql');
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame('Error: Failed to export.', $e->getMessage());
            $this->assertSame(422, $e->httpStatus);
        }
    }

    public function test_import_nonzero_exit_on_db_import_throws(): void
    {
        $sequence = [];
        $op = $this->databaseWithFakeRunner(
            $sequence,
            results: [['exit_code' => 1, 'stdout' => 'Error: Failed to import.', 'stderr' => '']],
        );

        try {
            $op->import('/home/alice/x.sql');
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame('Error: Failed to import.', $e->getMessage());
            $this->assertSame(422, $e->httpStatus);
        }
        $this->assertCount(1, $sequence);
    }

    public function test_import_fails_when_mysql_rejected_a_statement_although_wp_cli_reported_success(): void
    {
        $sequence = [];
        $op = $this->databaseWithFakeRunner(
            $sequence,
            results: [
                [
                    'exit_code' => 0,
                    'stdout' => "Success: Imported from '/home/alice/x.sql'.\n",
                    'stderr' => "mysql: Deprecated program name. It will be removed in a future release, use '/usr/bin/mariadb' instead\n"
                        ."--------------\nCREATE DEFINER=`ghost`@`%` SQL SECURITY DEFINER VIEW v1 AS SELECT a FROM t1\n--------------\n\n"
                        ."ERROR 1227 (42000) at line 3 in file: '/home/alice/x.sql': Access denied; you need (at least one of) "
                        ."the SET USER privilege(s) for this operation\n",
                ],
            ],
        );

        try {
            $op->import('/home/alice/x.sql');
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertStringStartsWith('MySQL rejected 1 statement(s) in /home/alice/x.sql, so it was not fully imported', $e->getMessage());
            $this->assertStringContainsString("\nERROR 1227 (42000) at line 3 in file: '/home/alice/x.sql': Access denied;", $e->getMessage());
            $this->assertStringNotContainsString('Success', $e->getMessage());
        }
    }

    public function test_an_import_failure_names_the_first_rejected_statements_and_counts_the_rest(): void
    {
        $sequence = [];
        $errors = '';
        for ($line = 1; $line <= 7; $line++) {
            $errors .= "ERROR 1050 (42S01) at line {$line} in file: '/home/alice/x.sql': Table 't{$line}' already exists\n";
        }
        $op = $this->databaseWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => "Success: Imported from '/home/alice/x.sql'.\n", 'stderr' => $errors],
            ],
        );

        try {
            $op->import('/home/alice/x.sql');
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertStringContainsString('MySQL rejected 7 statement(s)', $e->getMessage());
            $this->assertStringContainsString("Table 't5' already exists\n...and 2 more.", $e->getMessage());
            $this->assertStringNotContainsString("'t6'", $e->getMessage());
        }
    }

    public function test_an_import_succeeds_with_other_client_output_on_stderr(): void
    {
        $sequence = [];
        $op = $this->databaseWithFakeRunner(
            $sequence,
            results: [
                [
                    'exit_code' => 0,
                    'stdout' => "Success: Imported from '/home/alice/x.sql'.\n",
                    'stderr' => "mysql: Deprecated program name. It will be removed in a future release, use '/usr/bin/mariadb' instead\n",
                ],
            ],
        );

        $body = $op->import('/home/alice/x.sql');

        $this->assertSame("Success: Imported from '/home/alice/x.sql'.", $body['message']);
    }

    /**
     * @param  list<list<string>>  $sequence
     * @param  list<array{exit_code: int, stdout: string, stderr: string}>  $results
     */
    private function databaseWithFakeRunner(
        array &$sequence,
        array $results = [['exit_code' => 0, 'stdout' => '', 'stderr' => '']],
        ?string $path = null,
        string $home = '/home/alice',
    ): Database {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn($home);
        $project->shouldReceive('resolvePath')
            ->andReturnUsing(fn (string $path): string => (new FileManager($project))->resolvePath($path));
        $call = 0;
        $project->shouldReceive('runWpCli')
            ->andReturnUsing(function (array $args) use (&$sequence, &$call, $results): array {
                $sequence[] = $args;
                $result = $results[$call] ?? ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
                $call++;

                return $result;
            });
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldReceive('getMainDomain')->andReturn($this->domain('/example.test/public_html'));

        return new Database(WpCli::for($user, $path));
    }

    private function domain(string $documentRoot): Domain
    {
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn($documentRoot);

        return $domain;
    }
}
