<?php

namespace Tests\Unit\System\Project;

use App\Models\SftpAccount;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Filesystem;
use App\System\Project;
use App\System\Project\Sftp;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class ProjectSftpTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-project-sftp-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/config/sftp', 0777, true);
        mkdir($this->tmpRoot . '/home', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_project_sftp_returns_collaborator(): void
    {
        $system = $this->recordingSystem();
        $project = new Project($system, $this->userModel('alice', [], 1001, 1001));

        $this->assertInstanceOf(Sftp::class, $project->sftp());
    }

    public function test_login_lines_for_password_key_and_both(): void
    {
        $system = $this->recordingSystem();
        $passwordOnly = $this->sftpAccount('alice_sftp1', 'password', 'secret', '');
        $keyOnly = $this->sftpAccount('alice_sftp2', 'public_key', '', 'ssh-ed25519 AAAA');
        $both = $this->sftpAccount('alice_sftp3', 'password,public_key', 'pw', 'ssh-rsa BBBB');
        $project = new Project(
            $system,
            $this->userModel('alice', [$passwordOnly, $keyOnly, $both], 1001, 1001),
        );

        $home = $this->tmpRoot . '/home/alice';
        $lines = $project->sftp()->loginLines();

        $this->assertStringContainsString("alice_sftp1:secret:1001:1001:{$home}:\n", $lines);
        $this->assertStringContainsString("alice_sftp2::1001:1001:{$home}:ssh-ed25519 AAAA\n", $lines);
        $this->assertStringContainsString("alice_sftp3:pw:1001:1001:{$home}:ssh-rsa BBBB\n", $lines);
    }

    public function test_rebuild_composes_lines_from_multiple_projects_and_applies(): void
    {
        $system = $this->recordingSystem();

        $passwordOnly = $this->sftpAccount('alice_sftp1', 'password', 'secret', '');
        $keyOnly = $this->sftpAccount('alice_sftp2', 'public_key', '', 'ssh-ed25519 AAAA');
        $both = $this->sftpAccount('bob_sftp', 'password,public_key', 'pw', 'ssh-rsa BBBB');

        $alice = $this->userModel('alice', [$passwordOnly, $keyOnly], 1001, 1001);
        $bob = $this->userModel('bob', [$both], 1002, 1002);

        $project = new Project($system, $alice);
        $sftp = new class ($project, collect([$alice, $bob])) extends Sftp {
            public function __construct(
                Project $project,
                private Collection $users,
            ) {
                parent::__construct($project);
            }

            protected function usersWithSftpAccounts(): Collection
            {
                return $this->users;
            }
        };

        $sftp->rebuild();

        $confPath = $this->tmpRoot . '/config/sftp/logins.conf';
        $this->assertFileExists($confPath);
        $contents = file_get_contents($confPath);
        $homeAlice = $this->tmpRoot . '/home/alice';
        $homeBob = $this->tmpRoot . '/home/bob';

        $this->assertStringContainsString(
            "alice_sftp1:secret:1001:1001:{$homeAlice}:\n",
            $contents
        );
        $this->assertStringContainsString(
            "alice_sftp2::1001:1001:{$homeAlice}:ssh-ed25519 AAAA\n",
            $contents
        );
        $this->assertStringContainsString(
            "bob_sftp:pw:1002:1002:{$homeBob}:ssh-rsa BBBB\n",
            $contents
        );

        $this->assertContains('sync-logins', $system->journal);
        $syncCmd = null;
        foreach ($system->processJournal as $cmd) {
            if (str_contains($cmd, 'sync-logins.sh')) {
                $syncCmd = $cmd;
                break;
            }
        }
        $this->assertNotNull($syncCmd);
        $this->assertStringContainsString('exec sftp bash /etc/sftp/sync-logins.sh', $syncCmd);
        $this->assertStringNotContainsString('exec -T sftp', $syncCmd);
    }

    /**
     * @param list<SftpAccount> $accounts
     */
    private function userModel(string $username, array $accounts, int $uid, int $gid): ModelsUser
    {
        $model = new class extends ModelsUser {
            /** @var list<SftpAccount> */
            public array $sftpAccs = [];

            public function getSftpAccounts(): array
            {
                return $this->sftpAccs;
            }
        };
        $model->username = $username;
        $model->sftpAccs = $accounts;
        $model->setDetails([
            'UID' => $uid,
            'GID' => $gid,
        ]);

        return $model;
    }

    private function sftpAccount(string $username, string $authMethod, string $password, string $publicKey): SftpAccount
    {
        $acc = new SftpAccount();
        $acc->username = $username;
        $acc->auth_method = $authMethod;
        $acc->password = $password;
        $acc->public_key = $publicKey;

        return $acc;
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

            public function homesDirPath(): string
            {
                return $this->engineRoot . '/home';
            }

            public function composeFilePath(): string
            {
                return $this->engineRoot . '/docker-compose.yml';
            }

            public function isUidExists(string $username): bool
            {
                return true;
            }

            public function execOnHost(string|array $cmd, array $env = []): string
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                if (preg_match('/id -u (.+)$/', $line, $m)) {
                    return $m[1] === 'alice' ? '1001' : '1002';
                }
                if (preg_match('/id -g (.+)$/', $line, $m)) {
                    return $m[1] === 'alice' ? '1001' : '1002';
                }

                return '';
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
