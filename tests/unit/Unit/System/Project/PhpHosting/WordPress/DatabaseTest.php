<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\PhpHosting\WordPress\WpCli;
use App\System\Project\PhpHosting\WordPress\Database;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Contract for named database export/import: success body, failure as
 * Exception, and the WP-CLI arguments the runner received (including resolved
 * --path). Import asserts the internal DEFINER-strip eval before db import.
 */
class DatabaseTest extends TestCase
{
    protected function tearDown(): void
    {
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

        $op->export('/tmp/dump.sql');

        $this->assertSame(
            [
                'db',
                'export',
                '/tmp/dump.sql',
                '--path=/home/alice/other.test/public_html',
            ],
            $sequence[0]
        );
    }

    public function test_import_strips_definer_then_imports(): void
    {
        $sequence = [];
        $op = $this->databaseWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => '', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => "Success: Imported from 'backup.sql'.\n", 'stderr' => ''],
            ],
        );

        $file = '/home/alice/backup.sql';
        $body = $op->import($file);

        $this->assertCount(2, $sequence);
        $this->assertSame('eval', $sequence[0][0]);
        $this->assertSame(
            "sed -i 's/DEFINER=[^*]*\\*/\\*/g' '/home/alice/backup.sql'",
            $this->shellCommandOf($sequence[0][1])
        );
        $this->assertSame('--skip-wordpress', $sequence[0][2], 'sed must not need a loadable WordPress');
        $this->assertSame(
            '--path=/home/alice/example.test/public_html',
            $sequence[0][3]
        );
        $this->assertSame(
            [
                'db',
                'import',
                $file,
                '--path=/home/alice/example.test/public_html',
            ],
            $sequence[1]
        );
        $this->assertSame(
            [
                'file' => $file,
                'message' => "Success: Imported from 'backup.sql'.",
            ],
            $body
        );
    }

    public function test_a_file_path_with_a_quote_is_one_shell_argument(): void
    {
        $sequence = [];
        $op = $this->databaseWithFakeRunner($sequence);

        $op->import("/home/alice/it's here.sql");

        $this->assertSame(
            "sed -i 's/DEFINER=[^*]*\\*/\\*/g' '/home/alice/it'\\''s here.sql'",
            $this->shellCommandOf($sequence[0][1])
        );
    }

    /** The shell command the eval'd `exec('...');` runs. */
    private function shellCommandOf(string $evalCode): string
    {
        $this->assertMatchesRegularExpression('/^exec\((\'.*\')\);$/s', $evalCode);
        preg_match('/^exec\((\'.*\')\);$/s', $evalCode, $m);

        return eval('return ' . $m[1] . ';');
    }

    public function test_export_only_the_given_tables(): void
    {
        $sequence = [];
        $op = $this->databaseWithFakeRunner($sequence);

        $op->export('/home/alice/views.sql', ['wp_view_a', 'wp_view_b']);

        $this->assertSame(
            ['db', 'export', '/home/alice/views.sql', '--tables=wp_view_a,wp_view_b', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
    }

    public function test_import_with_explicit_path(): void
    {
        $sequence = [];
        $op = $this->databaseWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => '', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => "Success: Imported.\n", 'stderr' => ''],
            ],
            path: '/home/alice/other.test/public_html',
        );

        $op->import('/tmp/dump.sql');

        $this->assertSame(
            '--path=/home/alice/other.test/public_html',
            $sequence[0][3]
        );
        $this->assertSame(
            [
                'db',
                'import',
                '/tmp/dump.sql',
                '--path=/home/alice/other.test/public_html',
            ],
            $sequence[1]
        );
    }

    public function test_export_nonzero_exit_throws_with_command_output(): void
    {
        $sequence = [];
        $op = $this->databaseWithFakeRunner(
            $sequence,
            results: [['exit_code' => 1, 'stdout' => 'Error: Failed to export.', 'stderr' => '']],
        );

        try {
            $op->export('/tmp/x.sql');
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
            results: [
                ['exit_code' => 0, 'stdout' => '', 'stderr' => ''],
                ['exit_code' => 1, 'stdout' => 'Error: Failed to import.', 'stderr' => ''],
            ],
        );

        try {
            $op->import('/tmp/x.sql');
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame('Error: Failed to import.', $e->getMessage());
            $this->assertSame(422, $e->httpStatus);
        }
        $this->assertCount(2, $sequence);
    }

    /**
     * @param list<list<string>> $sequence
     * @param list<array{exit_code: int, stdout: string, stderr: string}> $results
     */
    private function databaseWithFakeRunner(
        array &$sequence,
        array $results = [['exit_code' => 0, 'stdout' => '', 'stderr' => '']],
        ?string $path = null,
    ): Database {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
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
