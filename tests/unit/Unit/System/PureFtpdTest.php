<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Services\PureFtpd;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class PureFtpdTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-pureftpd-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot, 0777, true);
        file_put_contents($this->tmpRoot . '/docker-compose.yml', "services: {}\n");
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_ftp_returns_pureftpd_collaborator(): void
    {
        $system = $this->recordingSystem();
        $this->assertInstanceOf(PureFtpd::class, $system->ftp());
    }

    public function test_exec_prefixes_compose_exec_t_ftp(): void
    {
        $system = $this->recordingSystem();
        $system->ftp()->exec(['pure-pw', 'mkdb']);

        $this->assertCount(1, $system->execJournal);
        $cmd = $system->execJournal[0];
        $this->assertStringContainsString('docker compose -f', $cmd);
        $this->assertStringContainsString($this->tmpRoot . '/docker-compose.yml', $cmd);
        $this->assertStringContainsString('exec -T ftp', $cmd);
        $this->assertStringContainsString('pure-pw mkdb', $cmd);
    }

    public function test_reload_touches_passwd_and_runs_mkdb(): void
    {
        $system = $this->recordingSystem();
        $system->ftp()->reload();

        $this->assertCount(2, $system->execJournal);
        $this->assertStringContainsString('touch /etc/pureftpd/pureftpd.passwd', $system->execJournal[0]);
        $this->assertStringContainsString('pure-pw mkdb', $system->execJournal[1]);
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

    public function test_delete_user_runs_userdel(): void
    {
        $system = $this->recordingSystem();
        $system->ftp()->deleteUser('ftp@example.com');

        $this->assertCount(1, $system->execJournal);
        $this->assertStringContainsString('pure-pw userdel ftp@example.com -m', $system->execJournal[0]);
    }

    public function test_usermod_quota_passes_empty_string_for_unlimited(): void
    {
        $system = $this->recordingSystem();
        $system->ftp()->usermodQuota('ftp@example.com', null);

        $cmd = $system->execJournal[0];
        $this->assertStringContainsString('pure-pw usermod ftp@example.com -N', $cmd);
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

        $this->assertCount(2, $system->execJournal);
        $addCmd = $system->execJournal[0];
        $this->assertStringContainsString('/bin/sh -c', $addCmd);
        $this->assertStringNotContainsString('secret', $addCmd, 'the password is on a command line');
        $this->assertSame(['PA_FTP_PASSWORD' => 'secret'], $system->envJournal[0]);
        // sudo resets the environment unless told to keep the variable, and
        // compose copies it into the exec only when named.
        $this->assertStringContainsString('sudo --preserve-env=PA_FTP_PASSWORD docker compose', $addCmd);
        $this->assertStringContainsString('exec -T -e PA_FTP_PASSWORD ftp', $addCmd);
        $this->assertStringContainsString('"$PA_FTP_PASSWORD" | pure-pw useradd', $addCmd);
        $this->assertStringContainsString('pure-pw useradd', $addCmd);
        $this->assertStringContainsString('/home/ftpuser/alice/public_html', $addCmd);
        $this->assertStringContainsString('-N', $addCmd);
        $this->assertStringContainsString('512', $addCmd);
        $this->assertStringContainsString('pure-pw mkdb', $system->execJournal[1]);
    }

    public function test_passwd_keeps_the_password_off_the_command_line(): void
    {
        $system = $this->recordingSystem();
        $system->ftp()->passwd('ftp@example.com', "p'w d");

        $this->assertStringNotContainsString("p'w d", $system->execJournal[0]);
        $this->assertStringContainsString('pure-pw passwd', $system->execJournal[0]);
        $this->assertSame(['PA_FTP_PASSWORD' => "p'w d"], $system->envJournal[0]);
    }

    /**
     * @return System&object{execJournal: list<string>, envJournal: list<array<string, string>>}
     */
    private function recordingSystem(int $exitCode = 0, string $errorOutput = ''): System
    {
        return new class ($this->tmpRoot, $exitCode, $errorOutput) extends System {
            /** @var list<string> */
            public array $execJournal = [];

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
