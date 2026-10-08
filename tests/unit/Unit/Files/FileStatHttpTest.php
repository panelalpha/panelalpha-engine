<?php

namespace Tests\Unit\Files;

use App\Http\Middleware\Authenticate;
use App\Models\User;
use App\System;
use App\System\Project\FileManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The controller checked file_exists() itself, as the engine's PHP user, before
 * stat ran as the account; a file in a directory only the account can enter
 * was a 404. The file layer answers now, as the account, and stat and exists
 * refuse a link out of the home like every other file operation.
 */
class FileStatHttpTest extends TestCase
{
    private string $tmpRoot;

    /** @var System&object{processJournal: list<array<int, string>>, accountSees: array<string, string>} */
    private System $system;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/pa-file-stat-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/home/alice', 0777, true);
        file_put_contents($this->tmpRoot . '/home/alice/file.txt', 'hello');
        symlink($this->tmpRoot . '/home/alice/gone', $this->tmpRoot . '/home/alice/dangling');
        mkdir($this->tmpRoot . '/home/bob', 0777, true);
        file_put_contents($this->tmpRoot . '/home/bob/theirs.txt', 'bob');
        symlink($this->tmpRoot . '/home/bob', $this->tmpRoot . '/home/alice/bob');
        symlink($this->tmpRoot . '/home/bob/gone', $this->tmpRoot . '/home/alice/dangling-out');

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

    public function test_a_file_is_stated(): void
    {
        $this->getJson('/api/projects/alice/files/stat?path=file.txt')
            ->assertOk()
            ->assertJson(['size' => '5']);
    }

    public function test_a_missing_path_is_still_a_404_and_stat_never_runs(): void
    {
        $this->getJson('/api/projects/alice/files/stat?path=nothing-here')
            ->assertNotFound()
            ->assertJson(['message' => 'Invalid path']);

        $this->assertCount(2, $this->system->processJournal);
        $this->assertContains(FileManager::CONFINE_SCRIPT, $this->system->processJournal[0]);
        $this->assertContains(FileManager::ENTRY_TYPE_SCRIPT, $this->system->processJournal[1]);
    }

    public function test_stat_and_exists_through_a_link_out_of_the_home_are_refused(): void
    {
        foreach (['bob/theirs.txt', 'bob/nothing.txt', 'dangling-out'] as $path) {
            foreach (['stat', 'exists'] as $endpoint) {
                $this->getJson("/api/projects/alice/files/{$endpoint}?path={$path}")
                    ->assertStatus(400)
                    ->assertJsonPath('message', "Refusing {$this->tmpRoot}/home/alice/{$path}: it resolves outside the project home\n (exit code 3)");
            }
        }
    }

    public function test_exists_inside_the_home_is_unchanged(): void
    {
        $this->getJson('/api/projects/alice/files/exists?path=file.txt')->assertOk()->assertJson(['exists' => true]);
        $this->getJson('/api/projects/alice/files/exists?path=nothing-here')->assertOk()->assertJson(['exists' => false]);
        $this->getJson('/api/projects/alice/files/exists?path=dangling')->assertOk()->assertJson(['exists' => false]);
    }

    public function test_a_link_whose_target_is_gone_is_still_a_404(): void
    {
        $this->getJson('/api/projects/alice/files/stat?path=dangling')
            ->assertNotFound()
            ->assertJson(['message' => 'Invalid path']);
    }

    public function test_a_file_only_the_account_can_see_is_stated(): void
    {
        $path = $this->tmpRoot . '/home/alice/private/secret.txt';
        $this->system->accountSees[$path] = 'file';

        $this->assertFileDoesNotExist($path);
        $this->getJson('/api/projects/alice/files/stat?path=private/secret.txt')
            ->assertOk()
            ->assertJson(['file_name' => $path, 'size' => '7']);
    }

    private function fakeSystem(string $root): System
    {
        return new class ($root) extends System {
            /** @var list<array<int, string>> */
            public array $processJournal = [];

            /** @var array<string, string> path => what the account sees there, where the engine sees nothing */
            public array $accountSees = [];

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

            /** Runs as this process, setpriv dropped. */
            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->processJournal[] = is_array($cmd) ? $cmd : [$cmd];
                $argv = array_slice((array) $cmd, 7);
                $path = rtrim((string) end($argv), '/');
                if (isset($this->accountSees[$path])) {
                    $argv = in_array(FileManager::ENTRY_TYPE_SCRIPT, $argv, true)
                        ? ['echo', $this->accountSees[$path]]
                        : ['printf', '%s', "{$path} 7 1001 1001 1 2 3 4"];
                }
                $process = new Process($argv);
                $process->run();

                return $process;
            }
        };
    }
}
