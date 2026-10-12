<?php

namespace Tests\Unit\System\Project\PhpHosting;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\PhpErrorLogConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class PhpErrorLogConfigTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/pa-php-error-log-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/engine/templates/user/default/project/php', 0777, true);
        file_put_contents($this->root . '/engine/templates/user/default/project/php/versions-available', "8.3\n8.2\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_an_existing_project_gets_the_error_log_lines_and_an_account_owned_directory(): void
    {
        $this->account('8.3', "max_execution_time = 600\n", "[www]\nuser = 1001\n");
        $this->account('8.2', "max_execution_time = 600\n", "[www]\nuser = 1001\n");

        [$system, $project] = $this->hostingProject();
        (new PhpErrorLogConfig())->apply($project);

        foreach (['8.3', '8.2'] as $version) {
            $ini = $this->accountFile($version, 'default.ini');
            $this->assertStringContainsString("log_errors = On\nerror_log = /var/log/php/php_error.log\n", $ini);
            $pool = $this->accountFile($version, 'fpm/pool.d/www.conf');
            $this->assertStringContainsString("catch_workers_output = yes\ndecorate_workers_output = no\n", $pool);
        }

        $logDir = $this->root . '/engine/users/alice/log/php';
        $this->assertContains(['sudo', 'mkdir', '-p', $logDir], $system->commands);
        $this->assertContains(['sudo', 'chown', '1001:1001', $logDir], $system->commands);
        $this->assertContains(['sudo', 'chmod', '755', $logDir], $system->commands);
    }

    public function test_applying_twice_does_not_repeat_a_directive(): void
    {
        $this->account('8.3', "log_errors = On\nerror_log = /var/log/php/php_error.log\n", "[www]\n");
        [$system, $project] = $this->hostingProject();

        (new PhpErrorLogConfig())->apply($project);
        (new PhpErrorLogConfig())->apply($project);

        $ini = $this->accountFile('8.3', 'default.ini');
        $this->assertSame(1, substr_count($ini, 'log_errors'));
        $this->assertSame(1, substr_count($ini, 'error_log'));
        $pool = $this->accountFile('8.3', 'fpm/pool.d/www.conf');
        $this->assertSame(1, substr_count($pool, 'catch_workers_output'));
        $this->assertSame(1, substr_count($pool, 'decorate_workers_output'));
    }

    public function test_a_version_the_project_does_not_ship_is_left_alone(): void
    {
        $this->account('8.3', "max_execution_time = 600\n", "[www]\n");
        [$system, $project] = $this->hostingProject();

        (new PhpErrorLogConfig())->apply($project);

        $this->assertFileDoesNotExist($this->root . '/engine/users/alice/php/8.2/default.ini');
        $this->assertContains(['sudo', 'mkdir', '-p', $this->root . '/engine/users/alice/log/php'], $system->commands);
    }

    private function account(string $version, string $ini, string $pool): void
    {
        $dir = $this->root . "/engine/users/alice/php/{$version}";
        mkdir($dir . '/fpm/pool.d', 0777, true);
        file_put_contents($dir . '/default.ini', $ini);
        file_put_contents($dir . '/fpm/pool.d/www.conf', $pool);
    }

    private function accountFile(string $version, string $relative): string
    {
        return (string) file_get_contents($this->root . "/engine/users/alice/php/{$version}/{$relative}");
    }

    /**
     * @return array{0: System, 1: PhpHosting}
     */
    private function hostingProject(): array
    {
        $system = new class ($this->root) extends System {
            /** @var list<list<string>> */
            public array $commands = [];

            public function __construct(private string $root)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root . '/engine';
            }

            public function homesDirPath(): string
            {
                return $this->root . '/home';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->commands[] = (array) $cmd;

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return new Process(['true']);
            }
        };

        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(['template' => 'default', 'UID' => 1001, 'GID' => 1001]);

        $project = (new ProjectAggregate($system, $model))->runtime();
        $this->assertInstanceOf(PhpHosting::class, $project);

        return [$system, $project];
    }
}
