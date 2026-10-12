<?php

namespace Tests\Unit\System\Project\Backup;

use App\Models\Backup as BackupRecord;
use App\Models\User;
use App\System;
use App\System\Project as Project;
use App\System\Project\Backup;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The project home root is root-owned (Project::createHomeDirectory chowns it
 * root:root 0755), so the account cannot plant entries there and the classic
 * restore performs the home-root work as root, on exact paths, without
 * following a symlink.
 */
class BackupClassicRestoreSafetyTest extends TestCase
{
    private const ACCOUNT_UID = 65534;
    private const ACCOUNT_GID = 65534;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/pa-g3-safety-' . bin2hex(random_bytes(4));
        mkdir($this->base, 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
        parent::tearDown();
    }

    public function test_the_scratch_removal_and_extraction_run_as_root(): void
    {
        $recorded = [];
        $backup = $this->backup($this->recordingSystem($recorded));

        $backup->exposeRemoveHomeEntry('.restore-9');
        $backup->exposeRemoveHomeEntry('.pre-restore-9');
        $backup->exposeExtractHomeArchive('/var/tmp/home.tar.gz', '/home/alice/.restore-9');
        $backup->exposeExtractOverHome('/var/tmp/home.tar.gz');

        $this->assertSame(['rm', '-rf', '--', '/home/alice/.restore-9'], $recorded[0]);
        $this->assertSame(['rm', '-rf', '--', '/home/alice/.pre-restore-9'], $recorded[1]);
        $this->assertSame(['mkdir', '-p', '/home/alice/.restore-9'], $recorded[2]);
        $this->assertSame(
            ['tar', '-xzf', '/var/tmp/home.tar.gz', '-C', '/home/alice/.restore-9'],
            $recorded[3],
        );
        $this->assertSame(['tar', '-xzf', '/var/tmp/home.tar.gz', '-C', '/home/alice'], $recorded[4]);
    }

    public function test_a_leftover_restore_symlink_is_removed_before_extraction(): void
    {
        $this->requiresRoot();

        $home = $this->home();
        $outside = $this->outside();
        symlink($outside, $home . '/.restore-9');
        $archive = $this->archiveWith('x', 'restored');

        $backup = $this->backup($this->executingSystem());

        $backup->exposeRemoveHomeEntry('.restore-9');
        $this->assertFalse(is_link($home . '/.restore-9'), 'the leftover symlink is gone');
        $this->assertFalse(file_exists($home . '/.restore-9'), 'nothing is left at the scratch path');

        $backup->exposeExtractHomeArchive($archive, $home . '/.restore-9');

        $this->assertSame([], $this->entries($outside), 'the symlink target received nothing');
        $this->assertFileExists($home . '/.restore-9/x');
        $this->assertSame('restored', file_get_contents($home . '/.restore-9/x'));
    }

    public function test_extract_over_home_does_not_write_through_a_symlinked_directory(): void
    {
        $this->requiresRoot();

        $home = $this->home();
        $outside = $this->outside();
        symlink($outside, $home . '/x');
        $archive = $this->archiveWith('x/evil', 'payload');

        $backup = $this->backup($this->executingSystem());

        $backup->exposeExtractOverHome($archive);

        $this->assertSame([], $this->entries($outside), 'the symlink target received nothing');
        $this->assertFalse(is_link($home . '/x'), 'the symlink was replaced by the archived directory');
        $this->assertSame('payload', file_get_contents($home . '/x/evil'));
    }

    private function requiresRoot(): void
    {
        if (!function_exists('posix_getuid') || posix_getuid() !== 0) {
            $this->markTestSkipped('The classic restore runs as root; the test process must too.');
        }
    }

    private function home(): string
    {
        $home = $this->base . '/alice';
        if (!is_dir($home)) {
            mkdir($home, 0755, true);
        }

        return $home;
    }

    /** A root-owned directory outside the home: a write through the symlink would land here. */
    private function outside(): string
    {
        $outside = $this->base . '/outside';
        if (!is_dir($outside)) {
            mkdir($outside, 0755, true);
        }

        return $outside;
    }

    private function archiveWith(string $relative, string $contents): string
    {
        $src = $this->base . '/src-' . bin2hex(random_bytes(4));
        $path = $src . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $contents);

        $archive = $this->base . '/archive-' . bin2hex(random_bytes(4)) . '.tar.gz';
        (new Process(['tar', '-C', $src, '-czf', $archive, '.']))->mustRun();

        return $archive;
    }

    /**
     * @return list<string>
     */
    private function entries(string $dir): array
    {
        $entries = scandir($dir);

        return $entries === false ? [] : array_values(array_diff($entries, ['.', '..']));
    }

    private function backup(System $system): ExposedBackup
    {
        $user = new User();
        $user->id = 7;
        $user->username = 'alice';
        $user->details = ['template' => 'default', 'UID' => self::ACCOUNT_UID, 'GID' => self::ACCOUNT_GID];

        $record = new BackupRecord();
        $record->user_id = 7;
        $record->username = 'alice';
        $record->container_id = 1;

        return new ExposedBackup(new Project($system, $user), $record);
    }

    /**
     * @param list<list<string>> $recorded
     */
    private function recordingSystem(array &$recorded): System
    {
        return new class ($recorded) extends System {
            /** @var list<list<string>> */
            private array $recorded;

            /** @param list<list<string>> $recorded */
            public function __construct(array &$recorded)
            {
                $this->recorded = &$recorded;
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->recorded[] = is_array($cmd) ? array_values($cmd) : [$cmd];

                return '';
            }
        };
    }

    /** Runs the engine's host commands as root in this container (GNU tar there too). */
    private function executingSystem(): System
    {
        return new class ($this->base) extends System {
            public function __construct(private string $base)
            {
            }

            public function homesDirPath(): string
            {
                return $this->base;
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
                $process = new Process($argv);
                $process->setTimeout($timeout);
                $process->run();
                if (!$process->isSuccessful()) {
                    throw new \RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()));
                }

                return $process->getOutput();
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
                if (($argv[0] ?? null) === 'sudo') {
                    $argv = array_slice($argv, 1);
                }

                $process = new Process($argv);
                $process->setTimeout($timeout);
                $process->run();

                return $process;
            }
        };
    }
}

class ExposedBackup extends Backup
{
    public function exposeExtractHomeArchive(string $archivePath, string $targetDir): void
    {
        $this->extractHomeArchive($archivePath, $targetDir);
    }

    public function exposeExtractOverHome(string $archivePath): void
    {
        $this->extractOverHome($archivePath);
    }

    public function exposeRemoveHomeEntry(string $entry): void
    {
        $this->removeHomeEntry($entry);
    }
}
