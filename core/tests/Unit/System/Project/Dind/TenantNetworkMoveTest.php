<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Dind\TenantNetwork;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\TenantNetworkMove;
use Tests\TestCase;

/**
 * engine#519: an account moves from pash-default-network to pash-tenants live.
 * The order is the point: join, re-pin and recreate the app, then leave -- so
 * a failure on the way never leaves the app pinned to an address it cannot
 * reach.
 */
class TenantNetworkMoveTest extends TestCase
{
    private const RUN_FILE = '/home/alice/project/docker-compose.panelalpha.yml';

    /** The account's own compose file, as an older template wrote it. */
    private const ACCOUNT_FILE = '/opt/panelalpha/shared-hosting/users/alice/docker-compose.yml';

    private const ACCOUNT_COMPOSE = "services:\n  dind:\n    volumes:\n"
        . "      - ./supervisord.conf:/etc/supervisor/supervisord.conf\n"
        . "networks:\n  default:\n    name: pash-default-network\n    external: true\n";

    /** @var list<string> */
    public array $events = [];

    /** @var array<string, string> */
    public array $files = [];

    /** @var array<string, ?string> the mode each write asked for */
    public array $modes = [];

    /** @var list<string> */
    public array $composeFiles = [self::RUN_FILE];

    /** @var list<string> networks the account container is on, in docker's view */
    public array $networks = [];

