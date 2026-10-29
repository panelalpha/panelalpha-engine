<?php

namespace Tests\Unit\System\Project;

use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\ContainerLimits;
use App\System\Project\Dind\AccountRuntime;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * An unlimited CPU or I/O limit is stored as -1, and must never reach Docker as
 * `cpus: -1` or a negative blkio rate. The rendered account template is parsed
 * as YAML, the way DindAccountRuntimeTest does.
 */
class ContainerLimitsTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../../../templates/user/dind/project/docker-compose.yml.blade.php';

    public function test_unlimited_cpu_and_io_are_left_off_the_container(): void
    {
        $system = $this->system();

        $vars = ContainerLimits::templateVars($this->user([
            'cpu_limit' => -1,
            'device_read_bps' => -1,
            'device_write_bps' => -1,
        ]), $system);

        $this->assertSame('', $vars['cpu_limit']);
        $this->assertNull($vars['device_read_bps']);
        $this->assertNull($vars['device_write_bps']);
        $this->assertSame('', $vars['block_device']);
        $this->assertSame(0, $system->blockDeviceLookups, 'no I/O limit, so no block device is looked up');

        $service = $this->rendered($vars);
        $this->assertArrayNotHasKey('cpus', $service);
        $this->assertArrayNotHasKey('blkio_config', $service);
        $this->assertSame('512M', $service['mem_limit']);
    }

    public function test_unset_cpu_and_io_are_left_off_the_container(): void
    {
        $vars = ContainerLimits::templateVars($this->user([]), $this->system());

        $service = $this->rendered($vars);
        $this->assertArrayNotHasKey('cpus', $service);
        $this->assertArrayNotHasKey('blkio_config', $service);
    }

    public function test_real_cpu_and_io_limits_reach_the_container(): void
    {
        $system = $this->system();

        $vars = ContainerLimits::templateVars($this->user([
            'cpu_limit' => 1.5,
            'device_read_bps' => 1000,
            'device_write_bps' => -1,
        ]), $system);

        $service = $this->rendered($vars);
        $this->assertSame(1.5, $service['cpus']);
        $this->assertSame(
            ['device_read_bps' => [['path' => '/dev/sda', 'rate' => '1000']]],
            $service['blkio_config'],
        );
        $this->assertSame(1, $system->blockDeviceLookups);
    }

    /**
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    private function rendered(array $vars): array
    {
        $yaml = Blade::render((string) file_get_contents(self::TEMPLATE), [
            'user' => 'alice',
            'isolation' => AccountRuntime::composeIsolation(),
            ...$vars,
        ]);

        return Yaml::parse($yaml)['services']['dind'] ?? [];
    }

    /** @param array<string, mixed> $limits */
    private function user(array $limits): User
    {
        $user = new User();
        $user->username = 'alice';
        $user->setDetails(['memory_limit' => 512, ...$limits]);

        return $user;
    }

    private function system(): System
    {
        return new class extends System {
            public int $blockDeviceLookups = 0;

            public function __construct()
            {
            }

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function __construct(private object $owner)
                    {
                    }

                    public function getHomeFilesystemParentBlockDevice(): string
                    {
                        $this->owner->blockDeviceLookups++;

                        return '/dev/sda';
                    }
                };
            }
        };
    }
}
