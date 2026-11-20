<?php

namespace Tests\Integration\Files;

use App\Http\Middleware\Authenticate;
use App\Models\User;
use App\System;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
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
            ->assertExactJson(['success' => true]);

        $this->assertSame('new', file_get_contents($this->tmpRoot . '/home/alice/public/index.php'));
        $this->assertSame([], glob($this->tmpRoot . '/home/.panelalpha-unzip-stage/*') ?: []);
    }

    /** As PowerShell's Compress-Archive writes it: made on MS-DOS, `\` between the folders. */
    public function test_a_zip_made_on_windows_is_unpacked_into_its_folders_and_answered_with_unzips_warning(): void
    {
        if ((new \Symfony\Component\Process\ExecutableFinder())->find('unzip') === null) {
            $this->markTestSkipped('unzip is not installed');
        }
        $zip = new \ZipArchive();
        $zip->open($this->tmpRoot . '/home/alice/site.zip', \ZipArchive::CREATE);
        $zip->addFromString('inc\\hello.php', '<?php echo 1;');
        $zip->setExternalAttributesName('inc\\hello.php', \ZipArchive::OPSYS_DOS, 0x20);
        $zip->close();

        $this->postJson('/api/projects/alice/files/unzip', ['zip_path' => 'site.zip', 'path' => 'public'])
            ->assertOk()
            ->assertExactJson(['success' => true, 'warnings' => ['warning: site.zip appears to use backslashes as path separators']]);

        $this->assertSame('<?php echo 1;', file_get_contents($this->tmpRoot . '/home/alice/public/inc/hello.php'));
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

    public function test_an_archive_that_is_not_there_is_a_422_not_found_on_zip_path(): void
    {
        mkdir($this->tmpRoot . '/home/alice/dir');

        $this->postJson('/api/projects/alice/files/unzip', ['zip_path' => 'dir/win.zip', 'path' => 'public'])
            ->assertStatus(422)
            ->assertJsonPath('errors.zip_path.0', 'Archive not found: /dir/win.zip')
            ->assertJsonPath('problems.0.field', 'zip_path')
            ->assertJsonPath('problems.0.code', 'not_found');
    }

    public function test_an_archive_the_checks_refuse_is_a_422_on_zip_path(): void
    {
        symlink('/etc', $this->tmpRoot . '/home/alice/src/etc');
        exec('tar -czf ' . escapeshellarg($this->tmpRoot . '/home/alice/links.tar.gz')
            . ' -C ' . escapeshellarg($this->tmpRoot . '/home/alice/src') . ' etc');

        $this->postJson('/api/projects/alice/files/unzip', ['zip_path' => 'links.tar.gz', 'path' => 'public'])
            ->assertStatus(422)
            ->assertJsonPath('errors.zip_path.0', 'Archive contains a symbolic link, which could redirect extraction outside the project directory.')
            ->assertJsonPath('problems.0.field', 'zip_path');

        $this->assertFalse(is_link($this->tmpRoot . '/home/alice/public/etc'));
    }

    public function test_an_archive_that_cannot_be_unpacked_is_a_422_on_zip_path(): void
    {
        file_put_contents($this->tmpRoot . '/home/alice/notes.gz', 'not gzip data');

        $response = $this->postJson('/api/projects/alice/files/unzip', ['zip_path' => 'notes.gz', 'path' => 'public'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'zip_path');

        $this->assertStringStartsWith('Could not unpack notes.gz: ', (string) $response->json('errors.zip_path.0'));
        $this->assertStringNotContainsString('unzip-stage', (string) $response->json('errors.zip_path.0'));
        $this->assertFileDoesNotExist($this->tmpRoot . '/home/alice/public/notes');
    }

    public function test_a_destination_the_project_cannot_write_to_is_a_422_not_writable_on_path(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes to a 0555 directory.');
        }
        mkdir($this->tmpRoot . '/home/alice/locked', 0555);

        try {
            $this->postJson('/api/projects/alice/files/unzip', ['zip_path' => 'site.tar.gz', 'path' => 'locked'])
                ->assertStatus(422)
                ->assertJsonPath('problems.0.field', 'path')
                ->assertJsonPath('problems.0.code', 'not_writable');
        } finally {
            chmod($this->tmpRoot . '/home/alice/locked', 0755);
        }
        $this->assertFileDoesNotExist($this->tmpRoot . '/home/alice/locked/index.php');
    }

    public function test_a_missing_destination_below_a_folder_the_project_cannot_write_to_is_a_422_not_writable_on_path(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes to a 0555 directory.');
        }
        mkdir($this->tmpRoot . '/home/alice/locked', 0555);

        try {
            $this->postJson('/api/projects/alice/files/unzip', ['zip_path' => 'site.tar.gz', 'path' => 'locked/new'])
                ->assertStatus(422)
                ->assertJsonPath('problems.0.field', 'path')
                ->assertJsonPath('problems.0.code', 'not_writable')
                ->assertJsonPath('errors.path.0', "/locked is not a folder the project's account can write to: choose a folder inside the project.");
        } finally {
            chmod($this->tmpRoot . '/home/alice/locked', 0755);
        }
    }

    public function test_an_archive_through_a_link_out_of_the_home_is_a_422_outside_home_on_zip_path(): void
    {
        mkdir($this->tmpRoot . '/elsewhere');
        copy($this->tmpRoot . '/home/alice/site.tar.gz', $this->tmpRoot . '/elsewhere/site.tar.gz');
        symlink($this->tmpRoot . '/elsewhere', $this->tmpRoot . '/home/alice/link');

        $this->postJson('/api/projects/alice/files/unzip', ['zip_path' => 'link/site.tar.gz', 'path' => 'public'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'zip_path')
            ->assertJsonPath('problems.0.code', 'outside_home')
            ->assertJsonPath('errors.zip_path.0', 'Refusing ' . $this->tmpRoot . '/home/alice/link/site.tar.gz: it resolves outside the project home');
    }

    public function test_a_destination_through_a_link_out_of_the_home_is_a_422_outside_home_on_path(): void
    {
        mkdir($this->tmpRoot . '/elsewhere');
        symlink($this->tmpRoot . '/elsewhere', $this->tmpRoot . '/home/alice/link');

        $this->postJson('/api/projects/alice/files/unzip', ['zip_path' => 'site.tar.gz', 'path' => 'link/public'])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'path')
            ->assertJsonPath('problems.0.code', 'outside_home');

        $this->assertSame([], array_values(array_diff(scandir($this->tmpRoot . '/elsewhere'), ['.', '..'])));
    }

    public function test_a_format_unzip_does_not_read_is_a_422_on_zip_path(): void
    {
        file_put_contents($this->tmpRoot . '/home/alice/site.rar', 'Rar!');

        $this->postJson('/api/projects/alice/files/unzip', ['zip_path' => 'site.rar', 'path' => 'public'])
            ->assertStatus(422)
            ->assertJsonPath('errors.zip_path.0', 'Unsupported archive format, expected .zip, .tar.gz, .tgz or .gz')
            ->assertJsonPath('problems.0.field', 'zip_path');
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

            /** Runs as this process, root's chown a no-op. */
            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = LocalRoot::plain((array) $cmd);
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
