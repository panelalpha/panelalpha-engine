<?php

namespace Tests\Unit\System\Project;

use App\System;
use App\System\Project\HomeMerge;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A restore that keeps existing files runs while the account's PHP and cron
 * can still rewrite its home, so root only renames entries at the root-owned
 * top level and the account itself extracts and merges everything below it.
 */
class HomeMergeTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/pa-home-merge-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/alice', 0755, true);
        mkdir($this->base . '/outside', 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
        parent::tearDown();
    }

    public function test_root_renames_top_level_entries_and_the_account_does_the_rest(): void
    {
        $recorded = [];
        $system = $this->recordingSystem($recorded, "public_html\n.bashrc\n.restore-9\n", [
            '/home/alice/.restore-9/public_html',
            '/home/alice/public_html',
        ]);

        (new HomeMerge($system, '/home/alice', '1002', '1003', 60))
            ->merge('/var/tmp/panelalpha-backup/alice/9/home.tar.gz', '/home/alice/.restore-9');

        $asAccount = 'sudo setpriv --reuid 1002 --regid 1003 --clear-groups';
        $realDirectory = 'sudo bash -c [ -d "$1" ] && [ ! -L "$1" ] bash ';
        $this->assertSame([
            'sudo mkdir -- /home/alice/.restore-9',
            'sudo chown 1002:1003 -- /home/alice/.restore-9',
            'bash -c exec "${@:2}" < "$1" bash /var/tmp/panelalpha-backup/alice/9/home.tar.gz '
                . $asAccount . ' tar -xzpf - --no-same-owner -C /home/alice/.restore-9',
            'sudo ls -A /home/alice/.restore-9',
            $realDirectory . '/home/alice/.restore-9/public_html',
            $realDirectory . '/home/alice/public_html',
            $realDirectory . '/home/alice/.restore-9/.bashrc',
            $realDirectory . '/home/alice/.bashrc',
            'sudo rm -f -- /home/alice/.bashrc',
            'sudo mv -T -- /home/alice/.restore-9/.bashrc /home/alice/.bashrc',
            $asAccount . ' bash -c set -o pipefail; tar -C "$1" -cf - -- "${@:3}" | tar -C "$2" -xpf - '
                . '--no-same-owner --no-overwrite-dir bash /home/alice/.restore-9 /home/alice public_html',
        ], array_map(static fn (array $argv): string => implode(' ', $argv), $recorded));
    }

    public function test_archive_files_overwrite_and_every_other_file_stays(): void
    {
        $this->requiresGnuTar();
        $home = $this->home();
        $this->write($home . '/public_html/index.php', 'old index');
        $this->write($home . '/public_html/keep.php', 'kept');
        $this->write($home . '/.bashrc', 'old rc');
        $this->write($home . '/data/sub/old.txt', 'old data');
        $archive = $this->archive([
            'public_html/index.php' => 'new index',
            'public_html/added/a.txt' => 'added',
            '.bashrc' => 'new rc',
            'data/sub/new.txt' => 'new data',
            'fresh/f.txt' => 'fresh',
        ]);

        $this->merge($archive);

        $this->assertSame('new index', file_get_contents($home . '/public_html/index.php'));
        $this->assertSame('kept', file_get_contents($home . '/public_html/keep.php'));
        $this->assertSame('added', file_get_contents($home . '/public_html/added/a.txt'));
        $this->assertSame('new rc', file_get_contents($home . '/.bashrc'));
        $this->assertSame('old data', file_get_contents($home . '/data/sub/old.txt'));
        $this->assertSame('new data', file_get_contents($home . '/data/sub/new.txt'));
        $this->assertSame('fresh', file_get_contents($home . '/fresh/f.txt'));
    }

    public function test_a_symlink_at_the_top_level_is_replaced_not_followed(): void
    {
        $this->requiresGnuTar();
        $home = $this->home();
        symlink($this->base . '/outside', $home . '/x');
        $archive = $this->archive(['x/evil' => 'payload']);

        $this->merge($archive);

        $this->assertSame([], $this->entries($this->base . '/outside'), 'the symlink target received nothing');
        $this->assertFalse(is_link($home . '/x'));
        $this->assertSame('payload', file_get_contents($home . '/x/evil'));
    }

    public function test_a_symlink_below_the_top_level_is_replaced_not_followed(): void
    {
        $this->requiresGnuTar();
        $home = $this->home();
        $this->write($home . '/public_html/keep.php', 'kept');
        symlink($this->base . '/outside', $home . '/public_html/x');
        $archive = $this->archive(['public_html/x/evil' => 'payload']);

        $this->merge($archive);

        $this->assertSame([], $this->entries($this->base . '/outside'), 'the symlink target received nothing');
        $this->assertFalse(is_link($home . '/public_html/x'));
        $this->assertSame('payload', file_get_contents($home . '/public_html/x/evil'));
        $this->assertSame('kept', file_get_contents($home . '/public_html/keep.php'));
    }

    public function test_a_directory_is_never_replaced_by_a_file_and_stays_whole(): void
    {
        $this->requiresGnuTar();
        $home = $this->home();
        $this->write($home . '/public_html/index.php', 'live');
        $archive = $this->archive(['public_html' => 'now a file']);

        try {
            $this->merge($archive);
            $this->fail('A file must not replace a live directory');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Failed to move restored 'public_html' into the home", $e->getMessage());
        }

        $this->assertSame('live', file_get_contents($home . '/public_html/index.php'));
    }

    private function merge(string $archive): void
    {
        (new HomeMerge($this->executingSystem(), $this->home(), (string) getmyuid(), (string) getmygid(), 60))
            ->merge($archive, $this->home() . '/.restore-9');
    }

    private function home(): string
    {
        return $this->base . '/alice';
    }

    private function write(string $path, string $contents): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $contents);
    }

    /**
     * @param array<string, string> $files
     */
    private function archive(array $files): string
    {
        $src = $this->base . '/src-' . bin2hex(random_bytes(4));
        mkdir($src, 0755, true);
        foreach ($files as $relative => $contents) {
            $this->write($src . '/' . $relative, $contents);
        }

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

    private function requiresGnuTar(): void
    {
        $tar = new Process(['tar', '--version']);
        $tar->run();
        $bash = new Process(['bash', '-c', 'true']);
        $bash->run();
        if (!str_contains($tar->getOutput(), 'GNU tar') || !$bash->isSuccessful()) {
            $this->markTestSkipped('The merge relies on GNU tar and bash, as the engine container has them.');
        }
    }

    /**
     * @param list<list<string>> $recorded
     * @param list<string> $directories
     */
    private function recordingSystem(array &$recorded, string $listing, array $directories): System
    {
        return new class ($recorded, $listing, $directories) extends System {
            /** @var list<list<string>> */
            private array $recorded;

            /**
             * @param list<list<string>> $recorded
             * @param list<string> $directories
             */
            public function __construct(array &$recorded, private string $listing, private array $directories)
            {
                $this->recorded = &$recorded;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
                $this->recorded[] = $argv;
                $isDirectoryCheck = ($argv[1] ?? null) === 'bash' && ($argv[2] ?? null) === '-c' && str_contains($argv[3] ?? '', '-L');
                $succeeds = !$isDirectoryCheck || in_array(end($argv), $this->directories, true);
                $output = ($argv[1] ?? null) === 'ls' ? $this->listing : '';

                $process = new Process(['sh', '-c', 'printf %s "$1"; exit "$2"', 'sh', $output, $succeeds ? '0' : '1']);
                $process->run();

                return $process;
            }
        };
    }

    /** Runs the merge as this test process: root's sudo and the account's setpriv are both dropped. */
    private function executingSystem(): System
    {
        return new class () extends System {
            public function __construct()
            {
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $process = new Process($this->withoutPrivilegeChanges(is_array($cmd) ? array_values($cmd) : [$cmd]));
                $process->setTimeout($timeout);
                $process->run();

                return $process;
            }

            /**
             * @param list<string> $argv
             * @return list<string>
             */
            private function withoutPrivilegeChanges(array $argv): array
            {
                $setpriv = array_search('setpriv', $argv, true);
                if ($setpriv !== false && $setpriv > 0 && $argv[$setpriv - 1] === 'sudo') {
                    array_splice($argv, $setpriv - 1, 7);
                }
                if (($argv[0] ?? null) === 'sudo') {
                    array_shift($argv);
                }

                return array_values($argv);
            }
        };
    }
}
