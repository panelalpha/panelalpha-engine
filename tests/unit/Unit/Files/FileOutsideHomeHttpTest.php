<?php

namespace Tests\Unit\Files;

use App\Http\Middleware\Authenticate;
use App\Models\User;
use App\System;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A path through a link out of the home is refused the same way by every file endpoint: a 422 whose problems[]
 * carry code outside_home and the confinement script's own sentence, never its exit code.
 */
class FileOutsideHomeHttpTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir().'/pa-file-outside-'.bin2hex(random_bytes(4));
        mkdir($this->tmpRoot.'/home/alice', 0777, true);
        mkdir($this->tmpRoot.'/home/bob', 0777, true);
        file_put_contents($this->tmpRoot.'/home/bob/theirs.txt', 'bob');
        symlink($this->tmpRoot.'/home/bob', $this->tmpRoot.'/home/alice/bob');

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        (require base_path('database/migrations/2014_10_12_000000_create_users_table.php'))->up();

        $user = new User;
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
        exec('rm -rf '.escapeshellarg($this->tmpRoot));
        parent::tearDown();
    }

    public function test_listing_a_link_out_of_the_home_is_refused(): void
    {
        $this->assertRefusal($this->getJson('/api/projects/alice/files/list?path=bob'), 'bob');
    }

    public function test_statting_a_file_through_a_link_out_of_the_home_is_refused(): void
    {
        $this->assertRefusal($this->getJson('/api/projects/alice/files/stat?path=bob/theirs.txt'), 'bob/theirs.txt');
    }

    public function test_downloading_a_file_through_a_link_out_of_the_home_is_refused(): void
    {
        $this->assertRefusal($this->getJson('/api/projects/alice/files/download?path=bob/theirs.txt'), 'bob/theirs.txt');
    }

    private function assertRefusal(TestResponse $response, string $path): void
    {
        $message = "Refusing {$this->tmpRoot}/home/alice/{$path}: it resolves outside the project home";

        $response->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'path')
            ->assertJsonPath('problems.0.code', 'outside_home')
            ->assertJsonPath('message', $message);
    }

    /** The broker's own scripts, run as this user without the broker. */
    private function fakeSystem(string $root): System
    {
        return new class($root) extends System
        {
            public function __construct(private string $root) {}

            public function engineDirPath(): string
            {
                return $this->root.'/engine';
            }

            public function homesDirPath(): string
            {
                return $this->root.'/home';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $process = new Process(LocalRoot::plain(is_array($cmd) ? $cmd : [$cmd]));
                $process->run();

                return $process;
            }
        };
    }
}
