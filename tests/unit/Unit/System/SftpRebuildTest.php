<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Filesystem;
use App\System\Services\Sftp;
use PHPUnit\Framework\TestCase;

class SftpRebuildTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-sftp-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/config/sftp', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_sftp_returns_sftp_collaborator(): void
    {
        $system = $this->recordingSystem();
        $this->assertInstanceOf(Sftp::class, $system->sftp());
    }

    public function test_logins_conf_path_under_engine_config(): void
    {
        $system = $this->recordingSystem();
        $this->assertSame(
            $this->tmpRoot . '/config/sftp/logins.conf',
            $system->sftp()->loginsConfPath()
        );
    }

    public function test_write_logins_writes_file(): void
    {
        $system = $this->recordingSystem();
        $system->sftp()->writeLogins("alice_sftp:secret:1001:1001:/home/alice:\n");

        $confPath = $this->tmpRoot . '/config/sftp/logins.conf';
        $this->assertFileExists($confPath);
        $this->assertSame("alice_sftp:secret:1001:1001:/home/alice:\n", file_get_contents($confPath));
    }

    public function test_sync_logins_runs_sync_script_without_t_flag(): void
    {
        $system = $this->recordingSystem();
        $system->sftp()->syncLogins();

        $this->assertContains('sync-logins', $system->journal);
        $syncCmd = null;
        foreach ($system->processJournal as $cmd) {
            if (str_contains($cmd, 'sync-logins.sh')) {
                $syncCmd = $cmd;
                break;
            }
        }
        $this->assertNotNull($syncCmd);
        $this->assertStringContainsString('docker compose -f', $syncCmd);
        $this->assertStringContainsString('exec sftp bash /etc/sftp/sync-logins.sh', $syncCmd);
        $this->assertStringNotContainsString('exec -T sftp', $syncCmd);
    }

    public function test_apply_logins_writes_and_syncs(): void
    {
        $system = $this->recordingSystem();
        $system->sftp()->applyLogins("bob_sftp:pw:1002:1002:/home/bob:ssh-rsa BBBB\n");

        $confPath = $this->tmpRoot . '/config/sftp/logins.conf';
        $this->assertFileExists($confPath);
        $this->assertSame("bob_sftp:pw:1002:1002:/home/bob:ssh-rsa BBBB\n", file_get_contents($confPath));
        $this->assertContains('sync-logins', $system->journal);
    }

    private function recordingSystem(): System
    {
        return new class ($this->tmpRoot) extends System {
            /** @var list<string> */
            public array $journal = [];

            /** @var list<string> */
            public array $processJournal = [];

            public function __construct(
                private string $engineRoot,
            ) {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function composeFilePath(): string
            {
                return $this->engineRoot . '/docker-compose.yml';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): \Symfony\Component\Process\Process
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                $this->processJournal[] = $line;
                if (str_contains($line, 'sync-logins.sh')) {
                    $this->journal[] = 'sync-logins';
                }

                return \Symfony\Component\Process\Process::fromShellCommandline('true');
            }

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function filePutContents(
                        string $path,
                        string $contents,
                        ?string $chown = null,
                        ?string $chmod = null
                    ): void {
                        $dir = dirname($path);
                        if (!is_dir($dir)) {
                            mkdir($dir, 0777, true);
                        }
                        file_put_contents($path, $contents);
                    }
                };
            }
        };
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->removeTree($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }
}
