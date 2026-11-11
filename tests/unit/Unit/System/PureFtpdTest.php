<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Services\PureFtpd;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;

class PureFtpdTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-pureftpd-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot, 0777, true);
        file_put_contents($this->tmpRoot . '/docker-compose.yml', "services: {}\n");
        putenv('PA_ROOT_LOCAL_ROOTS=' . json_encode(['engine' => $this->tmpRoot]));
    }

    protected function tearDown(): void
    {
        putenv('PA_ROOT_LOCAL_ROOTS');
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_ftp_returns_pureftpd_collaborator(): void
    {
        $system = $this->recordingSystem();
        $this->assertInstanceOf(PureFtpd::class, $system->ftp());
    }

    public function test_make_database_asks_the_broker_for_the_ftp_service(): void
    {
        $system = $this->recordingSystem();
        $system->ftp()->makeDatabase();

        $this->assertSame(
            [LocalRoot::CLIENT, 'compose.ftpMakeDatabase', '--', $this->tmpRoot . '/docker-compose.yml'],
            $system->requestJournal[0],
        );
    }

    public function test_reload_touches_passwd_and_runs_mkdb(): void
    {
        $system = $this->recordingSystem();
        $system->ftp()->reload();

        $this->assertCount(2, $system->requestJournal);
        $this->assertSame(
            [LocalRoot::CLIENT, 'compose.ftpTouchPasswordFile', '--', $this->tmpRoot . '/docker-compose.yml'],
            $system->requestJournal[0],
        );
        $this->assertSame(
            [LocalRoot::CLIENT, 'compose.ftpMakeDatabase', '--', $this->tmpRoot . '/docker-compose.yml'],
            $system->requestJournal[1],
        );
    }

    public function test_user_exists_returns_false_on_exit_16(): void
    {
        $system = $this->recordingSystem(exitCode: 16);
        $this->assertFalse($system->ftp()->userExists('missing@example.com'));
    }

    public function test_user_exists_returns_true_on_exit_0(): void
    {
        $system = $this->recordingSystem(exitCode: 0);
        $this->assertTrue($system->ftp()->userExists('ftp@example.com'));
    }

    public function test_user_exists_throws_on_other_exit_code(): void
    {
        $system = $this->recordingSystem(exitCode: 1, errorOutput: 'daemon error');
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('daemon error');
        $system->ftp()->userExists('ftp@example.com');
    }

    public function test_delete_user_asks_for_the_user_to_be_deleted(): void
    {
        $system = $this->recordingSystem();
        $system->ftp()->deleteUser('ftp@example.com');

        $this->assertCount(1, $system->requestJournal);
        $this->assertSame(
            [LocalRoot::CLIENT, 'compose.ftpDeleteUser', '--', $this->tmpRoot . '/docker-compose.yml', 'ftp@example.com'],
            $system->requestJournal[0],
        );
    }

    public function test_usermod_quota_passes_empty_string_for_unlimited(): void
    {
        $system = $this->recordingSystem();
        $system->ftp()->usermodQuota('ftp@example.com', null);

        $this->assertSame(
            [LocalRoot::CLIENT, 'compose.ftpSetQuota', '--', $this->tmpRoot . '/docker-compose.yml', 'ftp@example.com', ''],
            $system->requestJournal[0],
        );
    }

    #[DataProvider('quotasWithoutACap')]
    public function test_useradd_omits_the_quota_flag_when_there_is_no_cap(?int $quota): void
    {
        $system = $this->recordingSystem();
        $system->ftp()->useradd(
            'ftp@example.com',
            'secret',
            1001,
            1001,
            '/home/ftpuser/alice/public_html',
            $quota,
        );

        $this->assertSame(
            [LocalRoot::CLIENT, '--env', 'PA_FTP_PASSWORD', 'compose.ftpAddUser', '--', $this->tmpRoot . '/docker-compose.yml',
                'ftp@example.com', '1001', '1001', '/home/ftpuser/alice/public_html', ''],
            $system->requestJournal[0],
        );
    }

    #[DataProvider('quotasWithoutACap')]
    public function test_usermod_never_passes_a_numeric_quota_when_there_is_no_cap(?int $quota): void
    {
        $system = $this->recordingSystem();
        $system->ftp()->usermodQuota('ftp@example.com', $quota);

        $this->assertSame(
            [LocalRoot::CLIENT, 'compose.ftpSetQuota', '--', $this->tmpRoot . '/docker-compose.yml', 'ftp@example.com', ''],
            $system->requestJournal[0],
        );
    }

    /** @return list<array{?int}> */
    public static function quotasWithoutACap(): array
    {
        return [[-1], [0], [null]];
    }

    public function test_useradd_pipes_password_and_runs_mkdb(): void
    {
        $system = $this->recordingSystem();
        $system->ftp()->useradd(
            'ftp@example.com',
            'secret',
            1001,
            1001,
            '/home/ftpuser/alice/public_html',
            512,
        );

        $this->assertCount(2, $system->requestJournal);
        $this->assertSame([
            LocalRoot::CLIENT, '--env', 'PA_FTP_PASSWORD', 'compose.ftpAddUser', '--', $this->tmpRoot . '/docker-compose.yml',
            'ftp@example.com', '1001', '1001', '/home/ftpuser/alice/public_html', '512',
        ], $system->requestJournal[0]);
        $this->assertNotContains('secret', $system->requestJournal[0], 'the password is on a command line');
        $this->assertSame(['PA_FTP_PASSWORD' => 'secret'], $system->envJournal[0]);
        $this->assertSame(
            [LocalRoot::CLIENT, 'compose.ftpMakeDatabase', '--', $this->tmpRoot . '/docker-compose.yml'],
            $system->requestJournal[1],
        );
    }

    public function test_passwd_keeps_the_password_off_the_command_line(): void
    {
        $system = $this->recordingSystem();
        $system->ftp()->passwd('ftp@example.com', "p'w d");

        $this->assertSame(
            [LocalRoot::CLIENT, '--env', 'PA_FTP_PASSWORD', 'compose.ftpSetPassword', '--', $this->tmpRoot . '/docker-compose.yml',
                'ftp@example.com'],
            $system->requestJournal[0],
        );
        $this->assertNotContains("p'w d", $system->requestJournal[0], 'the password is on a command line');
        $this->assertSame(['PA_FTP_PASSWORD' => "p'w d"], $system->envJournal[0]);
    }

    /**
     * @return System&object{execJournal: list<string>, requestJournal: list<list<string>>, envJournal: list<array<string, string>>}
     */
    private function recordingSystem(int $exitCode = 0, string $errorOutput = ''): System
    {
        return new class ($this->tmpRoot, $exitCode, $errorOutput) extends System {
            /** @var list<string> */
            public array $execJournal = [];

            /** @var list<list<string>> */
            public array $requestJournal = [];

            /** @var list<array<string, string>> */
            public array $envJournal = [];

            public function __construct(
                private string $engineRoot,
                private int $processExitCode,
                private string $processErrorOutput,
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

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->requestJournal[] = (array) $cmd;
                $this->execJournal[] = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                $this->envJournal[] = $env;

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return new class ($this->processExitCode, $this->processErrorOutput) extends Process {
                    public function __construct(
                        private int $fakeExitCode,
                        private string $fakeErrorOutput,
                    ) {
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
                        return $this->fakeErrorOutput;
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
