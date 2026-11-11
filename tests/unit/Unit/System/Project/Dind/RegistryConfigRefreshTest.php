<?php

namespace Tests\Unit\System\Project\Dind;

use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\Inner\HostCommands;
use App\System\Project\Dind\Inner\ImageSeeding;
use App\System\Project\Dind\InnerDocker;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

/**
 * A registry-settings refresh asks the host daemon about the account's own container: whether the daemon.json the
 * engine renders is mounted into it, and a SIGHUP to its dockerd once the file is written in place.
 */
class RegistryConfigRefreshTest extends TestCase
{
    private const RENDERED = '{"data-root":"/home/demo/docker","live-restore":true}';

    /** @var list<array{string, string}> level, message */
    private array $logged = [];

    public function test_an_account_already_holding_the_rendered_file_asks_nothing(): void
    {
        $system = $this->refresh(self::RENDERED);

        $this->assertSame([], $system->requests);
        $this->assertSame([0, 0], [$system->setups, $system->rewrites]);
        $this->assertSame([], $this->logged);
    }

    public function test_a_mounted_account_is_rewritten_in_place_and_its_dockerd_hung_up(): void
    {
        $system = $this->refresh('{}', mounted: '1');

        $this->assertSame([0, 1], [$system->setups, $system->rewrites]);
        $this->assertSame(
            ['account-container.daemonConfigMounted', 'account-container.reloadDaemon'],
            array_map(LocalRoot::action(...), $system->requests)
        );
        $this->assertSame([['info', 'Pointed this account\'s Docker at the engine\'s image registries']], $this->logged);
    }

    public function test_an_account_without_the_mount_gets_only_the_plain_render(): void
    {
        $system = $this->refresh('{}', mounted: '0');

        $this->assertSame([1, 0], [$system->setups, $system->rewrites]);
        $this->assertSame(['account-container.daemonConfigMounted'], array_map(LocalRoot::action(...), $system->requests));
        $this->assertSame([], $this->logged);
    }

    public function test_a_file_that_is_no_plain_file_is_left_alone(): void
    {
        $system = $this->refresh('{}', mounted: '1', rewritable: false);

        $this->assertSame([0, 0], [$system->setups, $system->rewrites]);
        $this->assertSame(['account-container.daemonConfigMounted'], array_map(LocalRoot::action(...), $system->requests));
        $this->assertSame([], $this->logged);
    }

    public function test_a_container_running_no_dockerd_is_one_dim_line(): void
    {
        $this->refresh('{}', mounted: '1', reloadFailure: 'container demo is running no dockerd of its own');

        $this->assertSame(
            [['dim', 'Could not update this account\'s registry settings: container demo is running no dockerd of its own']],
            $this->logged
        );
    }

    private function refresh(?string $current, string $mounted = '1', bool $rewritable = true, ?string $reloadFailure = null): System
    {
        $system = new class($current, $mounted, $rewritable, $reloadFailure) extends System
        {
            /** @var list<list<string>> */
            public array $requests = [];

            public int $setups = 0;

            public int $rewrites = 0;

            public function __construct(
                private readonly ?string $current,
                private readonly string $mounted,
                private readonly bool $rewritable,
                private readonly ?string $reloadFailure,
            ) {}

            public function filesystem(): Filesystem
            {
                $current = $this->current;

                return new class($this, $current) extends Filesystem
                {
                    public function __construct(System $system, private readonly ?string $current)
                    {
                        parent::__construct($system);
                    }

                    public function fileExists(string $path): bool
                    {
                        return $this->current !== null;
                    }

                    public function fileGetContents(string $path): string
                    {
                        return (string) $this->current;
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $action = LocalRoot::action((array) $cmd);
                if ($action === null || ! str_starts_with($action, 'account-container.')) {
                    return '';
                }
                $this->requests[] = (array) $cmd;
                if ($action !== 'account-container.reloadDaemon') {
                    return $this->mounted;
                }
                if ($this->reloadFailure !== null) {
                    throw new \Exception($this->reloadFailure);
                }

                return '';
            }
        };

        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn('demo');
        $dind->method('daemonJsonPath')->willReturn('/opt/panelalpha/shared-hosting/users/demo/daemon.json');
        $dind->method('daemonJsonContents')->willReturn(self::RENDERED);
        $dind->method('accountDaemon')->willReturnCallback(static fn (): AccountDaemon => new AccountDaemon($dind));
        $dind->method('setupDaemonJson')->willReturnCallback(static function () use ($system): void {
            $system->setups++;
        });
        $dind->method('rewriteDaemonJsonInPlace')->willReturnCallback(static function () use ($system, $rewritable): bool {
            if (! $rewritable) {
                return false;
            }
            $system->rewrites++;

            return true;
        });

        $logged = &$this->logged;
        $host = $this->createStub(HostCommands::class);
        foreach (['logInfo' => 'info', 'logDim' => 'dim'] as $method => $level) {
            $host->method($method)->willReturnCallback(static function (string $message) use (&$logged, $level): void {
                $logged[] = [$level, $message];
            });
        }

        $inner = $this->createStub(InnerDocker::class);
        $inner->method('dind')->willReturn($dind);
        $inner->method('host')->willReturn($host);

        (new ImageSeeding($inner))->hasImage('redis:alpine');

        return $system;
    }
}
