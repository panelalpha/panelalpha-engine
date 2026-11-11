<?php

namespace Tests\Unit\System\Project\Dind;

use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\Dind\AccountImage;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Privileged;
use App\System\Project\ContainerLimits;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountCpu;
use App\System\Project\Dind\AccountRuntime;
use App\System\Project\Dind\CpuLimitDeferred;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A changed CPU limit reaches the account's compose file, nothing else in it moves, and a running account is
 * capped at once; a root broker that cannot do that leaves the limit for the account's next recreate, and says so.
 */
class AccountCpuTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../../../../templates/user/dind/project/docker-compose.yml.blade.php';

    private const COMPOSE = <<<'YAML'
        services:
          dind:
            image: ghcr.io/panelalpha/engine-user-dind:v2.0.2
            restart: always
            container_name: shop
            tmpfs:
              - /run:mode=755,size=64m
            volumes:
              - /home/shop/:/home/shop/
            mem_limit: 2048M
            cpus: 1.5
        networks:
          default:
            name: pash-default-network
            external: true
        YAML;

    private const UNLIMITED = <<<'YAML'
        services:
          dind:
            image: ghcr.io/panelalpha/engine-user-dind:v2.0.2
            container_name: shop
            mem_limit: 2048M
        YAML;

    private const OLD_BROKER = "pa-root: refused: there is no action 'docker.limitCpu'\n";

    private const BROKER = LocalRoot::CLIENT;

    private string $compose = '';

    /** @var list<list<string>> */
    private array $requests = [];

    /** @var array<string, Process> */
    private array $answers = [];

    public function test_it_sets_the_cpu_limit_and_nothing_else(): void
    {
        [$yaml, $container] = AccountCpu::withLimit(self::COMPOSE, 3.0);
        $before = Yaml::parse(self::COMPOSE);
        $after = Yaml::parse((string) $yaml);

        $this->assertSame('shop', $container);
        $this->assertSame(3.0, $after['services']['dind']['cpus']);
        unset($before['services']['dind']['cpus'], $after['services']['dind']['cpus']);
        $this->assertSame($before, $after);
    }

    /** No limit is a compose file with no `cpus`, the way the template renders it. */
    public function test_no_limit_removes_the_key(): void
    {
        [$yaml] = AccountCpu::withLimit(self::COMPOSE, null);

        $this->assertArrayNotHasKey('cpus', Yaml::parse((string) $yaml)['services']['dind']);
    }

    public function test_an_account_without_a_limit_gets_one(): void
    {
        [$yaml] = AccountCpu::withLimit(self::UNLIMITED, 0.5);

        $this->assertSame(0.5, Yaml::parse((string) $yaml)['services']['dind']['cpus']);
    }

    /** Nothing to write when the file already says it, so a repeated limit does not reformat the account's file. */
    public function test_a_limit_already_in_the_file_rewrites_nothing(): void
    {
        $this->assertSame([null, 'shop'], AccountCpu::withLimit(self::COMPOSE, 1.5));
        $this->assertSame([null, 'shop'], AccountCpu::withLimit(self::UNLIMITED, null));
    }

    public function test_a_file_without_the_account_service_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        AccountCpu::withLimit("services:\n  web:\n    image: nginx\n", 1.0);
    }

    /** A recreate renders the template, which carries the same limit this writes, so the limit survives it. */
    public function test_the_rendered_account_carries_the_limit_this_writes(): void
    {
        $user = $this->user(2.5);
        $rendered = Blade::render((string) file_get_contents(self::TEMPLATE), [
            'image' => AccountImage::REF,
            'user' => 'shop',
            'isolation' => AccountRuntime::composeIsolation(),
            ...ContainerLimits::templateVars($user, $this->system()),
        ]);

        $this->assertSame(2.5, Yaml::parse($rendered)['services']['dind']['cpus']);
        $this->assertSame([null, 'shop'], AccountCpu::withLimit($rendered, AccountCpu::limitOf($user->getCpuLimit())));
    }

    /** @return iterable<string, array{float|int|null, ?float}> */
    public static function storedLimits(): iterable
    {
        yield 'a limit' => [1.5, 1.5];
        yield 'a whole number' => [3, 3.0];
        yield 'never set' => [null, null];
        yield 'unlimited' => [-1, null];
        yield 'zero, which the template leaves out too' => [0, null];
    }

    #[DataProvider('storedLimits')]
    public function test_the_account_is_capped_at_what_the_template_would_write(float|int|null $stored, ?float $cpus): void
    {
        $this->assertSame($cpus, AccountCpu::limitOf($stored));
    }

    public function test_a_running_account_is_capped_live_with_the_brokers_argv(): void
    {
        $this->compose = self::UNLIMITED;

        $this->account(1.5, running: true)->applyCpuLimit();

        $this->assertSame([[self::BROKER, 'docker.limitCpu', '--', 'shop', '1.5']], $this->requests);
        $this->assertSame(1.5, Yaml::parse($this->compose)['services']['dind']['cpus']);
    }

    public function test_a_stopped_account_gets_the_limit_in_its_file_and_no_docker_call(): void
    {
        $this->compose = self::UNLIMITED;

        $this->account(2.0, running: false)->applyCpuLimit();

        $this->assertSame([], $this->requests);
        $this->assertSame(2.0, Yaml::parse($this->compose)['services']['dind']['cpus']);
    }

    /** Docker reads `--cpus 0` as no change, so a capped account is given the whole host instead. */
    public function test_a_cleared_limit_gives_a_capped_running_account_every_cpu_of_the_host(): void
    {
        $this->compose = self::COMPOSE;
        $this->answers['docker.nanoCpus'] = FakeProcess::ok("1500000000\n");
        $this->answers['docker.info'] = FakeProcess::ok('{"NCPU":8,"MemTotal":1}');

        $this->account(-1, running: true)->applyCpuLimit();

        $this->assertSame([
            [self::BROKER, 'docker.nanoCpus', '--', 'shop'],
            [self::BROKER, 'docker.info', '--'],
            [self::BROKER, 'docker.limitCpu', '--', 'shop', '8'],
        ], $this->requests);
        $this->assertArrayNotHasKey('cpus', Yaml::parse($this->compose)['services']['dind']);
    }

    public function test_a_cleared_limit_leaves_an_uncapped_running_account_alone(): void
    {
        $this->compose = self::COMPOSE;
        $this->answers['docker.nanoCpus'] = FakeProcess::ok("0\n");

        $this->account(null, running: true)->applyCpuLimit();

        $this->assertSame([[self::BROKER, 'docker.nanoCpus', '--', 'shop']], $this->requests);
        $this->assertArrayNotHasKey('cpus', Yaml::parse($this->compose)['services']['dind']);
    }

    /** A root broker image without this action refuses it: the file carries the limit, and the answer says when. */
    public function test_a_broker_without_the_action_leaves_the_limit_for_the_next_recreate(): void
    {
        $this->compose = self::UNLIMITED;
        $this->answers['docker.limitCpu'] = FakeProcess::failed(self::OLD_BROKER, Privileged::REFUSED);

        try {
            $this->account(3.0, running: true)->applyCpuLimit();
            $this->fail('A refused limit was reported as applied.');
        } catch (CpuLimitDeferred $deferred) {
            $this->assertSame(
                'The CPU limit is saved, but the running account keeps its current one until its container is next '
                    . "recreated: the root broker refused it (there is no action 'docker.limitCpu').",
                $deferred->getMessage(),
            );
        }

        $this->assertSame(3.0, Yaml::parse($this->compose)['services']['dind']['cpus']);
    }

    /** A broker without the limit's own read refuses it too: a cleared limit waits for the next recreate likewise. */
    public function test_a_broker_without_the_limit_read_leaves_a_cleared_limit_for_the_next_recreate(): void
    {
        $this->compose = self::COMPOSE;
        $this->answers['docker.nanoCpus'] = FakeProcess::failed("pa-root: refused: there is no action 'docker.nanoCpus'\n", Privileged::REFUSED);

        $this->expectException(CpuLimitDeferred::class);
        $this->expectExceptionMessage("the root broker refused it (there is no action 'docker.nanoCpus').");

        $this->account(null, running: true)->applyCpuLimit();
    }

    public function test_a_cleared_limit_waits_when_docker_does_not_say_how_many_cpus_the_host_has(): void
    {
        $this->compose = self::COMPOSE;
        $this->answers['docker.nanoCpus'] = FakeProcess::ok("1500000000\n");
        $this->answers['docker.info'] = FakeProcess::ok('{}');

        $this->expectException(CpuLimitDeferred::class);
        $this->expectExceptionMessage('Docker did not say how many CPUs the host has.');

        $this->account(null, running: true)->applyCpuLimit();
    }

    /** Only a refusal is deferred: Docker turning the value down is an error, as `docker update` gives it. */
    public function test_a_limit_docker_refuses_is_an_error_not_a_deferral(): void
    {
        $this->compose = self::UNLIMITED;
        $this->answers['docker.limitCpu'] = FakeProcess::failed(
            'Error response from daemon: Range of CPUs is from 0.01 to 8.00, as there are only 8 CPUs available',
        );

        $this->expectException(DockerErrorException::class);
        $this->expectExceptionMessage('Range of CPUs is from 0.01 to 8.00');

        $this->account(64.0, running: true)->applyCpuLimit();
    }

    private function account(float|int|null $cpuLimit, bool $running): Dind
    {
        return new class ($this->system(), $this->user($cpuLimit), $running) extends Dind {
            public function __construct(private System $host, private User $model, private bool $running)
            {
            }

            public function system(): System
            {
                return $this->host;
            }

            public function userModel(): User
            {
                return $this->model;
            }

            public function composeFilePath(): string
            {
                return '/opt/panelalpha/shared-hosting/users/shop/docker-compose.yml';
            }

            public function isRunning(): bool
            {
                return $this->running;
            }
        };
    }

    private function user(float|int|null $cpuLimit): User
    {
        $user = new User();
        $user->username = 'shop';
        $user->setDetails(['memory_limit' => 2048, 'cpu_limit' => $cpuLimit]);

        return $user;
    }

    private function system(): System
    {
        return new class ($this) extends System {
            public function __construct(private AccountCpuTest $test)
            {
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return $this->test->answer(is_array($cmd) ? $cmd : [$cmd]);
            }

            public function filesystem(): Filesystem
            {
                return new class ($this, $this->test) extends Filesystem {
                    public function __construct(System $system, private AccountCpuTest $test)
                    {
                        parent::__construct($system);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return $this->test->composeFile();
                    }

                    public function writeFileReplacingPath(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->test->replaceComposeFile($contents);
                    }

                    public function getHomeFilesystemParentBlockDevice(): string
                    {
                        return '/dev/sda';
                    }
                };
            }
        };
    }

    /** @param list<string> $argv */
    public function answer(array $argv): Process
    {
        $this->requests[] = $argv;

        return $this->answers[(string) LocalRoot::action($argv)] ?? FakeProcess::ok();
    }

    public function composeFile(): string
    {
        return $this->compose;
    }

    public function replaceComposeFile(string $contents): void
    {
        $this->compose = $contents;
    }
}
