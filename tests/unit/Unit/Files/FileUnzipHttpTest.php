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
 * An archive is unpacked beside the homes and renamed into the destination, so an entry the
 * destination already holds is a refusal the caller can act on, not something replaced.
 */
class FileUnzipHttpTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/pa-file-unzip-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/home/alice/src', 0777, true);
        file_put_contents($this->tmpRoot . '/home/alice/src/index.php', 'new');
        exec('tar -czf ' . escapeshellarg($this->tmpRoot . '/home/alice/site.tar.gz')
            . ' -C ' . escapeshellarg($this->tmpRoot . '/home/alice/src') . ' index.php', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('tar is required');
        }

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
        exec('rm -rf ' . escapeshellarg($this->tmpRoot));
        parent::tearDown();
    }

    public function test_an_archive_is_unpacked_into_the_destination(): void
    {
        mkdir($this->tmpRoot . '/home/alice/public');

        $this->postJson('/api/projects/alice/files/unzip', ['zip_path' => 'site.tar.gz', 'path' => 'public'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('new', file_get_contents($this->tmpRoot . '/home/alice/public/index.php'));
        $this->assertSame([], glob($this->tmpRoot . '/home/.panelalpha-unzip-stage/*') ?: []);
    }

    public function test_an_entry_already_in_the_destination_is_a_409_and_is_kept(): void
    {
        mkdir($this->tmpRoot . '/home/alice/public');
        file_put_contents($this->tmpRoot . '/home/alice/public/index.php', 'mine');

        $this->postJson('/api/projects/alice/files/unzip', ['zip_path' => 'site.tar.gz', 'path' => 'public'])
            ->assertStatus(409)
            ->assertJsonPath('problems.0.field', 'path')
            ->assertJsonPath('problems.0.code', 'destination_exists');

        $this->assertSame('mine', file_get_contents($this->tmpRoot . '/home/alice/public/index.php'));
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

            /** Runs as this process: sudo and the setpriv prefix dropped, root's chown a no-op. */
            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = (array) $cmd;
                $argv = ($argv[1] ?? '') === 'setpriv' ? array_slice($argv, 7) : array_slice($argv, 1);
                if (($argv[0] ?? '') === 'chown') {
                    $argv = ['true'];
                }
                $process = new Process($argv);
                $process->run();

                return $process;
            }
        };
    }
}
