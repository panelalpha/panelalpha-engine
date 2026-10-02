<?php

namespace Tests\Unit\Files;

use App\Http\Middleware\Authenticate;
use App\Lib\Helpers\FileStreamWrapper;
use App\Models\User;
use App\System;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * `sudo test -f` follows a symlink, so one pointing out of the home passed the
 * pre-check; the stream wrapper then refused it and Symfony's
 * FileNotFoundException reached the client as a 500.
 */
class FileDownloadHttpTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/pa-file-download-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/home/alice/project', 0777, true);
        mkdir($this->tmpRoot . '/etc', 0777, true);
        file_put_contents($this->tmpRoot . '/etc/shadow', 'root:*:');
        symlink($this->tmpRoot . '/etc/shadow', $this->tmpRoot . '/home/alice/project/shadowlink');

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
        FileStreamWrapper::confineTo(null);
        Schema::dropIfExists('users');
        exec('rm -rf ' . escapeshellarg($this->tmpRoot));
        parent::tearDown();
    }

    public function test_a_symlink_out_of_the_home_is_a_404_not_a_500(): void
    {
        $response = $this->getJson('/api/projects/alice/files/download?path=project/shadowlink');

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Invalid path']);
    }

    private function fakeSystem(string $root): System
    {
        // `sudo test -f` is answered with a real test -f, which follows symlinks too.
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

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? $cmd : [$cmd];
                $code = ($argv[0] ?? null) === 'sudo' && ($argv[1] ?? null) === 'test' && ($argv[2] ?? null) === '-f'
                    ? (is_file((string) $argv[3]) ? 0 : 1)
                    : 0;

                return new class ($code) extends Process {
                    public function __construct(private int $code)
                    {
                        parent::__construct(['true']);
                    }

                    public function getExitCode(): ?int
                    {
                        return $this->code;
                    }
                };
            }
        };
    }
}
