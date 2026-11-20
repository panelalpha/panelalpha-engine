<?php

namespace Tests\Integration\Deploy\Dind;

use App\Lib\Deploy\Compose\ServiceLimits;
use App\Lib\Deploy\Dind\DindEngine;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\BuildMemory;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Host\HostMemory;
use App\Lib\Host\HostMemoryProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

/**
 * The host build container's memory ceiling, and Node being told about it.
 *
 * A build is a host resource -- a throwaway container on the engine, before
 * the project's own container exists -- so the operator sets it, not a
 * hosting plan. It was a constant, and a flat 2g is not enough for every
 * project: Chamilo 2.x's Encore build OOMs inside it on a 15 GB host and
 * succeeds at a 3584 MB heap, which our own 70% rule reaches in a ~5 GB
 * container. nextcloud/server's webpack pass is the same shape, because
 * TerserPlugin forks one minifier per CPU and their heaps sum past a flat
 * limit before any single one reaches its own cap.
 */
class BuildMemoryLimitTest extends TestCase
{
    /** @return array<string, mixed> */
    private function build(?string $configured): array
    {
        $account = new EngineAccount('demo', '/home/demo', '1001:1001');

        return LocalRoot::build((new DindHostBuilder($configured))->nodeBuildArgv(
            $account,
            'node:24-bookworm-slim',
            'npm ci',
            'npm run build'
        ));
    }

    /** @return array<string, string> */
    private function envFrom(array $build): array
    {
        return $build['environment'];
    }

    private function memoryFlag(array $build): ?string
    {
        return $build['memory'];
    }

    public function test_defaults_to_2g_when_nothing_usable_is_given(): void
    {
        $this->assertSame('2g', $this->memoryFlag($this->build(null)));
        $this->assertSame('2g', $this->memoryFlag($this->build('')));
    }

    public function test_the_operator_can_raise_it(): void
    {
        $this->assertSame('6144m', $this->memoryFlag($this->build('6g')));
    }

    /**
     * A unit-less number means megabytes, because that is what the docs' own
     * `512m, 2g, 4096m` examples lead an operator to write and what the
     * validator reads it as -- while `docker run --memory 4096` reads it as
     * 4096 *bytes* and refuses the container ("Minimum memory limit allowed is
     * 6MB"), failing every host build on the engine. So the value is re-emitted
     * in the unit it was understood in rather than passed through.
     */
    public function test_a_unit_less_number_reaches_docker_as_megabytes(): void
    {
        $this->assertSame('4096m', $this->memoryFlag($this->build('4096')));
        $this->assertSame('1024m', $this->memoryFlag($this->build('1024')));
    }

    /** A byte count, which is how a value this large can only be meant. */
    public function test_a_byte_count_is_understood(): void
    {
        $this->assertSame('4096m', $this->memoryFlag($this->build((string) (4096 * 1024 * 1024))));
    }

    /** No floor, so a small host is not handed more than it has. */
    public function test_a_small_value_is_kept(): void
    {
        $this->assertSame('512m', $this->memoryFlag($this->build('512m')));
        $this->assertSame('1024m', $this->memoryFlag($this->build('1g')));
    }

    /** Unset, a build gets 8 GB, held to half the host's RAM and its RAM less the engine's share. */
    #[DataProvider('hosts')]
    public function test_the_host_sizes_the_ceiling(HostMemory $host, string $limit): void
    {
        $memory = DindEngine::buildMemory('', $host);

        $this->assertSame([$limit, BuildMemory::HOST], [$memory->limit, $memory->source]);
    }

    public static function hosts(): array
    {
        return [
            '15 GB host gets half' => [HostMemoryProbe::fromReadings("MemTotal:       15728640 kB\n"), '7680m'],
            '16 GB host reaches 8 GB' => [new HostMemory(16384), '8192m'],
            '128 GB host gets the 8 GB cap' => [new HostMemory(131072), '8192m'],
            // A 3.8 GB host: half its RAM, not the old 2048 MB floor.
            '3.7 GB host' => [new HostMemory(3790), '1895m'],
            'a large engine share binds first' => [new HostMemory(2048, 1536), '512m'],
        ];
    }

    public function test_an_unreadable_host_leaves_the_builder_default(): void
    {
        $memory = DindEngine::buildMemory('', HostMemoryProbe::fromReadings(''));

        $this->assertSame('', $memory->limit);
        $this->assertSame(2048, (new DindHostBuilder($memory->limit, null, null, $memory))->memoryLimitMb());
    }