    public int $upExit = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['env.USERS_DB_HOST' => null]);
        $this->files = [
            self::RUN_FILE => "    extra_hosts:\n      - 'database-users.shared-hosting.palocal:172.25.0.3'\n",
            self::ACCOUNT_FILE => self::ACCOUNT_COMPOSE,
        ];
    }

    public function test_a_running_account_joins_repins_then_leaves(): void
    {
        $this->networks = [TenantNetwork::LEGACY_NAME];

        $this->assertSame(TenantNetworkMove::MOVED, (new TenantNetworkMove($this->dind(true)))->run());

        $this->assertSame([
            'firewall',
            'connect',
            'firewall',
            'write ' . self::RUN_FILE,
            'app up',
            'write ' . self::ACCOUNT_FILE,
            'disconnect',
            'reload proxy',
        ], $this->events);
        $this->assertStringContainsString('palocal:10.200.0.2', $this->files[self::RUN_FILE]);
        $this->assertSame([TenantNetwork::NAME], $this->networks);
    }

    /**
     * Re-rendering the account from today's template replaced files an older
     * running account mounts, and Docker could not start it again after its
     * next restart. Only the network's name changes.
     */
    public function test_the_account_keeps_every_file_its_container_was_made_from(): void
    {
        $this->networks = [TenantNetwork::LEGACY_NAME];

        (new TenantNetworkMove($this->dind(true)))->run();

        $this->assertNotContains('render template', $this->events);
        $this->assertSame(
            str_replace('name: pash-default-network', 'name: pash-tenants', self::ACCOUNT_COMPOSE),
            $this->files[self::ACCOUNT_FILE]
        );
    }

    /**
     * The run file inlines env_vars and passwords, so it stays 0600 (engine#173)
     * when the repin rewrites it; an override keeps the 0644 its writers use.
     */
    public function test_the_repinned_run_file_stays_owner_only(): void
    {
        $this->networks = [TenantNetwork::LEGACY_NAME];
        $override = '/home/alice/project/' . EngineArtifacts::RUN_COMPOSE_OVERRIDE;
        $this->files[$override] = "      - 'database-users.shared-hosting.palocal:172.25.0.3'\n";
        $this->composeFiles = [self::RUN_FILE, $override];

        (new TenantNetworkMove($this->dind(true)))->run();

        $this->assertSame(EngineArtifacts::RUN_COMPOSE_MODE, $this->modes[self::RUN_FILE]);
        $this->assertSame('644', $this->modes[$override]);
    }

    public function test_an_app_that_does_not_come_back_keeps_the_old_network(): void
    {
        $this->networks = [TenantNetwork::LEGACY_NAME];
        $this->upExit = 1;

        try {
            (new TenantNetworkMove($this->dind(true)))->run();
            $this->fail('expected the move to stop');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('did not come back up', $e->getMessage());
        }
        $this->assertNotContains('disconnect', $this->events);
        $this->assertContains(TenantNetwork::LEGACY_NAME, $this->networks);
    }

    public function test_a_moved_account_is_left_as_it_is(): void
    {
        $this->networks = [TenantNetwork::NAME];
        $this->files[self::RUN_FILE] = "      - 'database-users.shared-hosting.palocal:10.200.0.2'\n";
        $this->files[self::ACCOUNT_FILE] = TenantNetwork::composeOnTenantNetwork(self::ACCOUNT_COMPOSE);

        $this->assertSame(TenantNetworkMove::ALREADY, (new TenantNetworkMove($this->dind(true)))->run());
        $this->assertSame(['firewall'], $this->events);
    }

    public function test_a_stopped_account_is_not_touched(): void
    {
        $this->networks = [TenantNetwork::LEGACY_NAME];

        $this->assertSame(TenantNetworkMove::NOT_RUNNING, (new TenantNetworkMove($this->dind(false)))->run());
        $this->assertSame([], $this->events);
    }

    private function dind(bool $running): Dind
    {
        $test = $this;
        $system = new class ($test) extends System {
            public function __construct(private TenantNetworkMoveTest $test)
            {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = (array) $cmd;
                if ($argv === TenantNetwork::firewallArgv()) {
                    $this->test->events[] = 'firewall';

                    return '';
                }
                if ($argv === TenantNetwork::accountNetworksArgv('alice')) {
                    $out = [];
                    foreach ($this->test->networks as $name) {
                        $out[$name] = $name === TenantNetwork::NAME
                            ? ['IPAddress' => '10.200.128.9', 'IPPrefixLen' => 16]
                            : ['IPAddress' => '172.25.0.9', 'IPPrefixLen' => 24];
                    }

                    return (string) json_encode($out);
                }
                if (array_slice($argv, 0, 4) === ['sudo', 'docker', 'network', 'connect']) {
                    $this->test->events[] = 'connect';
                    $this->test->networks[] = $argv[4];

                    return '';
                }
                if (array_slice($argv, 0, 4) === ['sudo', 'docker', 'network', 'disconnect']) {
                    $this->test->events[] = 'disconnect';
                    $this->test->networks = array_values(array_diff($this->test->networks, [$argv[4]]));

                    return '';
                }
                throw new \LogicException('unexpected command: ' . implode(' ', $argv));
            }

            public function reloadWebserver(): bool
            {
                $this->test->events[] = 'reload proxy';

                return true;
            }

            public function filesystem(): Filesystem
            {
                return new class ($this, $this->test) extends Filesystem {
                    public function __construct(System $system, private TenantNetworkMoveTest $test)
                    {
                        parent::__construct($system);
                    }

                    public function fileExists(string $path): bool
                    {
                        return isset($this->test->files[$path]);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return $this->test->files[$path];
                    }

                    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->test->events[] = 'write ' . $path;
                        $this->test->files[$path] = $contents;
                        $this->test->modes[$path] = $chmod;
                    }
                };
            }
        };

        $user = $this->createStub(User::class);
        $user->method('getChownString')->willReturn('1001:1001');

        $dind = $this->createStub(Dind::class);
        $dind->method('isRunning')->willReturn($running);
        $dind->method('username')->willReturn('alice');
        $dind->method('composeFilePath')->willReturn(self::ACCOUNT_FILE);
        $dind->method('system')->willReturn($system);
        $dind->method('userModel')->willReturn($user);
        $dind->method('userAppComposeEnv')->willReturn([
            'COMPOSE_FILE' => implode(':', $test->composeFiles),
            'COMPOSE_PATH_SEPARATOR' => ':',
        ]);
        $dind->method('projectAction')->willReturnCallback(function (string $action) use ($test): array {
            $test->events[] = 'app ' . $action;

            return ['stdout' => '', 'stderr' => $test->upExit ? 'boom' : '', 'exit_code' => $test->upExit];
        });
        $dind->method('createFromTemplate')->willReturnCallback(function () use ($test): void {
            $test->events[] = 'render template';
        });

        return $dind;
    }
}
