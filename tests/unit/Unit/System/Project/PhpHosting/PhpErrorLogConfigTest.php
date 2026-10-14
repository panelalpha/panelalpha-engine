<?php

namespace Tests\Unit\System\Project\PhpHosting;

use App\Lib\Project\PhpErrorLog;
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
        file_put_contents($this->root . '/engine/templates/user/default/project/php/versions-available', "8.4\n8.3\n8.2\n");
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

    public function test_php_8_4_gets_no_jit_buffer_and_older_versions_are_not_touched(): void
    {
        $this->account('8.4', "max_execution_time = 600\n", "[www]\n");
        $this->account('8.3', "max_execution_time = 600\n", "[www]\n");
        [$system, $project] = $this->hostingProject();

        (new PhpErrorLogConfig())->apply($project);
        (new PhpErrorLogConfig())->apply($project);

        $this->assertSame(1, substr_count($this->accountFile('8.4', 'default.ini'), "\nopcache.jit_buffer_size = 0\n"));
        $this->assertStringNotContainsString('opcache.jit', $this->accountFile('8.3', 'default.ini'));
    }

    public function test_a_jit_buffer_the_project_sets_itself_is_kept(): void
    {
        $this->account('8.4', "opcache.jit_buffer_size = 128M\n", "[www]\n");
        [$system, $project] = $this->hostingProject();

        (new PhpErrorLogConfig())->apply($project);

        $ini = $this->accountFile('8.4', 'default.ini');
        $this->assertStringContainsString("opcache.jit_buffer_size = 128M\n", $ini);
        $this->assertSame(1, substr_count($ini, 'opcache.jit_buffer_size'));
    }

    public function test_the_shipped_default_ini_of_every_version_is_already_complete(): void
    {
        $shipped = dirname(__DIR__, 6) . '/templates/user/default/project/php';
        foreach (['8.4', '8.3', '8.2'] as $version) {
            $this->account($version, (string) file_get_contents("{$shipped}/{$version}/default.ini"), "[www]\n");
        }
        [$system, $project] = $this->hostingProject();

        (new PhpErrorLogConfig())->apply($project);

        foreach (['8.4', '8.3', '8.2'] as $version) {
            $this->assertSame(file_get_contents("{$shipped}/{$version}/default.ini"), $this->accountFile($version, 'default.ini'), $version);
        }
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

    public function test_the_error_log_file_is_ensured_as_the_account(): void
    {
        $this->account('8.3', "max_execution_time = 600\n", "[www]\n");
        [$system, $project] = $this->hostingProject();

        (new PhpErrorLogConfig())->apply($project);

        $command = $this->accountCommand($system);
        $this->assertSame(['sudo', 'setpriv', '--reuid', '1001', '--regid', '1001', '--clear-groups', 'sh', '-c'], array_slice($command, 0, 9));
        $this->assertSame(['sh', $this->logFile(), '1001:1001'], array_slice($command, -3));
    }

    public function test_root_never_names_the_error_log_file(): void
    {
        $this->account('8.3', "max_execution_time = 600\n", "[www]\n");
        mkdir($this->root . '/engine/users/alice/log/php', 0777, true);
        symlink('/etc/passwd', $this->logFile());

        [$system, $project] = $this->hostingProject();
        (new PhpErrorLogConfig())->apply($project);

        foreach ($system->commands as $command) {
            if (($command[1] ?? null) === 'setpriv') {
                continue;
            }
            $this->assertNotContains($this->logFile(), $command, implode(' ', $command));
        }
    }

    public function test_the_error_log_file_is_created_appendable(): void
    {
        $this->account('8.3', "max_execution_time = 600\n", "[www]\n");
        mkdir($this->root . '/engine/users/alice/log/php', 0777, true);
        [$system, $project] = $this->hostingProject(true);

        (new PhpErrorLogConfig())->apply($project);

        $this->assertTrue(is_file($this->logFile()) && !is_link($this->logFile()));
        $this->assertSame(0664, fileperms($this->logFile()) & 0777);
    }

    public function test_a_symlinked_error_log_is_removed_and_recreated(): void
    {
        $this->account('8.3', "max_execution_time = 600\n", "[www]\n");
        mkdir($this->root . '/engine/users/alice/log/php', 0777, true);
        symlink($this->root . '/engine/users/alice/log/missing.txt', $this->logFile());

        [$system, $project] = $this->hostingProject(true);
        (new PhpErrorLogConfig())->apply($project);

        $this->assertFalse(is_link($this->logFile()));
        $this->assertTrue(is_file($this->logFile()));
        $this->assertFileDoesNotExist($this->root . '/engine/users/alice/log/missing.txt');
    }

    public function test_a_symlink_to_an_existing_file_is_removed_and_its_target_left_alone(): void
    {
        $this->account('8.3', "max_execution_time = 600\n", "[www]\n");
        mkdir($this->root . '/engine/users/alice/log/php', 0777, true);
        $secret = $this->root . '/engine/users/alice/log/secret.txt';
        file_put_contents($secret, "root secret\n");
        chmod($secret, 0600);
        symlink($secret, $this->logFile());

        [$system, $project] = $this->hostingProject(true);
        (new PhpErrorLogConfig())->apply($project);

        $this->assertFalse(is_link($this->logFile()));
        $this->assertSame('', file_get_contents($this->logFile()));
        $this->assertSame("root secret\n", file_get_contents($secret));
        $this->assertSame(0600, fileperms($secret) & 0777);
    }

    public function test_an_existing_account_owned_log_file_is_kept_in_place(): void
    {
        $this->account('8.3', "max_execution_time = 600\n", "[www]\n");
        mkdir($this->root . '/engine/users/alice/log/php', 0777, true);
        file_put_contents($this->logFile(), "kept\n");
        $inode = fileinode($this->logFile());

        [$system, $project] = $this->hostingProject(true, $this->accountOwnerOf($this->logFile()));
        (new PhpErrorLogConfig())->apply($project);

        $this->assertSame("kept\n", file_get_contents($this->logFile()));
        $this->assertSame($inode, fileinode($this->logFile()));
        $this->assertSame(0664, fileperms($this->logFile()) & 0777);
    }

    public function test_a_log_file_someone_else_owns_is_replaced_by_an_account_owned_copy(): void
    {
        $this->account('8.3', "max_execution_time = 600\n", "[www]\n");
        mkdir($this->root . '/engine/users/alice/log/php', 0777, true);
        file_put_contents($this->logFile(), "written by the master\n");
        chmod($this->logFile(), 0644);
        $inode = fileinode($this->logFile());

        [$system, $project] = $this->hostingProject(true);
        (new PhpErrorLogConfig())->apply($project);

        $this->assertSame("written by the master\n", file_get_contents($this->logFile()));
        $this->assertNotSame($inode, fileinode($this->logFile()));
        $this->assertSame(0664, fileperms($this->logFile()) & 0777);
        $this->assertSame([PhpErrorLog::FILE_NAME], array_values(array_diff(scandir(dirname($this->logFile())), ['.', '..'])));
    }

    public function test_a_directory_named_like_the_log_is_left_alone(): void
    {
        $this->account('8.3', "max_execution_time = 600\n", "[www]\n");
        mkdir($this->root . '/engine/users/alice/log/php/php_error.log', 0750, true);

        [$system, $project] = $this->hostingProject(true);
        (new PhpErrorLogConfig())->apply($project);

        $this->assertTrue(is_dir($this->logFile()));
        $this->assertSame(0750, fileperms($this->logFile()) & 0777);
    }

    /**
     * @param object{commands: list<list<string>>} $system
     * @return list<string>
     */
    private function accountCommand(object $system): array
    {
        $found = array_values(array_filter($system->commands, fn (array $c): bool => in_array($this->logFile(), $c, true)));
        $this->assertCount(1, $found);

        return $found[0];
    }

    /** An account is never uid 0, so a run as root hands the file to a stand-in account first. */
    private function accountOwnerOf(string $file): string
    {
        if (posix_geteuid() !== 0) {
            return fileowner($this->root) . ':' . filegroup($this->root);
        }

        chown($file, 1001);
        chgrp($file, 1001);

        return '1001:1001';
    }

    private function logFile(): string
    {
        return $this->root . '/engine/users/alice/log/php/php_error.log';
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
    private function hostingProject(bool $runAsAccount = false, string $owner = '1001:1001'): array
    {
        $system = new class ($this->root, $runAsAccount) extends System {
            /** @var list<list<string>> */
            public array $commands = [];

            public function __construct(private string $root, private bool $runAsAccount)
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
                if ($this->runAsAccount && (((array) $cmd)[1] ?? null) === 'setpriv') {
                    return (new Process(array_slice((array) $cmd, 7)))->mustRun()->getOutput();
                }

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return new Process(['true']);
            }
        };

        $model = new ModelsUser();
        $model->username = 'alice';
        [$uid, $gid] = array_map('intval', explode(':', $owner));
        $model->setDetails(['template' => 'default', 'UID' => $uid, 'GID' => $gid]);

        $project = (new ProjectAggregate($system, $model))->runtime();
        $this->assertInstanceOf(PhpHosting::class, $project);

        return [$system, $project];
    }
}
