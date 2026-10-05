<?php

namespace Tests\Unit\Files;

use App\Http\Middleware\Authenticate;
use App\Mcp\Tools\Api\Files\FileDeleteTool;
use App\Models\User;
use App\System;
use App\System\Project\FileManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * file_delete says "file or directory", but a directory only goes with
 * `recursive`, which the tool did not expose; rm's "Is a directory" came back
 * as a 400.
 */
class FileRemoveHttpTest extends TestCase
{
    private string $tmpRoot;

    /** @var System&object{processJournal: list<array<int, string>>} */
    private System $system;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/pa-file-remove-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/home/alice/dir/sub', 0777, true);
        file_put_contents($this->tmpRoot . '/home/alice/file.txt', 'x');

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        (require base_path('database/migrations/2014_10_12_000000_create_users_table.php'))->up();

        $user = new User();
        $user->username = 'alice';
        $user->domain = 'alice.test';
        $user->password = 'secret';
        $user->setDetails(['template' => 'default', 'UID' => 1001, 'GID' => 1001]);
        $user->save();

        $this->system = $this->fakeSystem($this->tmpRoot);
        $this->app->instance(System::class, $this->system);
        $this->withoutMiddleware(Authenticate::class);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        exec('rm -rf ' . escapeshellarg($this->tmpRoot));
        parent::tearDown();
    }

    public function test_a_directory_without_recursive_is_a_clear_422_and_nothing_runs(): void
    {
        $response = $this->deleteJson('/api/projects/alice/files/remove?path=dir');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('recursive');
        $this->assertSame([], $this->system->processJournal);
    }

    public function test_a_directory_with_recursive_is_removed_with_rm_r(): void
    {
        $this->deleteJson('/api/projects/alice/files/remove?path=dir&recursive=1')->assertOk();

        $this->assertSame(['rm', '-f', '-r', $this->tmpRoot . '/home/alice/dir'], $this->rmCall());
    }

    public function test_a_file_needs_no_recursive(): void
    {
        $this->deleteJson('/api/projects/alice/files/remove?path=file.txt')->assertOk();

        $this->assertSame(['rm', '-f', $this->tmpRoot . '/home/alice/file.txt'], $this->rmCall());
    }

    public function test_a_link_whose_target_is_missing_is_removed(): void
    {
        symlink($this->tmpRoot . '/gone', $this->tmpRoot . '/home/alice/dangling');

        $this->deleteJson('/api/projects/alice/files/remove?path=dangling')->assertOk();

        $this->assertSame(['rm', '-f', $this->tmpRoot . '/home/alice/dangling'], $this->rmCall());
    }

    public function test_a_link_to_a_directory_outside_removes_the_link_without_recursive(): void
    {
        mkdir($this->tmpRoot . '/outside');
        symlink($this->tmpRoot . '/outside', $this->tmpRoot . '/home/alice/out');

        $this->deleteJson('/api/projects/alice/files/remove?path=out')->assertOk();

        $this->assertSame(['rm', '-f', $this->tmpRoot . '/home/alice/out'], $this->rmCall());
    }

    public function test_a_missing_path_is_still_a_404_and_nothing_runs(): void
    {
        $this->deleteJson('/api/projects/alice/files/remove?path=nothing-here')
            ->assertNotFound()
            ->assertJson(['message' => 'Invalid path']);

        $this->assertSame([], $this->system->processJournal);
    }

    public function test_the_tool_forwards_recursive(): void
    {
        $response = (new FileDeleteTool())->handle(new Request([
            'name' => 'alice',
            'path' => 'dir',
            'recursive' => true,
        ]));

        $this->assertFalse($response->isError(), (string) $response->content());
        $this->assertSame(['rm', '-f', '-r', $this->tmpRoot . '/home/alice/dir'], $this->rmCall());
    }

    /** @return list<string> the rm argv, without the setpriv prefix */
    private function rmCall(): array
    {
        // The confinement check runs first, then rm.
        $this->assertCount(2, $this->system->processJournal);
        $this->assertContains(FileManager::CONFINE_SCRIPT, $this->system->processJournal[0]);
        $argv = $this->system->processJournal[1];

        return array_values(array_slice($argv, (int) array_search('rm', $argv, true)));
    }

    private function fakeSystem(string $root): System
    {
        return new class ($root) extends System {
            /** @var list<array<int, string>> */
            public array $processJournal = [];

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

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->processJournal[] = is_array($cmd) ? $cmd : [$cmd];

                return new class () extends Process {
                    public function __construct()
                    {
                        parent::__construct(['true']);
                    }

                    public function getExitCode(): ?int
                    {
                        return 0;
                    }
                };
            }
        };
    }
}
