<?php

namespace Tests\Integration\Console\Users;

use App\Console\Commands\System\CleanupDisconnectedDomains as SystemCleanup;
use App\Console\Commands\Users\CleanupDisconnectedDomains as ProjectCleanup;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Services\Webserver;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/** `project:domain:cleanup` and `system:domain:cleanup`: only vhosts no domain row names are removed, then the webserver reloads. */
class DisconnectedDomainsCleanupCommandTest extends TestCase
{
    use InMemoryDatabase;

    /** @var array<string, list<string>> username => domains with a vhost, '' for the host-wide webserver */
    public array $vhosts = [];

    /** @var list<string> */
    public array $calls = [];

    public ?string $listFailure = null;

    private string $printed = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['env.KEEP_WEBSERVER_LOGS_FOR_DELETED_DOMAINS' => false]);
        $this->makeMainDomain($this->makeUser('alice'), 'alice.example.test');
        $this->makeMainDomain($this->makeUser('bob'), 'bob.example.test');
        $test = $this;
        $this->app->instance(System::class, new class($test) extends RecordingSystem
        {
            public function __construct(private readonly DisconnectedDomainsCleanupCommandTest $test) {}

            public function webserver(): Webserver
            {
                $test = $this->test;

                return new class($this, $test) extends Webserver
                {
                    public function __construct(System $system, private readonly DisconnectedDomainsCleanupCommandTest $test)
                    {
                        parent::__construct($system);
                    }

                    public function listDomains(): array
                    {
                        return $this->test->vhostsOf('');
                    }

                    public function deleteDomainsConfigs(array $domainNames): void
                    {
                        $this->test->calls[] = 'configs '.implode(',', $domainNames);
                    }

                    public function deleteDomainsLogsDirs(array $domainNames): void
                    {
                        $this->test->calls[] = 'logs '.implode(',', $domainNames);
                    }

                    public function reload(bool $rebindIpListeners = false): void
                    {
                        $this->test->calls[] = 'reload';
                    }
                };
            }
        });
    }

    /** @return list<string> */
    public function vhostsOf(string $owner): array
    {
        if ($this->listFailure !== null) {
            throw new RuntimeException($this->listFailure);
        }

        return $this->vhosts[$owner] ?? [];
    }

    /** @param class-string<Command> $class */
    private function command(string $class, array $input): int
    {
        $test = $this;
        $command = $class === ProjectCleanup::class
            ? new class($test) extends ProjectCleanup
            {
                public function __construct(private readonly DisconnectedDomainsCleanupCommandTest $test)
                {
                    parent::__construct();
                }

                protected function project(User $user): Project
                {
                    $test = $this->test;

                    return new class(new RecordingSystem(), $user, $test) extends Project
                    {
                        public function __construct(System $system, User $user, private readonly DisconnectedDomainsCleanupCommandTest $test)
                        {
                            parent::__construct($system, $user);
                        }

                        public function listDomains(): array
                        {
                            return $this->test->vhostsOf($this->model()->username);
                        }

                        public function deleteDomainsConfigs(array $domainNames): void
                        {
                            $this->test->calls[] = $this->model()->username.' configs '.implode(',', $domainNames);
                        }

                        public function reloadWebserver(): void
                        {
                            $this->test->calls[] = $this->model()->username.' reload';
                        }
                    };
                }
            }
            : new SystemCleanup();
        $command->setLaravel($this->app);
        $output = new BufferedOutput();
        $arguments = new ArrayInput($input);
        $arguments->setInteractive(false);
        $code = $command->run($arguments, $output);
        $this->printed = $output->fetch();

        return $code;
    }

    public function test_every_project_loses_only_the_vhosts_its_domains_no_longer_name(): void
    {
        $this->vhosts = [
            'alice' => ['alice.example.test', 'gone.example.test', 'old.example.test'],
            'bob' => ['bob.example.test'],
        ];

        $this->assertSame(0, $this->command(ProjectCleanup::class, ['--all' => true, '--force' => true]));

        $this->assertSame(['alice configs gone.example.test,old.example.test', 'alice reload'], $this->calls);
        $this->assertStringContainsString('Found 2 disconnected domains:', $this->printed);
        $this->assertStringContainsString('Project `bob`:', $this->printed);
        $this->assertStringContainsString('Did not found any disconnected domains. Nothing to cleanup.', $this->printed);
        $this->assertStringContainsString('Domains cleaned up.', $this->printed);
    }

    public function test_one_project_can_be_named_and_nothing_is_removed_without_force_when_nobody_can_confirm(): void
    {
        $this->vhosts = ['alice' => ['gone.example.test'], 'bob' => ['stale.example.test']];

        $this->assertSame(1, $this->command(ProjectCleanup::class, ['--project' => 'alice']));

        $this->assertSame([], $this->calls);
        $this->assertStringNotContainsString('Project `bob`', $this->printed);
    }

    public function test_an_unknown_project_is_refused(): void
    {
        $this->assertSame(1, $this->command(ProjectCleanup::class, ['--project' => 'carol']));
        $this->assertStringContainsString('Invalid username', $this->printed);
    }

    public function test_the_host_webserver_loses_the_vhosts_and_logs_of_domains_no_row_names(): void
    {
        $this->vhosts = ['' => ['alice.example.test', 'gone.example.test', 'bob.example.test']];

        $this->assertSame(0, $this->command(SystemCleanup::class, ['--force' => true]));

        $this->assertSame(['configs gone.example.test', 'logs gone.example.test', 'reload'], $this->calls);
        $this->assertStringContainsString('Found 1 disconnected domains:', $this->printed);
        $this->assertStringContainsString('  gone.example.test', $this->printed);
        $this->assertStringContainsString('Domains cleaned up.', $this->printed);
    }

    public function test_the_host_webserver_keeps_the_logs_when_told_to(): void
    {
        config(['env.KEEP_WEBSERVER_LOGS_FOR_DELETED_DOMAINS' => true]);
        $this->vhosts = ['' => ['gone.example.test']];

        $this->assertSame(0, $this->command(SystemCleanup::class, ['--force' => true]));

        $this->assertSame(['configs gone.example.test', 'reload'], $this->calls);
    }

    public function test_the_host_webserver_with_nothing_disconnected_is_left_alone(): void
    {
        $this->vhosts = ['' => ['alice.example.test', 'bob.example.test']];

        $this->assertSame(0, $this->command(SystemCleanup::class, ['--force' => true]));

        $this->assertSame([], $this->calls);
        $this->assertStringContainsString('Did not found any disconnected domains. Nothing to cleanup.', $this->printed);
    }

    public function test_the_host_cleanup_needs_force_when_nobody_can_confirm(): void
    {
        $this->vhosts = ['' => ['gone.example.test']];

        $this->assertSame(1, $this->command(SystemCleanup::class, []));

        $this->assertSame([], $this->calls);
    }

    public function test_a_webserver_that_cannot_list_its_vhosts_is_reported(): void
    {
        $this->listFailure = 'nginx-proxy is not running';

        $this->assertSame(0, $this->command(SystemCleanup::class, ['--force' => true]));

        $this->assertStringContainsString('nginx-proxy is not running', $this->printed);
        $this->assertSame([], $this->calls);
    }
}
