<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Filesystem;
use App\System\Project;
use App\System\Project\Ftp;
use App\System\Services\PureFtpd;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProjectFtpTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-project-ftp-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/home/alice/public_html', 0777, true);
        file_put_contents($this->tmpRoot . '/docker-compose.yml', "services: {}\n");
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_project_ftp_returns_collaborator(): void
    {
        $system = $this->recordingSystem();
        $project = new Project($system, $this->userModel('alice', 1001, 1001));

        $this->assertInstanceOf(Ftp::class, $project->ftp());
    }

    public function test_create_runs_useradd_with_uid_and_container_path(): void
    {
        $system = $this->recordingSystem();
        $project = new Project($system, $this->userModel('alice', 1001, 1001));

        $project->ftp()->create('ftp@example.com', 'secret', '/public_html', 256);

        $this->assertGreaterThanOrEqual(2, count($system->execJournal));
        $addCmd = $system->execJournal[0];
        $this->assertStringContainsString('/bin/sh -c', $addCmd);
        $this->assertStringNotContainsString('secret', $addCmd, 'the password is on a command line');
        $this->assertSame(['PA_FTP_PASSWORD' => 'secret'], $system->envJournal[0]);
        $this->assertStringContainsString('pure-pw useradd', $addCmd);
        $this->assertStringContainsString('1001', $addCmd);
        $this->assertStringContainsString('/home/ftpuser/alice/public_html', $addCmd);
        $this->assertStringContainsString('256', $addCmd);
        $this->assertStringContainsString('pure-pw mkdb', $system->execJournal[1]);
    }

    public function test_create_rejects_missing_directory(): void
    {
        $system = $this->recordingSystem();
        $project = new Project($system, $this->userModel('alice', 1001, 1001));

        $this->expectException(ValidationException::class);
        $project->ftp()->create('ftp@example.com', 'secret', '/missing', null);
    }

    public function test_update_changes_password_quota_and_mkdb(): void
    {
        $system = $this->recordingSystem();
        $project = new Project($system, $this->userModel('alice', 1001, 1001));

        $project->ftp()->update('ftp@example.com', 'newpass', 128);

        $this->assertCount(3, $system->execJournal);
        $this->assertStringContainsString('pure-pw passwd', $system->execJournal[0]);
        $this->assertStringNotContainsString('newpass', $system->execJournal[0], 'the password is on a command line');
        $this->assertSame(['PA_FTP_PASSWORD' => 'newpass'], $system->envJournal[0]);
        $this->assertStringContainsString('pure-pw usermod ftp@example.com -N 128', $system->execJournal[1]);
        $this->assertStringContainsString('pure-pw mkdb', $system->execJournal[2]);
    }

    public function test_update_skips_passwd_when_password_null(): void
    {
        $system = $this->recordingSystem();
        $project = new Project($system, $this->userModel('alice', 1001, 1001));

        $project->ftp()->update('ftp@example.com', null, null);

        $this->assertCount(2, $system->execJournal);
        $this->assertStringContainsString('pure-pw usermod ftp@example.com -N', $system->execJournal[0]);
        $this->assertStringContainsString('pure-pw mkdb', $system->execJournal[1]);
    }

    public function test_delete_removes_existing_user_and_reloads(): void
    {
        $system = $this->recordingSystem(userExistsExitCode: 0);
        $project = new Project($system, $this->userModel('alice', 1001, 1001));

        $project->ftp()->delete('ftp@example.com');

        $joined = implode("\n", $system->execJournal);
        $this->assertStringContainsString('pure-pw userdel ftp@example.com -m', $joined);
        $this->assertStringContainsString('touch /etc/pureftpd/pureftpd.passwd', $joined);
        $this->assertStringContainsString('pure-pw mkdb', $joined);
    }

    public function test_delete_skips_userdel_when_missing_but_still_reloads(): void
    {
        $system = $this->recordingSystem(userExistsExitCode: 16);
        $project = new Project($system, $this->userModel('alice', 1001, 1001));

        $project->ftp()->delete('missing@example.com');

        $joined = implode("\n", $system->execJournal);
        $this->assertStringNotContainsString('userdel', $joined);
        $this->assertStringContainsString('touch /etc/pureftpd/pureftpd.passwd', $joined);
        $this->assertStringContainsString('pure-pw mkdb', $joined);
    }

    public function test_delete_many_reloads_once(): void
    {
        $system = $this->recordingSystem(userExistsExitCode: 0);
        $project = new Project($system, $this->userModel('alice', 1001, 1001));

        $project->ftp()->deleteMany(['a@example.com', 'b@example.com']);

        $userdelCount = 0;
        $touchCount = 0;
        foreach ($system->execJournal as $cmd) {
            if (str_contains($cmd, 'userdel')) {
                $userdelCount++;
            }
            if (str_contains($cmd, 'touch /etc/pureftpd/pureftpd.passwd')) {
                $touchCount++;
            }
        }
        $this->assertSame(2, $userdelCount);
        $this->assertSame(1, $touchCount);
    }

    public function test_disk_usage_returns_du_megabytes(): void
    {
        $system = $this->recordingSystem(duOutput: "42\t/home/alice/public_html\n");
        $project = new Project($system, $this->userModel('alice', 1001, 1001));

        $this->assertSame('42', $project->ftp()->diskUsage('/public_html'));
    }

    public function test_disk_usage_creates_missing_directory_and_returns_zero(): void
    {
        $system = $this->recordingSystem();
        $project = new Project($system, $this->userModel('alice', 1001, 1001));

        $this->assertSame('0', $project->ftp()->diskUsage('/newdir'));
        $this->assertNotEmpty($system->processJournal);
        $this->assertStringContainsString('mkdir -p', $system->processJournal[0]);
    }

    private function userModel(string $username, int $uid, int $gid): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = $username;
        $model->setDetails([
            'UID' => $uid,
            'GID' => $gid,
        ]);

        return $model;
    }

    /**
     * @return System&object{execJournal: list<string>, processJournal: list<string>}
     */
    private function recordingSystem(int $userExistsExitCode = 0, string $duOutput = "0\tpath\n"): System
    {
        return new class ($this->tmpRoot, $userExistsExitCode, $duOutput) extends System {
            /** @var list<string> */
            public array $execJournal = [];
            /** @var list<array<string, string>> */
            public array $envJournal = [];

            /** @var list<string> */
            public array $processJournal = [];

            public function __construct(
                private string $engineRoot,
                private int $userExistsExitCode,
                private string $duOutput,
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

            public function ftp(): PureFtpd
            {
                return new PureFtpd($this);
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                $this->execJournal[] = $line;
                $this->envJournal[] = $env;
                if (str_contains($line, 'du -shm')) {
                    return $this->duOutput;
                }

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                $this->processJournal[] = $line;

                if (str_contains($line, 'pure-pw show')) {
                    return new class ($this->userExistsExitCode) extends Process {
                        public function __construct(private int $fakeExitCode)
                        {
                            parent::__construct(['true']);
                        }

                        public function run(?callable $callback = null, array $env = []): int
                        {
                            return $this->fakeExitCode;
                        }

                        public function getExitCode(): ?int
                        {
                            return $this->fakeExitCode;
                        }

                        public function getErrorOutput(): string
                        {
                            return '';
                        }
                    };
                }

                return Process::fromShellCommandline('true');
            }

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function directoryExists(string $path): bool
                    {
                        return is_dir($path);
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
