<?php

namespace Tests\Unit\Project;

use App\Lib\Project\ArchiveDrop;
use App\Models\User;
use App\System;
use App\System\Project as SystemProject;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * An archive put into a project for one deploy, written as the account and taken out again: under the name it was
 * given, so the deploy names the file the operator, the uploader or the URL gave.
 */
class ArchiveDropTest extends TestCase
{
    private string $root;

    private string $file;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-archive-drop-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/home/alice/.panelalpha/archives', 0777, true);
        mkdir($this->root . '/operator');
        $this->file = $this->root . '/operator/win.zip';
        file_put_contents($this->file, 'PK archive');
        $this->user = $this->user($this->fakeSystem($this->root));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_an_archive_keeps_the_name_it_was_given_in_a_folder_of_its_own(): void
    {
        $zipPath = (new ArchiveDrop())->put($this->user, $this->file, 'site v2.zip', ArchiveDrop::PROJECT_DIR);

        $this->assertMatchesRegularExpression('~^/project/\.deploy-archive-[a-z0-9]{12}/site v2\.zip$~', $zipPath);
        $this->assertSame('PK archive', file_get_contents($this->home() . $zipPath));
    }

    public function test_two_archives_of_one_name_do_not_meet(): void
    {
        $drop = new ArchiveDrop();

        $this->assertNotSame($drop->put($this->user, $this->file, 'win.zip'), $drop->put($this->user, $this->file, 'win.zip'));
    }

    public function test_a_control_character_or_a_backslash_in_the_name_becomes_an_underscore(): void
    {
        $zipPath = (new ArchiveDrop())->put($this->user, $this->file, "win\nold\\site.zip");

        $this->assertSame('win_old_site.zip', basename($zipPath));
        $this->assertFileExists($this->home() . $zipPath);
    }

    public function test_an_archive_goes_with_its_folder(): void
    {
        $drop = new ArchiveDrop();
        $zipPath = $drop->put($this->user, $this->file, 'win.zip');
        $kept = $drop->put($this->user, $this->file, 'win.zip');

        $drop->remove($this->user, $zipPath);

        $this->assertDirectoryDoesNotExist($this->home() . dirname($zipPath));
        $this->assertFileExists($this->home() . $kept);
    }

    public function test_an_archive_in_no_folder_of_its_own_goes_alone(): void
    {
        file_put_contents($this->home() . '/.panelalpha/archives/old.zip', 'PK');
        file_put_contents($this->home() . '/.panelalpha/archives/other.zip', 'PK');

        (new ArchiveDrop())->remove($this->user, '/.panelalpha/archives/old.zip');

        $this->assertFileDoesNotExist($this->home() . '/.panelalpha/archives/old.zip');
        $this->assertFileExists($this->home() . '/.panelalpha/archives/other.zip');
    }

    private function home(): string
    {
        return $this->root . '/home/alice';
    }

    private function user(System $system): User
    {
        $user = new class extends User {
            public System $system;

            public function project(?System $system = null): SystemProject
            {
                return parent::project($this->system);
            }
        };
        $user->system = $system;
        $user->username = 'alice';
        $user->setDetails(['template' => 'dind', 'UID' => 1001, 'GID' => 1001]);

        return $user;
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

            /** Runs as this process. */
            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $process = new Process(LocalRoot::plain((array) $cmd));
                $process->run();

                return $process;
            }
        };
    }
}
