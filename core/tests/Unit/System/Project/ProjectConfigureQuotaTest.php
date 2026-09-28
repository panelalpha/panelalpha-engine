<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * #244: with quota off on the host filesystem setquota fails, and its exit code
 * was ignored -- a project with a disk limit looked limited and was not.
 */
class ProjectConfigureQuotaTest extends TestCase
{
    private const NO_QUOTA = 'setquota: Mountpoint (or device) /home not found or has no quota enabled.';

    /** @var object{warnings: list<string>} */
    private object $log;

    private mixed $previousApp;

    protected function setUp(): void
    {
        parent::setUp();
        // Swap the log without touching an application another test booted.
        $this->previousApp = Facade::getFacadeApplication();
        Facade::setFacadeApplication(null);
        $this->log = new class {
            /** @var list<string> */
            public array $warnings = [];

            public function warning(string $message): void
            {
                $this->warnings[] = $message;
            }
        };
        Log::swap($this->log);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstance('log');
        Facade::setFacadeApplication($this->previousApp);
        parent::tearDown();
    }

    public function test_a_limit_setquota_refuses_is_reported_as_not_enforced(): void
    {
        $system = $this->system(1, self::NO_QUOTA);

        $applied = $this->project($system, 10, null)->configureQuota();

        $this->assertFalse($applied);
        $this->assertSame(['setquota', '-u', 'alice', '10240', '10240', '0', '0', '/home'], $system->argv);
        $this->assertCount(1, $this->log->warnings);
        $this->assertStringContainsString('alice', $this->log->warnings[0]);
        $this->assertStringContainsString('NOT enforced', $this->log->warnings[0]);
        $this->assertStringContainsString(self::NO_QUOTA, $this->log->warnings[0]);
    }

    public function test_an_inode_limit_alone_is_reported_too(): void
    {
        $applied = $this->project($this->system(1, self::NO_QUOTA), null, 5000)->configureQuota();

        $this->assertFalse($applied);
        $this->assertCount(1, $this->log->warnings);
    }

    public function test_an_applied_limit_is_quiet(): void
    {
        $applied = $this->project($this->system(0, ''), 10, null)->configureQuota();

        $this->assertTrue($applied);
        $this->assertSame([], $this->log->warnings);
    }

    public function test_unlimited_stays_quiet_when_quota_is_off(): void
    {
        $system = $this->system(1, self::NO_QUOTA);

        $applied = $this->project($system, -1, null)->configureQuota();

        $this->assertTrue($applied);
        $this->assertSame(['setquota', '-u', 'alice', '0', '0', '0', '0', '/home'], $system->argv);
        $this->assertSame([], $this->log->warnings);
    }

    private function project(System $system, ?int $diskMb, ?int $inodes): Project
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails([
            'template' => 'default',
            'disk_space_limit' => $diskMb,
            'inodes_limit' => $inodes,
        ]);

        return new Project($system, $model);
    }

    private function system(int $exitCode, string $stderr): System
    {
        return new class ($exitCode, $stderr) extends System {
            /** @var list<string> */
            public array $argv = [];

            public function __construct(private int $exitCode, private string $stderr)
            {
            }

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->argv = (array) $cmd;

                return new class ($this->exitCode, $this->stderr) extends Process {
                    public function __construct(private int $code, private string $stderr)
                    {
                        parent::__construct(['true']);
                    }

                    public function getExitCode(): ?int
                    {
                        return $this->code;
                    }

                    public function getErrorOutput(): string
                    {
                        return $this->stderr;
                    }
                };
            }

            public function filesystem(): System\Filesystem
            {
                return new class ($this) extends System\Filesystem {
                    public function getHomeFilesystemMountPoint(): string
                    {
                        return '/home';
                    }
                };
            }
        };
    }
}