    /**
     * An install that never mentions DEPLOY_BUILD_MEMORY gets the host ceiling,
     * and one that sets it gets exactly what it set.
     */
    public function test_the_engine_reads_the_host_only_when_the_operator_has_not_spoken(): void
    {
        $host = new HostMemory(15360);

        $this->assertSame('7680m', DindEngine::buildMemory('', $host)->limit);
        $this->assertSame(BuildMemory::SETTING, DindEngine::buildMemory('6g', $host)->source);
        $this->assertSame('6g', DindEngine::buildMemory('6g', $host)->limit);
        // The operator may go past 8 GB, up to half the host.
        $this->assertSame('12g', DindEngine::buildMemory('12g', new HostMemory(32768))->limit);
        // Whitespace in .env is stripped, not pasted into `--memory`.
        $this->assertSame('6g', DindEngine::buildMemory('  6g  ', $host)->limit);
        // A typo falls back to the host figure rather than failing every deploy.
        $this->assertSame(['7680m', BuildMemory::HOST], [
            DindEngine::buildMemory('lots', $host)->limit,
            DindEngine::buildMemory('lots', $host)->source,
        ]);
    }

    /** Not even the operator's number goes past half the host. */
    public function test_the_setting_is_held_to_the_host_ceiling(): void
    {
        $memory = DindEngine::buildMemory('12g', new HostMemory(4096));

        $this->assertSame(['2048m', BuildMemory::SETTING_CAPPED], [$memory->limit, $memory->source]);
    }

    public function test_the_builder_gets_the_host_ceiling(): void
    {
        $request = DindEngine::builderFor('', new HostMemory(3790))->nodeBuildArgv(
            new EngineAccount('demo', '/home/demo', '1001:1001'),
            'node:24-bookworm-slim',
            'npm ci',
            'npm run build'
        );
        $build = LocalRoot::build($request);

        $this->assertSame('1895m', $this->memoryFlag($build));
        $this->assertSame([], LocalRoot::plain($request));
    }

    /**
     * Node sizes its heap from the cgroup and keeps well under it, so it has
     * to be told. If the flag and NODE_OPTIONS disagreed, raising the limit
     * would buy a bigger container that Node still refused to use.
     */
    public function test_the_node_heap_follows_the_configured_limit(): void
    {
        $small = $this->envFrom($this->build('2g'))['NODE_OPTIONS'] ?? '';
        $large = $this->envFrom($this->build('8g'))['NODE_OPTIONS'] ?? '';

        $this->assertStringContainsString('--max-old-space-size=', $small);
        $this->assertStringContainsString('--max-old-space-size=', $large);

        $toMb = static fn (string $o): int => (int) preg_replace('/\D+/', '', $o);
        $this->assertGreaterThan(
            $toMb($small),
            $toMb($large),
            'a larger container must give Node a larger heap'
        );
    }

    /**
     * The JVM is told about the cgroup for the same reason Node is, and more
     * sharply: HotSpot's `MaxRAMPercentage` defaults to 25, so a JVM left
     * alone takes a quarter of the container and a large Maven reactor dies
     * with three quarters of its memory unused. Alfresco Community's twelve
     * modules did exactly that inside the default 2g container.
     */
    public function test_the_jvm_heap_follows_the_configured_limit(): void
    {
        $small = $this->envFrom($this->build('2g'));
        $large = $this->envFrom($this->build('8g'));

        $heap = static function (string $value): int {
            preg_match('/-Xmx(\d+)m/', $value, $m);

            return (int) ($m[1] ?? 0);
        };

        $this->assertGreaterThan(0, $heap($small['JAVA_TOOL_OPTIONS'] ?? ''));
        $this->assertGreaterThan(
            $heap($small['JAVA_TOOL_OPTIONS'] ?? ''),
            $heap($large['JAVA_TOOL_OPTIONS'] ?? '')
        );
    }

    /**
     * The heap belongs in JAVA_TOOL_OPTIONS and nowhere else. `MAVEN_ARGS` is
     * Maven's own argument list, so an `-Xmx` there is rejected outright --
     * measured as `Unknown lifecycle phase "mx1433m"`, which fails every Java
     * deploy before Maven starts.
     */
    public function test_the_heap_is_not_put_where_maven_would_parse_it(): void
    {
        $env = $this->envFrom($this->build('2g'));

        $this->assertStringNotContainsString('-Xmx', $env['MAVEN_ARGS']);
        $this->assertSame('-Dmaven.repo.local=/var/cache/pa-js/m2', $env['MAVEN_ARGS']);
    }

    /**
     * The `maven:` entrypoint copies its settings into MAVEN_CONFIG, and /root/.m2 is not the account's to write.
     */
    public function test_maven_config_points_the_entrypoint_at_the_build_cache(): void
    {
        $env = $this->envFrom($this->build('2g'));

        $this->assertSame('/var/cache/pa-js/maven', $env['MAVEN_CONFIG'] ?? null);
    }

