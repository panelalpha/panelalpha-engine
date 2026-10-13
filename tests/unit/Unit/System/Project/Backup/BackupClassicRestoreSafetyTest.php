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

        $this->assertSame(['rm', '-rf', '--', '/home/alice/.restore-9'], $recorded[0]);
        $this->assertSame(['rm', '-rf', '--', '/home/alice/.pre-restore-9'], $recorded[1]);
        $this->assertSame(['mkdir', '-p', '-m', '700', '/home/alice/.restore-9'], $recorded[2]);
        $this->assertSame(
            ['tar', '-xzf', '/var/tmp/home.tar.gz', '-C', '/home/alice/.restore-9'],
            $recorded[3],
        );
        $this->assertCount(4, $recorded);
    }

    public function test_a_merge_restore_never_runs_tar_as_root(): void
    {
        $recorded = [];
        $ran = [];
        $backup = $this->backup($this->recordingSystem($recorded, $ran));

        $backup->exposeMergeIntoHome('/var/tmp/home.tar.gz', '.restore-9');

        $this->assertSame([], $recorded, 'nothing runs in the host namespaces');
        foreach ($ran as $argv) {
            $this->assertFalse(
                in_array('tar', $argv, true) && !in_array('setpriv', $argv, true),
                'tar runs only as the account: ' . implode(' ', $argv),
            );
        }
        $this->assertContains(
            ['sudo', 'chown', self::ACCOUNT_UID . ':' . self::ACCOUNT_GID, '--', '/home/alice/.restore-9'],
            $ran,
        );
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
     * @param list<list<string>> $recorded commands run in the host namespaces
     * @param list<list<string>> $ran commands run in the engine container
     */
    private function recordingSystem(array &$recorded, array &$ran = []): System
    {
        return new class ($recorded, $ran) extends System {
            /** @var list<list<string>> */
            private array $recorded;

            /** @var list<list<string>> */
            private array $ran;

            /**
             * @param list<list<string>> $recorded
             * @param list<list<string>> $ran
             */
            public function __construct(array &$recorded, array &$ran)
            {
                $this->recorded = &$recorded;
                $this->ran = &$ran;
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->recorded[] = is_array($cmd) ? array_values($cmd) : [$cmd];

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
                $this->ran[] = $argv;
                $output = ($argv[1] ?? null) === 'ls' ? "public_html\n" : '';

                $process = new Process(['printf', '%s', $output]);
                $process->run();

                return $process;
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

    public function exposeMergeIntoHome(string $archivePath, string $incomingDir): void
    {
        $this->mergeIntoHome($archivePath, $incomingDir);
    }

    public function exposeRemoveHomeEntry(string $entry): void
    {
        $this->removeHomeEntry($entry);
    }
}
