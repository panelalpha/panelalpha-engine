<?php

namespace Tests\Unit\System\Project\PhpHosting;

use App\Lib\Deploy\Dind\TenantNetwork;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind\TenantNetworkMove as DindTenantNetworkMove;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\TenantNetworkMove;
use Tests\TestCase;

/**
 * A PHP-hosting account moves from pash-default-network to
 * pash-tenants live: join, be bound, rename the network in its compose file,
 * then leave. Nothing in it pins an address, so nothing is recreated.
 */
class TenantNetworkMoveTest extends TestCase
{
    private const ACCOUNT_FILE = '/opt/panelalpha/shared-hosting/users/alice/docker-compose.yml';

    private const ACCOUNT_COMPOSE = "services:\n  php:\n    container_name: alice\n"
        . "networks: \n  default: \n    name: pash-default-network\n    external: true\n";

    /** @var list<string> */
    public array $events = [];

    /** @var array<string, string> */
    public array $files = [];

    /** @var list<string> */
    public array $networks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = [self::ACCOUNT_FILE => self::ACCOUNT_COMPOSE];
    }

    public function test_a_running_account_joins_is_bound_then_leaves(): void
    {
        $this->networks = [TenantNetwork::LEGACY_NAME];

        $this->assertSame(DindTenantNetworkMove::MOVED, (new TenantNetworkMove($this->account(true)))->run());

        $this->assertSame(
            ['firewall', 'connect', 'firewall', 'write ' . self::ACCOUNT_FILE, 'disconnect', 'reload proxy'],
            $this->events
        );
        $this->assertSame([TenantNetwork::NAME], $this->networks);
        $this->assertStringContainsString('name: pash-tenants', $this->files[self::ACCOUNT_FILE]);
        $this->assertStringNotContainsString('pash-default-network', $this->files[self::ACCOUNT_FILE]);
    }

    public function test_a_moved_account_is_left_as_it_is(): void
    {
        $this->networks = [TenantNetwork::NAME];
        $this->files[self::ACCOUNT_FILE] = TenantNetwork::composeOnTenantNetwork(self::ACCOUNT_COMPOSE);

        $this->assertSame(DindTenantNetworkMove::ALREADY, (new TenantNetworkMove($this->account(true)))->run());
        $this->assertSame(['firewall'], $this->events);
    }

    public function test_a_stopped_account_is_not_touched(): void
    {
        $this->networks = [TenantNetwork::LEGACY_NAME];

        $this->assertSame(DindTenantNetworkMove::NOT_RUNNING, (new TenantNetworkMove($this->account(false)))->run());
        $this->assertSame([], $this->events);
    }

    private function account(bool $running): PhpHosting
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
                    return (string) json_encode(array_fill_keys($this->test->networks, ['IPAddress' => '10.200.128.9']));
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
                    }
                };
            }
        };

        $account = $this->createStub(PhpHosting::class);
        $account->method('isRunning')->willReturn($running);
        $account->method('username')->willReturn('alice');
        $account->method('system')->willReturn($system);
        $account->method('composeFilePath')->willReturn(self::ACCOUNT_FILE);

        return $account;
    }
}
