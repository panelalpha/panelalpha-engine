<?php

namespace Tests\Unit\System\Project\Dind;

use App\System;
use App\System\Project\Dind\StepDiskLimit;
use PHPUnit\Framework\TestCase;

/**
 * engine#244: what stops a running deploy step for disk -- the host minimum
 * and the project's own limit, which user quota never applied to the
 * account's Docker store.
 */
class StepDiskLimitTest extends TestCase
{
    private const GB = 1073741824;

    public function test_the_host_falling_under_its_minimum_stops_the_step(): void
    {
        $limit = new StepDiskLimit($this->host(['/var/lib/docker' => 30 * 1024, '/home' => 2900], 0), 3 * self::GB, '/home/acme', null);

        $this->assertSame('the engine host has 2.8G free on /home, under DEPLOY_HOST_MIN_FREE (3G)', $limit->reason());
    }

    public function test_a_project_over_its_disk_limit_stops_the_step(): void
    {
        $host = $this->host(['/var/lib/docker' => 30 * 1024, '/home' => 30 * 1024], 2100 * 1024);
        $limit = new StepDiskLimit($host, 3 * self::GB, '/home/acme', 2000);

        $this->assertSame('the project uses 2.1G, over its 2G disk limit', $limit->reason());
        $this->assertContains('du -sxk /home/acme', $host->asked);
    }

    public function test_room_on_both_carries_on(): void
    {
        $limit = new StepDiskLimit($this->host(['/var/lib/docker' => 30 * 1024, '/home' => 30 * 1024], 500 * 1024), 3 * self::GB, '/home/acme', 2000);

        $this->assertNull($limit->reason());
    }

    public function test_unlimited_projects_are_never_measured(): void
    {
        $host = $this->host(['/var/lib/docker' => 30 * 1024, '/home' => 30 * 1024], 99 * 1024 * 1024);

        $this->assertNull((new StepDiskLimit($host, 3 * self::GB, '/home/acme', -1))->reason());
        $this->assertNull((new StepDiskLimit($host, null, '/home/acme', null))->reason());
        $this->assertSame([], array_values(array_filter($host->asked, static fn (string $c): bool => str_starts_with($c, 'du '))));
    }

    public function test_unreadable_disks_carry_on(): void
    {
        $this->assertNull((new StepDiskLimit($this->host([], null), 3 * self::GB, '/home/acme', 2000))->reason());
    }

    /**
     * @param array<string, int> $freeMb df free space per path in MB; a missing path fails
     */
    private function host(array $freeMb, ?int $homeKb): System
    {
        return new class ($freeMb, $homeKb) extends System {
            /** @var list<string> */
            public array $asked = [];

            public function __construct(private array $freeMb, private ?int $homeKb)
            {
            }

            public function execOnHost(string|array $cmd, array $env = []): string
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                $this->asked[] = $line;
                $path = is_array($cmd) ? (string) end($cmd) : '';
                if (str_starts_with($line, 'du ')) {
                    if ($this->homeKb === null) {
                        throw new \RuntimeException('du failed');
                    }

                    return "{$this->homeKb}\t{$path}\n";
                }
                if (!isset($this->freeMb[$path])) {
                    throw new \RuntimeException("df: {$path}: No such file or directory");
                }

                return "Filesystem 1024-blocks Used Available Capacity Mounted on\n"
                    . '/dev/sda1 157286400 1000 ' . ($this->freeMb[$path] * 1024) . " 30% {$path}\n";
            }
        };
    }
}
