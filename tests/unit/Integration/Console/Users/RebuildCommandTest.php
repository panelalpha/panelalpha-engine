<?php

namespace Tests\Integration\Console\Users;

use App\Console\Commands\Users\Rebuild;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Services\Webserver;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/** `project:rebuild`: what it rebuilds for each project, in order, and the host-wide vhost rebuild it ends with. */
class RebuildCommandTest extends TestCase
{
    use InMemoryDatabase;

    /** @var list<string|array<int, string>> */
    public array $calls = [];

    /** @var list<string> */
    public array $failing = [];

    public bool $wipeFails = false;

    private string $printed = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->makeMainDomain($this->makeUser('alice'), 'alice.example.test');
        $this->makeUser('bob');
        $test = $this;
        $this->app->instance(System::class, new class($test) extends RecordingSystem
        {
            public function __construct(private readonly RebuildCommandTest $test) {}

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->test->calls[] = $cmd;
                if ($this->test->wipeFails) {
                    throw new \Exception('rm failed');
                }

                return '';
            }

            public function webserver(): Webserver
            {
                $test = $this->test;

                return new class($this, $test) extends Webserver
                {
                    public function __construct(System $system, private readonly RebuildCommandTest $test)
                    {
                        parent::__construct($system);
                    }

                    public function getCurrentWebserver(): string
                    {
                        return 'nginx-proxy';
                    }

                    public function rebuildDomains(): void
                    {
                        $this->test->calls[] = 'host vhosts';
                    }
                };
            }
        });
    }

    public function record(string $call): void
    {
        $this->calls[] = $call;
        foreach ($this->failing as $failing) {
            if ($call === $failing) {
                throw new RuntimeException("{$call} failed");
            }
        }
    }

    private function rebuild(array $input): int
    {
        $test = $this;
        $command = new class($test) extends Rebuild
        {
            public function __construct(private readonly RebuildCommandTest $test)
            {
                parent::__construct();
            }

            protected function project(User $user): Project
            {
                $test = $this->test;

                return new class(new RecordingSystem(), $user, $test) extends Project
                {
                    public function __construct(System $system, User $user, private readonly RebuildCommandTest $test)
                    {
                        parent::__construct($system, $user);
                    }

                    private function step(string $step): void
                    {
                        $this->test->record($this->model()->username.' '.$step);
                    }

                    public function deleteAllDomainsConfigs(): void
                    {
                        $this->step('wipe configs');
                    }

                    public function rebuildFromSource(?DeployLogger $deployLogger = null, ?string $zipPath = null): void
                    {
                        $this->step('rebuild');
                    }

                    public function rebuildDomains(): void
                    {
                        $this->step('domains');
                    }

                    public function waitForAllRunning(int $tries = 12, int $intervalSeconds = 5): void
                    {
                        $this->step('wait');
                    }

                    public function reloadWebserver(): void
                    {
                        $this->step('reload');
                    }
                };
            }
        };
        $command->setLaravel($this->app);
        $output = new BufferedOutput();
        $code = $command->run(new ArrayInput($input), $output);
        $this->printed = $output->fetch();

        return $code;
    }

    public function test_every_project_is_rebuilt_and_only_one_with_domains_has_them_redone(): void
    {
        $this->assertSame(0, $this->rebuild(['--all' => true]));

        $this->assertSame([
            'alice rebuild', 'alice domains', 'alice wait', 'alice reload',
            'bob rebuild',
            'host vhosts',
        ], $this->calls);
        $this->assertStringContainsString("Rebuilding project 'alice'...", $this->printed);
        $this->assertStringContainsString('  Finished rebuilding project.', $this->printed);
    }

    public function test_wiping_the_vhosts_clears_each_projects_configs_and_the_webservers_directory(): void
    {
        $this->assertSame(0, $this->rebuild(['--project' => 'bob', '--wipe-vhosts-dir' => true]));

        $dir = app(System::class)->engineDirPath().'/webserver-config/nginx-proxy/vhosts';
        $this->assertSame(['bob wipe configs', 'bob rebuild', "rm -rf {$dir}/* {$dir}/.*", 'host vhosts'], $this->calls);
    }

    public function test_a_failed_wipe_of_the_directory_still_rebuilds_the_host_vhosts(): void
    {
        $this->wipeFails = true;

        $this->assertSame(0, $this->rebuild(['--project' => 'bob', '--wipe-vhosts-dir' => true]));

        $this->assertSame('host vhosts', $this->calls[array_key_last($this->calls)]);
    }

    public function test_one_failed_project_fails_the_run_and_the_next_is_still_rebuilt(): void
    {
        $this->failing = ['alice rebuild'];

        $this->assertSame(1, $this->rebuild(['--all' => true]));

        $this->assertSame(['alice rebuild', 'bob rebuild', 'host vhosts'], $this->calls);
        $this->assertStringContainsString('alice rebuild failed', $this->printed);
    }
}
