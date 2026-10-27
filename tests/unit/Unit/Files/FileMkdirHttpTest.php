<?php

namespace Tests\Unit\Files;

use App\Http\Middleware\Authenticate;
use App\Models\User;
use App\System;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A classic project's home belongs to root: a folder made at its top is
 * refused on `path` with not_writable, as an upload there is, and never with
 * mkdir's own error text.
 */
class FileMkdirHttpTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes to a 0555 directory.');
        }

        $this->tmpRoot = sys_get_temp_dir() . '/pa-file-mkdir-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/home/alice/site', 0777, true);
        chmod($this->tmpRoot . '/home/alice', 0555);

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

        $this->app->instance(System::class, $this->fakeSystem($this->tmpRoot));
        $this->withoutMiddleware(Authenticate::class);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        if (isset($this->tmpRoot)) {
            chmod($this->tmpRoot . '/home/alice', 0755);
            exec('rm -rf ' . escapeshellarg($this->tmpRoot));
        }
        parent::tearDown();
    }

    public function test_a_folder_at_the_top_of_a_home_the_account_cannot_write_is_422_not_writable(): void
    {
        $response = $this->postJson('/api/projects/alice/files/mkdir', ['path' => '/home/aliceD/z2', 'parents' => true]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'path');
        $response->assertJsonPath('problems.0.code', 'not_writable');
        $response->assertJsonPath(
            'problems.0.message',
            "/ is not a folder the project's account can write to: choose a folder inside the project.",
        );
        $this->assertStringNotContainsString('mkdir:', (string) $response->getContent());
        $this->assertDirectoryDoesNotExist($this->tmpRoot . '/home/alice/home');
    }

    public function test_a_folder_inside_one_the_account_can_write_is_made(): void
    {
        $this->postJson('/api/projects/alice/files/mkdir', ['path' => 'site/blog'])->assertOk();

        $this->assertDirectoryExists($this->tmpRoot . '/home/alice/site/blog');
    }

    private function fakeSystem(string $root): System
    {
        return new class ($root) extends System {
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
                $process = new Process(array_slice((array) $cmd, 7));
                $process->run();

                return $process;
            }
        };
    }
}
