<?php

namespace Tests\Unit\System\Project\Dind;

use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountMapping;
use App\System\Project\Dind\OuterLifecycle;
use App\System\Project\Dind\ShellOperations;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * An account sysbox started unmapped reads /root as nobody's; only recreating
 * its container maps it again, and an account that cannot be asked is left alone.
 */
class AccountMappingTest extends TestCase
{
    private const COMPOSE = '/opt/users/alice/docker-compose.yml';

    private string $storage;

    /** @var list<list<string>> */
    private array $commands = [];

    /** @var list<string|\Throwable> what each `stat` of /root answers, in turn */
    private array $owners = [];

    private int $awaited = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/pa-mapping-' . bin2hex(random_bytes(4));
        mkdir($this->storage . '/logs/deploy', 0777, true);
        $this->app->useStoragePath($this->storage);
        config(['env.DIND_RUNTIME' => 'sysbox-runc']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->storage));
        parent::tearDown();
    }

    public function test_root_owning_root_is_mapped(): void
    {
        $this->owners = ["0\n"];
        $mapping = new AccountMapping($this->project());

        $this->assertFalse($mapping->isUnmapped());
    }

    public function test_nobody_owning_root_is_unmapped(): void
    {
        $this->owners = ["65534\n"];
        $mapping = new AccountMapping($this->project());

        $this->assertTrue($mapping->isUnmapped());
        $this->assertSame(['stat', '-c', '%u', '/root'], array_slice($this->commands[0], -4));
    }

    public function test_a_probe_that_fails_is_not_unmapped_and_recreates_nothing(): void
    {
        $this->owners = [new \RuntimeException('service "dind" is not running')];
        $mapping = new AccountMapping($this->project());

        $this->assertFalse($mapping->ensureMapped());
        $this->assertSame([], $this->recreates());

        $this->owners = ['OCI runtime exec failed'];
        $this->assertNull($mapping->isUnmapped());
    }

    public function test_an_account_refusing_exec_after_a_sysbox_restart_is_unmapped(): void
    {
        $this->owners = [new \RuntimeException('OCI runtime exec failed: exec failed: unable to start container process: unsafe procfs detected: openat2 fsmount:fscontext:proc/thread-self/fd/: operation not permitted')];
        $mapping = new AccountMapping($this->project());

        $this->assertTrue($mapping->isUnmapped());
    }

    public function test_a_mapped_account_is_left_as_it_is(): void
    {
        $this->owners = ['0'];

        $this->assertFalse((new AccountMapping($this->project()))->ensureMapped());
        $this->assertSame([], $this->recreates());
        $this->assertSame(0, $this->awaited);
    }

    public function test_an_unmapped_account_is_recreated_awaited_and_asked_again(): void
    {
        Log::spy();
        $this->owners = ['65534', '0'];

        $this->assertTrue((new AccountMapping($this->project()))->ensureMapped());

        $this->assertSame(
            [['sudo', 'docker', 'compose', '-f', self::COMPOSE, 'up', '-d', '--remove-orphans', '--force-recreate']],
            $this->recreates()
        );
        $this->assertSame(1, $this->awaited);
        $this->assertCount(2, $this->probes());
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message): bool => $message === 'alice: ' . AccountMapping::RECREATED
        );
    }

    public function test_still_unmapped_after_recreating_is_an_error(): void
    {
        $this->owners = ['65534', '65534'];

        try {
            (new AccountMapping($this->project()))->ensureMapped();
            $this->fail('a recreate that did not map the image must not carry on');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('still has its image unmapped', $e->getMessage());
        }
        $this->assertCount(1, $this->recreates());
    }

    public function test_a_privileged_account_is_never_probed(): void
    {
        config(['env.DIND_RUNTIME' => 'privileged']);
        $this->owners = ['65534'];

        $this->assertFalse((new AccountMapping($this->project()))->ensureMapped());
        $this->assertSame([], $this->probes());
    }

    public function test_up_brings_the_account_up_then_heals_it(): void
    {
        $this->owners = ['65534', '0'];

        (new OuterLifecycle($this->project()))->up();

        $ups = array_values(array_filter($this->commands, static fn (array $c): bool => in_array('up', $c, true)));
        $this->assertSame(['sudo', 'docker', 'compose', '-f', self::COMPOSE, 'up', '-d', '--remove-orphans'], $ups[0]);
        $this->assertContains('--force-recreate', $ups[1]);
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

    private function project(): Dind
    {
        $system = new class ($this->commands, $this->owners) extends System {
            /**
             * @param list<list<string>> $commands
             * @param list<string|\Throwable> $owners
             */
            public function __construct(private array &$commands, private array &$owners)
            {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = array_values((array) $cmd);
                $this->commands[] = $argv;
                if (array_slice($argv, -2) === ['%u', '/root']) {
                    $owner = array_shift($this->owners) ?? '';
                    if ($owner instanceof \Throwable) {
                        throw $owner;
                    }

                    return $owner;
                }

                return '';
            }
        };

        $project = $this->createStub(Dind::class);
        $project->method('system')->willReturn($system);
        $project->method('username')->willReturn('alice');
        $project->method('homeDirPath')->willReturn('/home/alice');
        $project->method('composeFilePath')->willReturn(self::COMPOSE);
        $project->method('shell')->willReturn(new ShellOperations($project));
        $project->method('awaitReady')->willReturnCallback(function (): void {
            $this->awaited++;
        });

        return $project;
    }
}
