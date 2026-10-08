<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\System;
use App\Lib\Deploy\Telemetry\HostFacts;
use App\System\Services\Webserver;
use Tests\TestCase;

/**
 * The handful of facts telemetry reads off the machine.
 *
 * Two properties matter here and neither is about correctness of a value.
 * First, what leaves the host: the envelope describes the platform, and must
 * not carry the hostname, the IP or an account name - the identifying
 * material exists only to be hashed into an install id and is never
 * transmitted. Second, every probe is best-effort: telemetry that can throw
 * is telemetry that can break a deploy or a cron run.
 */
class HostFactsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HostFacts::resetProbeCache();
    }

    protected function tearDown(): void
    {
        HostFacts::resetProbeCache();
        parent::tearDown();
    }

    /**
     * A System whose `docker info` answers with $info, counting its calls.
     */
    private function system(?array $info, ?int &$calls = null): System
    {
        $calls = 0;
        $webserver = $this->createStub(Webserver::class);
        $webserver->method('getCurrentWebserver')->willReturn('openlitespeed');

        return new class ($info, $calls, $webserver) extends System {
            public function __construct(
                private readonly ?array $info,
                private ?int &$calls,
                private Webserver $webserverStub,
            ) {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->calls++;
                if ($this->info === null) {
                    throw new \RuntimeException('Cannot connect to the Docker daemon');
                }

                return (string) json_encode($this->info);
            }

            public function webserver(): Webserver
            {
                return $this->webserverStub;
            }
        };
    }

    public function test_the_envelope_describes_the_platform(): void
    {
        $envelope = HostFacts::envelope($this->system([
            'OperatingSystem' => 'Ubuntu 24.04 LTS',
            'KernelVersion' => '6.8.0-51-generic',
            'ServerVersion' => '27.3.1',
            'Runtimes' => ['runc' => [], 'sysbox-runc' => []],
        ]));

        $this->assertSame('Ubuntu 24.04 LTS', $envelope['os']);
        $this->assertSame('6.8.0-51-generic', $envelope['kernel']);
        $this->assertSame('27.3.1', $envelope['docker']);
        $this->assertSame(PHP_VERSION, $envelope['php']);
        $this->assertSame('openlitespeed', $envelope['webserver']);
    }

    public function test_the_envelope_identifies_nobody(): void
    {
        // The whole reason the two methods are separate. A hostname, a public
        // IP or an account name here would make every batch attributable.
        $envelope = HostFacts::envelope($this->system([
            'ID' => 'AAAA:BBBB:CCCC',
            'Name' => 'engine-host',
            'OperatingSystem' => 'Ubuntu 24.04 LTS',
        ]));

        foreach (['hostname', 'machine_id', 'docker_id', 'public_ip', 'cpu_model', 'username'] as $key) {
            $this->assertArrayNotHasKey($key, $envelope, $key);
        }
        $this->assertStringNotContainsString('engine-host', (string) json_encode($envelope));
    }

    public function test_whether_sysbox_is_registered_is_reported(): void
    {
        // Sysbox is what makes the dind template viable, so when a whole
        // install's deploys fail this is the first thing worth knowing.
        $envelope = HostFacts::envelope($this->system(['Runtimes' => ['sysbox-runc' => [], 'runc' => []]]));

        $this->assertSame(['runc', 'sysbox-runc'], $envelope['runtimes']);
    }

    public function test_a_daemon_reporting_no_runtimes_reports_none(): void
    {
        $this->assertSame([], HostFacts::envelope($this->system([]))['runtimes']);
        $this->assertSame([], HostFacts::envelope($this->system(['Runtimes' => 'runc']))['runtimes']);
    }

    public function test_the_fingerprint_material_carries_what_identifies_the_install(): void
    {
        // Never transmitted; hashed into the install id.
        $material = HostFacts::forFingerprint($this->system([
            'ID' => 'AAAA:BBBB:CCCC',
            'Name' => 'engine-host',
        ]));

        $this->assertSame('AAAA:BBBB:CCCC', $material['docker_id']);
        $this->assertSame('engine-host', $material['hostname']);
        $this->assertArrayHasKey('machine_id', $material);
        $this->assertArrayHasKey('mem_total_kb', $material);
    }

    public function test_the_fingerprint_falls_back_to_this_containers_name(): void
    {
        // Better a stable wrong-ish value than none: the id has to be the
        // same on every run or every batch looks like a new install.
        $material = HostFacts::forFingerprint($this->system([]));

        $this->assertSame(gethostname(), $material['hostname']);
    }

    public function test_a_docker_daemon_that_cannot_be_reached_yields_nulls(): void
    {
        // The cron container has the socket but not much else, and an
        // exception here would take the cron run down with it.
        $envelope = HostFacts::envelope($this->system(null));

        $this->assertNull($envelope['os']);
        $this->assertNull($envelope['kernel']);
        $this->assertNull($envelope['docker']);
        $this->assertSame([], $envelope['runtimes']);
    }

    public function test_a_blank_value_is_reported_as_absent(): void
    {
        // `""` and "we could not read it" are the same thing to a reader, and
        // only one of them is honest.
        $envelope = HostFacts::envelope($this->system(['OperatingSystem' => '   ', 'KernelVersion' => '']));

        $this->assertNull($envelope['os']);
        $this->assertNull($envelope['kernel']);
    }

    public function test_the_daemon_is_asked_once_per_process(): void
    {
        // Both methods read the same call, and telemetry runs alongside every
        // deploy.
        $system = $this->system(['ID' => 'X'], $calls);
        HostFacts::envelope($system);
        HostFacts::forFingerprint($system);

        $this->assertSame(1, $calls);
    }

    public function test_the_cache_can_be_reset(): void
    {
        $system = $this->system(['ID' => 'X'], $calls);
        HostFacts::envelope($system);
        HostFacts::resetProbeCache();
        HostFacts::envelope($system);

        $this->assertSame(2, $calls);
    }

    public function test_the_hosts_own_hardware_is_read_from_proc(): void
    {
        // /proc/cpuinfo and /proc/meminfo are not namespaced, so they report
        // the real machine even from inside an unprivileged container.
        $envelope = HostFacts::envelope($this->system([]));

        $this->assertGreaterThan(0, $envelope['cpu_cores']);
        $this->assertGreaterThan(0, $envelope['memory_gb']);
    }
}
