<?php

namespace Tests\Integration\System\Project\Dind;

use App\Models\User as ModelsUser;
use App\Support\JobBudget;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AbstractApplication;
use App\System\Project\Dind\ContainerOperations;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * An account whose image sysbox no longer maps fails a single-service start or
 * restart with compose's "--project-directory" error until the account is
 * recreated; a stop touches nothing that needs the mapping.
 */
class ContainerOperationsServiceMappingTest extends TestCase
{
    private const COMPOSE = '/opt/panelalpha/shared-hosting/users/alice/docker-compose.yml';

    private string $storage;

    /** @var list<list<string>> */
    private array $commands = [];

    /** @var list<int> */
    private array $timeouts = [];

    /** @var list<string|\Throwable> what each `stat` of /root answers, in turn */
    private array $owners = [];

    private int $awaited = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/pa-service-mapping-' . bin2hex(random_bytes(4));
        mkdir($this->storage . '/logs/deploy', 0777, true);
        $this->app->useStoragePath($this->storage);
        config(['env.DIND_RUNTIME' => 'sysbox-runc']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->storage));
        parent::tearDown();
    }

    public function test_starting_a_service_recreates_an_unmapped_account_before_compose(): void
    {
        $this->owners = ['65534', '65534', '0'];

        $result = $this->operations()->serviceAction('web', 'start');

        $this->assertSame(0, $result['exit_code']);
        $this->assertCount(1, $this->recreates());
        $this->assertLessThan(
            $this->indexOfServiceAction('start'),
            $this->indexOfRecreate(),
            'the account is repaired before the service is started'
        );
    }

    public function test_restarting_a_service_recreates_an_unmapped_account_before_compose(): void
    {
        $this->owners = ['65534', '65534', '0'];

        $result = $this->operations()->serviceAction('web', 'restart');

        $this->assertSame(0, $result['exit_code']);
        $this->assertCount(1, $this->recreates());
        $this->assertLessThan($this->indexOfServiceAction('restart'), $this->indexOfRecreate());
    }

    public function test_stopping_a_service_leaves_the_mapping_alone(): void
    {
        $this->owners = ['65534'];

        $result = $this->operations()->serviceAction('web', 'stop');

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame([], $this->recreates());
        $this->assertSame([], $this->probes());
    }

    public function test_compose_stop_and_start_run_on_the_cleanup_reserve(): void
    {
        $operations = $this->operations(hasApp: true);

        $operations->composeStop();
        $operations->composeStart();

        $this->assertNotEmpty($this->timeouts);
        foreach ($this->timeouts as $timeout) {
            $this->assertSame(JobBudget::CLEANUP_SECONDS, $timeout);
        }
    }

    /** @return list<list<string>> */
    private function recreates(): array
    {
        return array_values(array_filter($this->commands, static fn (array $c): bool => in_array('--force-recreate', $c, true)));
    }

    /** @return list<list<string>> */
    private function probes(): array
    {
        return array_values(array_filter($this->commands, static fn (array $c): bool => array_slice($c, -2) === ['%u', '/root']));
    }

    private function indexOfRecreate(): int
    {
        return $this->indexOf(static fn (array $c): bool => in_array('--force-recreate', $c, true));
    }

    private function indexOfServiceAction(string $action): int
    {
        return $this->indexOf(static function (array $c) use ($action): bool {
            $compose = LocalRoot::composeCommand($c);

            return in_array($action, $compose, true) && in_array('web', $compose, true);
        });
    }

    /** @param callable(list<string>): bool $match */
    private function indexOf(callable $match): int
    {
        foreach ($this->commands as $index => $argv) {
            if ($match($argv)) {
                return $index;
            }
        }

        $this->fail('the expected command was never run');
    }

    private function operations(bool $hasApp = false): ContainerOperations
    {
        $project = $this->project($hasApp);

        return new ContainerOperations($project, new ShellOperations($project));
    }

    private function project(bool $hasApp = false): Dind
    {
        $system = new class ($this->commands, $this->owners, $this->timeouts) extends System {
            /**
             * @param list<list<string>> $commands
             * @param list<string|\Throwable> $owners
             * @param list<int> $timeouts
             */
            public function __construct(private array &$commands, private array &$owners, private array &$timeouts)
            {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = LocalRoot::plain(array_values((array) $cmd));
                $this->commands[] = $argv;
                $this->timeouts[] = $timeout;
                if (array_slice($argv, -2) === ['%u', '/root']) {
                    $owner = array_shift($this->owners) ?? '';
                    if ($owner instanceof \Throwable) {
                        throw $owner;
                    }

                    return $owner;
                }

                return '';
            }

            /** The account's and sysbox's start times are unknown, as on a host that cannot answer. */
            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = LocalRoot::plain(array_values((array) $cmd));
                $unknown = new Process(['php', '-r', 'exit(1);']);
                $unknown->run();

                return $unknown;
            }
        };

        $project = $this->createStub(Dind::class);
        $project->method('system')->willReturn($system);
        $project->method('username')->willReturn('alice');
        $project->method('homeDirPath')->willReturn('/home/alice');
        $project->method('composeFilePath')->willReturn(self::COMPOSE);
        $project->method('shell')->willReturn(new ShellOperations($project));
        $project->method('userAppComposeCommand')->willReturnCallback(
            static fn (array $rest): array => ['docker', 'compose', ...$rest]
        );
        $project->method('userModel')->willReturn($this->createStub(ModelsUser::class));
        $project->method('awaitReady')->willReturnCallback(function (): void {
            $this->awaited++;
        });
        if ($hasApp) {
            $project->method('app')->willReturn($this->createStub(AbstractApplication::class));
        }

        return $project;
    }
}
