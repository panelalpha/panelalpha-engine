<?php

namespace Tests\Unit\Console;

use App\System;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class PhpConfigRefreshCommandTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();

        $this->root = sys_get_temp_dir() . '/pa-php-config-refresh-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/engine/templates/user/default/project/php', 0777, true);
        file_put_contents($this->root . '/engine/templates/user/default/project/php/versions-available', "8.3\n");

        $this->app->instance(System::class, $this->fakeSystem());
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_an_existing_project_gains_the_error_log_directives(): void
    {
        $this->makeUser('alice', ['template' => 'default', 'UID' => 1001, 'GID' => 1001]);
        $this->projectFile('alice', '8.3', 'default.ini', "max_execution_time = 600\n");
        $this->projectFile('alice', '8.3', 'fpm/pool.d/www.conf', "[www]\nuser = 1001\n");

        $exit = Artisan::call('projects:php-config:refresh', ['--project' => 'alice']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString("Refreshing the PHP config of 'alice'...", $output);
        $this->assertStringContainsString('Error-log directives written.', $output);
        $this->assertStringContainsString(
            "log_errors = On\nerror_log = /var/log/php/php_error.log\n",
            $this->projectFile('alice', '8.3', 'default.ini')
        );
        $this->assertStringContainsString(
            "catch_workers_output = yes\ndecorate_workers_output = no\n",
            $this->projectFile('alice', '8.3', 'fpm/pool.d/www.conf')
        );
        $this->assertContains(
            ['sudo', 'chown', '1001:1001', $this->root . '/engine/users/alice/log/php'],
            $this->system()->commands
        );
    }

    public function test_a_container_project_is_skipped_without_failing_the_run(): void
    {
        $this->makeUser('bob', ['template' => 'dind', 'UID' => 1002, 'GID' => 1002]);

        $exit = Artisan::call('projects:php-config:refresh', ['--all' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Not a PHP hosting project; nothing to refresh.', $output);
        $this->assertSame([], $this->system()->commands);
    }

    public function test_the_description_says_the_setting_takes_effect_at_the_next_restart(): void
    {
        $command = Artisan::all()['projects:php-config:refresh'];

        $this->assertStringContainsString('next restart', $command->getDescription());
    }

    private function projectFile(string $username, string $version, string $relative, ?string $contents = null): string
    {
        $path = $this->root . "/engine/users/{$username}/php/{$version}/{$relative}";
        if ($contents !== null) {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $contents);
        }

        return (string) file_get_contents($path);
    }

    private function system(): System
    {
        return app(System::class);
    }

    private function fakeSystem(): System
    {
        return new class ($this->root) extends System {
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
    }
}
