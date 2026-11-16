<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Filesystem;
use App\System\Privileged;
use App\System\Project;
use App\System\Project\Ftp;
use App\System\Services\PureFtpd;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
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
        putenv('PA_ROOT_LOCAL_ROOTS=' . json_encode(['engine' => $this->tmpRoot, 'homes' => $this->tmpRoot . '/home']));
    }

    protected function tearDown(): void
    {
        putenv('PA_ROOT_LOCAL_ROOTS');
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

        $this->assertGreaterThanOrEqual(2, count($system->requests));
        $this->assertSame([
            LocalRoot::CLIENT, '--env', 'PA_FTP_PASSWORD', 'compose.ftpAddUser', '--', $this->composeFile(),
            'ftp@example.com', '1001', '1001', '/home/ftpuser/alice/public_html', '256',
        ], $system->requests[0]);
        $this->assertNotContains('secret', $system->requests[0], 'the password is on a command line');
        $this->assertSame(['PA_FTP_PASSWORD' => 'secret'], $system->envJournal[0]);
        $this->assertSame(['compose.ftpAddUser', 'compose.ftpMakeDatabase'], $system->actions());
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

        $this->assertSame(['compose.ftpSetPassword', 'compose.ftpSetQuota', 'compose.ftpMakeDatabase'], $system->actions());
        $this->assertNotContains('newpass', $system->requests[0], 'the password is on a command line');
        $this->assertSame(['PA_FTP_PASSWORD' => 'newpass'], $system->envJournal[0]);
        $this->assertSame(
            [LocalRoot::CLIENT, 'compose.ftpSetQuota', '--', $this->composeFile(), 'ftp@example.com', '128'],
            $system->requests[1],
        );
    }

    public function test_update_skips_passwd_when_password_null(): void
    {
        $system = $this->recordingSystem();
        $project = new Project($system, $this->userModel('alice', 1001, 1001));

        $project->ftp()->update('ftp@example.com', null, null);

        $this->assertSame(['compose.ftpSetQuota', 'compose.ftpMakeDatabase'], $system->actions());
        $this->assertSame(
            [LocalRoot::CLIENT, 'compose.ftpSetQuota', '--', $this->composeFile(), 'ftp@example.com', ''],
            $system->requests[0],
        );
    }

    public function test_delete_removes_existing_user_and_reloads(): void
    {
        $system = $this->recordingSystem(userExistsExitCode: 0);
        $project = new Project($system, $this->userModel('alice', 1001, 1001));

        $project->ftp()->delete('ftp@example.com');

        $this->assertSame(
            ['compose.ftpShowUser', 'compose.ftpDeleteUser', 'compose.ftpTouchPasswordFile', 'compose.ftpMakeDatabase'],
            $system->actions(),
        );
        $this->assertSame(
            [LocalRoot::CLIENT, 'compose.ftpDeleteUser', '--', $this->composeFile(), 'ftp@example.com'],
            $system->requests[1],
        );
    }

    public function test_delete_skips_userdel_when_missing_but_still_reloads(): void
    {
        $system = $this->recordingSystem(userExistsExitCode: 16);
        $project = new Project($system, $this->userModel('alice', 1001, 1001));

        $project->ftp()->delete('missing@example.com');

        $this->assertSame(
            ['compose.ftpShowUser', 'compose.ftpTouchPasswordFile', 'compose.ftpMakeDatabase'],
            $system->actions(),
        );
    }

    public function test_delete_many_reloads_once(): void
    {
        $system = $this->recordingSystem(userExistsExitCode: 0);
        $project = new Project($system, $this->userModel('alice', 1001, 1001));

        $project->ftp()->deleteMany(['a@example.com', 'b@example.com']);

        $actions = $system->actions();

        $this->assertSame(2, count(array_keys($actions, 'compose.ftpDeleteUser', true)));
        $this->assertSame(1, count(array_keys($actions, 'compose.ftpTouchPasswordFile', true)));
        $this->assertSame(1, count(array_keys($actions, 'compose.ftpMakeDatabase', true)));
    }

    public function test_disk_usage_of_a_dind_account_leaves_out_the_inner_daemon_data(): void
    {
        $system = $this->recordingSystem(duOutput: "42\t/home/alice/public_html\n");
        $project = new Project($system, $this->userModel('alice', 1001, 1001, 'dind'));

        $this->assertSame('42', $project->ftp()->diskUsage('/public_html'));
        $home = $this->tmpRoot.'/home/alice';
        $this->assertContains(
            Privileged::accountFiles(1001, 1001)->diskUsageInMegabytes($home.'/public_html', $home.'/docker'),
            $system->requests
        );
        $this->assertContains('du -shm --exclude='.$home.'/docker -- '.$home.'/public_html', $system->execJournal);
    }

    public function test_disk_usage_of_a_php_hosting_account_counts_its_docker_directory(): void
    {
        $system = $this->recordingSystem(duOutput: "42\t/home/alice/public_html\n");
        $project = new Project($system, $this->userModel('alice', 1001, 1001, 'php'));

        $this->assertSame('42', $project->ftp()->diskUsage('/public_html'));
        $home = $this->tmpRoot.'/home/alice';
        $this->assertContains(
            Privileged::accountFiles(1001, 1001)->diskUsageInMegabytes($home.'/public_html'),
            $system->requests
        );
        $this->assertContains('du -shm -- '.$home.'/public_html', $system->execJournal);
    }

    public function test_disk_usage_creates_missing_directory_and_returns_zero(): void
    {
        $system = $this->recordingSystem();
        $project = new Project($system, $this->userModel('alice', 1001, 1001));

        $this->assertSame('0', $project->ftp()->diskUsage('/newdir/sub'));
        $home = $this->tmpRoot.'/home/alice';
        $this->assertSame([
            implode(' ', Privileged::scripts()->makeHomeEntry($home.'/newdir', '0755', '1001:1001')),
            implode(' ', Privileged::accountFiles(1001, 1001)->makeDirectories($home.'/newdir/sub')),
        ], $system->processJournal);
    }

    public function test_disk_usage_makes_nothing_for_the_home_itself(): void
    {
        $system = $this->recordingSystem();
        $project = new Project($system, $this->userModel('bob', 1002, 1002));

        $this->assertSame('0', $project->ftp()->diskUsage('/'));
        $this->assertSame([], $system->processJournal);
    }

    public function test_disk_usage_makes_the_first_named_entry_of_a_dotted_path(): void
    {
        $system = $this->recordingSystem();
        $project = new Project($system, $this->userModel('alice', 1001, 1001));

        $this->assertSame('0', $project->ftp()->diskUsage('/./newdir'));
        $home = $this->tmpRoot.'/home/alice';
        $this->assertSame(
            implode(' ', Privileged::scripts()->makeHomeEntry($home.'/newdir', '0755', '1001:1001')),
            $system->processJournal[0] ?? null,
        );
    }

    private function userModel(string $username, int $uid, int $gid, ?string $template = null): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = $username;
        $model->setDetails([
            'UID' => $uid,
            'GID' => $gid,
            'template' => $template,
        ]);

        return $model;
    }

    /**
     * @return System&object{execJournal: list<string>, processJournal: list<string>, requests: list<list<string>>}
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

            /** @var list<list<string>> */
            public array $requests = [];

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
                $this->requests[] = (array) $cmd;
                $line = is_array($cmd) ? implode(' ', LocalRoot::plain($cmd) ?: $cmd) : $cmd;
                $this->execJournal[] = $line;
                $this->envJournal[] = $env;
                if (str_contains($line, 'du -shm')) {
                    return $this->duOutput;
                }

                return '';
            }

            /** @return list<string> */
            public function actions(): array
            {
                return array_map(static fn (array $argv): ?string => LocalRoot::action($argv), $this->requests);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->requests[] = (array) $cmd;
                $this->processJournal[] = is_array($cmd) ? implode(' ', $cmd) : $cmd;

                if (is_array($cmd) && LocalRoot::action($cmd) === 'compose.ftpShowUser') {
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

    private function composeFile(): string
    {
        return $this->tmpRoot . '/docker-compose.yml';
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
