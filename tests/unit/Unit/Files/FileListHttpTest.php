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
 * The directory listing is the endpoint the panel's file manager was missing:
 * find runs as the account, NUL-separated so names with spaces survive, and a
 * path outside the home or a path that is not a directory is refused.
 */
class FileListHttpTest extends TestCase
{
    private string $tmpRoot;

    /** @var System&object{processJournal: list<array<int, string>>} */
    private System $system;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/pa-file-list-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/home/alice/site/child', 0777, true);
        file_put_contents($this->tmpRoot . '/home/alice/note.txt', 'hello');
        file_put_contents($this->tmpRoot . '/home/alice/.hidden', 'x');
        file_put_contents($this->tmpRoot . '/home/alice/with space.txt', 'spaced');
        symlink('note.txt', $this->tmpRoot . '/home/alice/link');
        mkdir($this->tmpRoot . '/home/bob');
        file_put_contents($this->tmpRoot . '/home/bob/theirs.txt', 'bob');
        symlink($this->tmpRoot . '/home/bob', $this->tmpRoot . '/home/alice/bob');

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
        $this->requireGnuFind();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        exec('rm -rf ' . escapeshellarg($this->tmpRoot));
        parent::tearDown();
    }

    public function test_it_lists_the_home_with_directories_first(): void
    {
        $response = $this->getJson('/api/projects/alice/files/list?path=%2F')
            ->assertOk()
            ->assertJsonPath('meta.path', '/')
            ->assertJsonPath('meta.truncated', false)
            ->assertJsonPath('meta.total', 6);

        $this->assertSame(
            ['site', '.hidden', 'bob', 'link', 'note.txt', 'with space.txt'],
            array_column($response->json('data'), 'name')
        );
        $this->assertSame('dir', $response->json('data.0.type'));
        $this->assertSame(5, $response->json('data.4.size'));
        $this->assertSame('file', $response->json('data.4.type'));
        $this->assertSame('/note.txt', $response->json('data.4.path'));

        $names = array_column($response->json('data'), null, 'name');
        $this->assertTrue($names['site']['has_subdirectories']);
        $this->assertSame('link', $names['link']['type']);
        $this->assertSame('note.txt', $names['link']['target']);
        $this->assertSame('file', $names['link']['target_type']);
        $this->assertSame('link', $names['bob']['type']);
        $this->assertSame('dir', $names['bob']['target_type']);
    }

    public function test_it_omits_dot_entries_when_show_hidden_is_false(): void
    {
        $response = $this->getJson('/api/projects/alice/files/list?path=%2F&show_hidden=0')->assertOk();

        $this->assertNotContains('.hidden', array_column($response->json('data'), 'name'));
    }

    public function test_a_limit_truncates_and_flags_it(): void
    {
        mkdir($this->tmpRoot . '/home/alice/limited');
        foreach (['a.txt', 'b.txt', 'c.txt'] as $name) {
            file_put_contents($this->tmpRoot . '/home/alice/limited/' . $name, 'x');
        }

        $response = $this->getJson('/api/projects/alice/files/list?path=limited&limit=2')
            ->assertOk()
            ->assertJsonPath('meta.path', '/limited')
            ->assertJsonPath('meta.truncated', true)
            ->assertJsonPath('meta.total', 3);

        $this->assertSame(['a.txt', 'b.txt'], array_column($response->json('data'), 'name'));
    }

    public function test_a_missing_path_is_a_404_and_find_never_runs(): void
    {
        $this->getJson('/api/projects/alice/files/list?path=nothing-here')
            ->assertNotFound()
            ->assertJson(['message' => 'Invalid path']);

        $this->assertCount(2, $this->system->processJournal);
        $this->assertContains(FileManager::CONFINE_SCRIPT, $this->system->processJournal[0]);
        $this->assertContains(FileManager::ENTRY_TYPE_SCRIPT, $this->system->processJournal[1]);
    }

    public function test_a_file_is_refused_with_not_a_directory(): void
    {
        $this->getJson('/api/projects/alice/files/list?path=note.txt')
            ->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'path')
            ->assertJsonPath('problems.0.code', 'not_a_directory');
    }

    public function test_listing_through_a_link_out_of_the_home_is_refused(): void
    {
        $this->getJson('/api/projects/alice/files/list?path=bob')
            ->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'path')
            ->assertJsonPath('problems.0.code', 'outside_home');
    }

    public function test_stat_returns_the_entry_shape(): void
    {
        $response = $this->getJson('/api/projects/alice/files/stat?path=note.txt')
            ->assertOk()
            ->assertJsonPath('data.name', 'note.txt')
            ->assertJsonPath('data.path', '/note.txt')
            ->assertJsonPath('data.type', 'file')
            ->assertJsonPath('data.size', 5)
            ->assertJsonPath('data.target', null)
            ->assertJsonPath('data.target_type', null);

        $this->assertIsInt($response->json('data.uid'));
        $this->assertIsInt($response->json('data.gid'));
        $this->assertMatchesRegularExpression('/^0[0-7]{3}$/', (string) $response->json('data.mode'));
    }

    public function test_stat_works_for_a_name_with_a_space(): void
    {
        $this->getJson('/api/projects/alice/files/stat?path=' . rawurlencode('with space.txt'))
            ->assertOk()
            ->assertJsonPath('data.name', 'with space.txt')
            ->assertJsonPath('data.size', 6);
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
                $argv = array_slice((array) $cmd, 7);
                $process = new Process($argv);
                $process->run();

                return $process;
            }
        };
    }
}