    /**
     * The heap and the container must not disagree, or the operator raising
     * `deploy.build_memory` buys a bigger container the JVM still refuses to
     * use -- the exact shape of the bug, one order of magnitude down.
     */
    public function test_every_toolchain_heap_is_inside_the_container(): void
    {
        $env = $this->envFrom($this->build('2g'));

        // 70% of 2048 MB, which is what the JVM and V8 both get -- and 2.8x
        // the 512 MB HotSpot's 25% default would have given the same
        // container.
        $this->assertSame('-Xmx1433m', $env['JAVA_TOOL_OPTIONS']);
        $this->assertSame('--max-old-space-size=1433', $env['NODE_OPTIONS']);
    }

    /**
     * Cargo's default is one job per CPU, and each job of a C++ `-sys` crate is
     * a compiler of its own. liwan's libduckdb-sys: 8 jobs, 5202m, cc1plus
     * OOM-killed at ~1.2 GB each, on a retest.
     */
    public function test_cargo_jobs_follow_the_configured_limit(): void
    {
        $this->assertSame('1', $this->envFrom($this->build('2g'))['CARGO_BUILD_JOBS'] ?? null);
        $jobs = (int) ($this->envFrom($this->build('5202m'))['CARGO_BUILD_JOBS'] ?? 0);

        $this->assertGreaterThanOrEqual(1, $jobs);
        $this->assertLessThanOrEqual(3, $jobs);
    }

    public function test_cargo_jobs_never_exceed_the_cpus_or_drop_below_one(): void
    {
        $this->assertSame(3, ServiceLimits::cargoJobsFor('5202m', 8));
        $this->assertSame(2, ServiceLimits::cargoJobsFor('8g', 2));
        $this->assertSame(5, ServiceLimits::cargoJobsFor('8g'));
        $this->assertSame(1, ServiceLimits::cargoJobsFor('1g', 8));
        $this->assertSame(1, ServiceLimits::cargoJobsFor('lots', 8));
    }

    /**
     * A JS build inside a *generated Dockerfile* runs in the account's daemon,
     * not in the engine's 2g build container, so `deploy.build_memory` is the
     * wrong ceiling for it -- the account's own limit is what can kill it.
     *
     * A heap bigger than the cgroup is worse than none: V8 reaches for memory
     * the kernel will not give, and the process dies with none of Node's own
     * diagnostics. Measured: the cap that prints `Reached heap limit` inside
     * `--memory 2g` prints nothing at all inside `--memory 512m`.
     */
    public function test_the_in_image_heap_is_sized_from_the_account_not_the_build_container(): void
    {
        // The number the generated Dockerfile would carry for a 2g account,
        // derived by the same rule the hardened services use.
        $this->assertSame(1433, ServiceLimits::nodeHeapMbForAccount(2048));
        $this->assertLessThan(
            ServiceLimits::nodeHeapMbForAccount(2048),
            ServiceLimits::nodeHeapMbForAccount(1024),
            'a smaller account must get a smaller heap, or the cap never binds'
        );
    }

    /**
     * An account with no memory limit has no cgroup to respect, and a cap of
     * our choosing would be smaller than the host-sized default Node already
     * takes -- so the honest answer is "no cap", not a guess.
     */
    public function test_an_account_with_no_limit_gets_no_cap(): void
    {
        $this->assertNull(ServiceLimits::nodeHeapMbForAccount(null));
        $this->assertNull(ServiceLimits::nodeHeapMbForAccount(0));
        $this->assertNull(ServiceLimits::nodeHeapMbForAccount(-1));
    }

    /**
     * A typo in one config key must not stop the host deploying anything.
     */
    #[DataProvider('unusable')]
    public function test_a_value_docker_would_reject_falls_back(?string $configured): void
    {
        $this->assertSame('2g', $this->memoryFlag($this->build($configured)));
    }

    public static function unusable(): array
    {
        return [
            'empty' => [''],
            'null' => [null],
            'whitespace' => ['   '],
            'not a size' => ['lots'],
            'shell injection' => ['2g; rm -rf /'],
            'missing number' => ['g'],
        ];
    }

    /** What the deploy log reports is what `--memory` gets. */
    public function test_the_reported_limit_matches_the_container_flag(): void
    {
        foreach (['5202m' => 5202, '6g' => 6144, '4096' => 4096, '1g' => 1024, 'lots' => 2048] as $configured => $mb) {
            $builder = new DindHostBuilder((string) $configured);
            $this->assertSame($mb, $builder->memoryLimitMb(), $configured);
            $this->assertSame($mb, ServiceLimits::toMegabytes($this->memoryFlag($this->build((string) $configured))), $configured);
        }
    }
}
