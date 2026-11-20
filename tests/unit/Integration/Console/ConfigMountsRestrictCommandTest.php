<?php

namespace Tests\Integration\Console;

use App\System;
use App\System\Privileged;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

class ConfigMountsRestrictCommandTest extends TestCase
{
    use InMemoryDatabase;

    private const COMPOSE = "services:\n  php:\n    volumes:\n      - /home/alice/:/var/www/\n"
        ."      - ./apache-sites:/etc/apache2/sites-enabled\n      - ./log:/var/log\n"
        ."      - ./entrypoint-init.d/:/entrypoint-init.d/\n";

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();

        $this->root = sys_get_temp_dir().'/pa-config-mounts-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/engine/users/alice', 0777, true);
        file_put_contents($this->composePath(), self::COMPOSE);

        $this->app->instance(System::class, $this->fakeSystem());
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_an_existing_project_gets_read_only_config_mounts_without_a_restart(): void
    {
        $this->makeUser('alice', ['template' => 'default', 'UID' => 1001, 'GID' => 1001]);

        $exit = Artisan::call('projects:config-mounts:restrict', ['--project' => 'alice']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString("Restricting the config mounts of 'alice'...", $output);
        $this->assertStringContainsString("Read-only from the container's next recreate.", $output);
        $compose = (string) file_get_contents($this->composePath());
        $this->assertStringContainsString("- ./apache-sites:/etc/apache2/sites-enabled:ro\n", $compose);
        $this->assertStringContainsString("- ./entrypoint-init.d/:/entrypoint-init.d/:ro\n", $compose);
        $this->assertStringContainsString("- ./log:/var/log\n", $compose);
        $this->assertNotContains('compose.up', $this->actions());
    }

    public function test_a_second_run_says_it_is_already_done(): void
    {
        $this->makeUser('alice', ['template' => 'default', 'UID' => 1001, 'GID' => 1001]);
        Artisan::call('projects:config-mounts:restrict', ['--project' => 'alice']);

        Artisan::call('projects:config-mounts:restrict', ['--project' => 'alice']);

        $this->assertStringContainsString('Already read-only.', Artisan::output());
    }

    /** A stopped project is not started for it: its next start recreates the container. */
    public function test_recreate_leaves_a_stopped_project_stopped(): void
    {
        $this->makeUser('alice', ['template' => 'default', 'UID' => 1001, 'GID' => 1001]);

        $exit = Artisan::call('projects:config-mounts:restrict', ['--project' => 'alice', '--recreate' => true]);

        $this->assertSame(0, $exit);
        $this->assertContains(Privileged::compose($this->composePath())->runningServices(), $this->system()->commands);
        $this->assertNotContains('compose.up', $this->actions());
    }

    public function test_a_container_project_is_skipped_without_failing_the_run(): void
    {
        $this->makeUser('bob', ['template' => 'dind', 'UID' => 1002, 'GID' => 1002]);

        $exit = Artisan::call('projects:config-mounts:restrict', ['--project' => 'bob']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Not a PHP hosting project; nothing to restrict.', Artisan::output());
    }

    private function composePath(): string
    {
        return $this->root.'/engine/users/alice/docker-compose.yml';
    }

    /** @return list<string|null> */
    private function actions(): array
    {
        return array_map(static fn (array $cmd): ?string => LocalRoot::action($cmd), $this->system()->commands);
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

            public function __construct(private string $root) {}

            public function engineDirPath(): string
            {
                return $this->root.'/engine';
            }

            public function homesDirPath(): string
            {
                return $this->root.'/home';
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
