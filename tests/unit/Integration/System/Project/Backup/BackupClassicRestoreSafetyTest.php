<?php

namespace Tests\Integration\System\Project\Backup;

use App\Models\User;
use App\System;
use App\Support\JobDeadline;
use App\System\Project as Project;
use App\System\Project\Backup\HomeFiles;
use Symfony\Component\Process\Process;
use Tests\Support\HostCommand;
use Tests\Support\LocalRoot;
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
        $homeFiles = $this->homeFiles($this->recordingSystem($recorded));

        $homeFiles->removeEntry('.restore-9');
        $homeFiles->removeEntry('.pre-restore-9');
        $homeFiles->extract('/var/tmp/home.tar.gz', '/home/alice/.restore-9');

        $this->assertSame(['rm', '-rf', '--', '/home/alice/.restore-9'], $recorded[0]);
        $this->assertSame(['rm', '-rf', '--', '/home/alice/.pre-restore-9'], $recorded[1]);
        $this->assertSame(['mkdir', '-p', '-m', '700', '--', '/home/alice/.restore-9'], $recorded[2]);
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
        $homeFiles = $this->homeFiles($this->recordingSystem($recorded, $ran));

        $homeFiles->merge('/var/tmp/home.tar.gz', '.restore-9');

        $this->assertSame([], $recorded, 'nothing runs in the host namespaces');
        foreach ($ran as $argv) {
            $request = array_slice($argv, (int) array_search(LocalRoot::CLIENT, $argv, true));
            $this->assertFalse(
                in_array('tar', LocalRoot::plain($request), true) && !str_starts_with((string) LocalRoot::action($request), 'account.'),
                'tar runs only as the account: ' . implode(' ', $argv),
            );
        }
        $this->assertContains(
            [LocalRoot::CLIENT, 'files.changeOwner', '--', self::ACCOUNT_UID . ':' . self::ACCOUNT_GID, '/home/alice/.restore-9'],
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

        $homeFiles = $this->homeFiles($this->executingSystem());

        $homeFiles->removeEntry('.restore-9');
        $this->assertFalse(is_link($home . '/.restore-9'), 'the leftover symlink is gone');
        $this->assertFalse(file_exists($home . '/.restore-9'), 'nothing is left at the scratch path');

        $homeFiles->extract($archive, $home . '/.restore-9');

        $this->assertSame([], $this->entries($outside), 'the symlink target received nothing');
        $this->assertFileExists($home . '/.restore-9/x');
        $this->assertSame('restored', file_get_contents($home . '/.restore-9/x'));
    }

    /** A move aside that fails part way: the rollback moves back only what was moved, and the incoming tree stays whole. */
    public function test_a_swap_that_fails_part_way_rolls_back_only_what_it_moved(): void
    {
        $this->requiresRoot();
        $home = $this->home();
        $this->writeFile($home . '/.bashrc', 'original rc');
        $this->writeFile($home . '/project/app.php', 'original app');
        $this->writeFile($home . '/public_html/index.html', 'original site');
        $this->writeFile($home . '/.restore-9/.bashrc', 'restored rc');
        $this->writeFile($home . '/.restore-9/public_html/index.html', 'restored site');
        $homeFiles = $this->homeFiles($this->executingSystem(failMoveAsideOf: 'project'));
        $skip = ['.restore-9', '.pre-restore-9'];

        try {
            $homeFiles->swap('.restore-9', '.pre-restore-9', $skip);
            $this->fail('The move aside of project should fail.');
        } catch (\RuntimeException) {
        }
        $homeFiles->rollback('.restore-9', '.pre-restore-9');

        $this->assertSame('original rc', file_get_contents($home . '/.bashrc'));
        $this->assertSame('original app', file_get_contents($home . '/project/app.php'));
        $this->assertSame('original site', file_get_contents($home . '/public_html/index.html'));
        $this->assertSame(['.bashrc', 'public_html'], $this->entries($home . '/.restore-9'));
        $this->assertSame([], $this->entries($home . '/.pre-restore-9'));
    }

    /** What a failed rollback left aside is the home's own files: a retry refuses rather than delete them. */
    public function test_a_retry_refuses_an_aside_that_still_holds_the_home(): void
    {
        $this->requiresRoot();
        $home = $this->home();
        $this->writeFile($home . '/.pre-restore-9/project/app.php', 'original app');
        $homeFiles = $this->homeFiles($this->executingSystem());

        try {
            $homeFiles->removeEmptyAside('.pre-restore-9');
            $this->fail('A non-empty aside should be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('.pre-restore-9', $e->getMessage());
        }

        $this->assertSame('original app', file_get_contents($home . '/.pre-restore-9/project/app.php'));
    }

    public function test_a_retry_removes_the_empty_aside_a_rollback_left(): void
    {
        $this->requiresRoot();
        $home = $this->home();
        mkdir($home . '/.pre-restore-9');

        $this->homeFiles($this->executingSystem())->removeEmptyAside('.pre-restore-9');

        $this->assertFileDoesNotExist($home . '/.pre-restore-9');
    }

    private function writeFile(string $path, string $contents): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $contents);
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

    private function homeFiles(System $system): HomeFiles
    {
        $user = new User();
        $user->id = 7;
        $user->username = 'alice';
        $user->details = ['template' => 'default', 'UID' => self::ACCOUNT_UID, 'GID' => self::ACCOUNT_GID];

        return new HomeFiles(new Project($system, $user), JobDeadline::startingNow());
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

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = (array) $cmd;

                return array_slice(array_values($argv), 0, 2) === [LocalRoot::CLIENT, '--host']
                    ? $this->execOnHost($argv, $env, $timeout)
                    : parent::exec($cmd, $env, $timeout);
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = HostCommand::argv($cmd);
                $this->recorded[] = is_array($cmd) ? array_values($cmd) : [$cmd];

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
                $this->ran[] = $argv;
                $output = (LocalRoot::plain($argv)[0] ?? null) === 'ls' ? "public_html\n" : '';

                $process = new Process(['printf', '%s', $output]);
                $process->run();

                return $process;
            }
        };
    }

    /** Runs the engine's host commands as root in this container (GNU tar there too); $failMoveAsideOf's move aside fails. */
    private function executingSystem(?string $failMoveAsideOf = null): System
    {
        return new class ($this->base, $failMoveAsideOf) extends System {
            public function __construct(private string $base, private ?string $failMoveAsideOf)
            {
            }

            public function homesDirPath(): string
            {
                return $this->base;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = (array) $cmd;

                return array_slice(array_values($argv), 0, 2) === [LocalRoot::CLIENT, '--host']
                    ? $this->execOnHost($argv, $env, $timeout)
                    : parent::exec($cmd, $env, $timeout);
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = HostCommand::argv($cmd);
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
                $argv = is_array($cmd) ? LocalRoot::plain(array_values($cmd)) : [$cmd];
                if ($this->failMoveAsideOf !== null && ($argv[0] ?? null) === 'mv'
                    && str_ends_with($argv[1] ?? '', '/' . $this->failMoveAsideOf) && str_contains($argv[2] ?? '', '.pre-restore')) {
                    $argv = ['false'];
                }

                $process = new Process(array_map(static fn (string $word): string => $word === LocalRoot::CLIENT ? LocalRoot::client() : $word, $argv));
                $process->setTimeout($timeout);
                $process->run();

                return $process;
            }
        };
    }
}

