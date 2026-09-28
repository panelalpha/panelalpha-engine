<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Compose\ServiceLimits;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Dind\DindEngine;
use App\Lib\Deploy\Engine\BuildMemory;
use App\Lib\Deploy\Engine\EngineAccount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
    private function argv(?string $configured): array
    {
        $account = new EngineAccount('demo', '/home/demo', '1001:1001');

        return (new DindHostBuilder($configured))->nodeBuildArgv(
            $account,
            'node:24-bookworm-slim',
            'npm ci',
            'npm run build'
        );
    }

    /** @return array<string, string> */
    private function envFrom(array $argv): array
    {
        $env = [];
        foreach ($argv as $i => $arg) {
            if ($arg === '-e' && isset($argv[$i + 1]) && str_contains((string) $argv[$i + 1], '=')) {
                [$k, $v] = explode('=', (string) $argv[$i + 1], 2);
                $env[$k] = $v;
            }
        }

        return $env;
    }

    private function memoryFlag(array $argv): ?string
    {
        foreach ($argv as $i => $arg) {
            if ($arg === '--memory') {
                return $argv[$i + 1] ?? null;
            }
        }

        return null;
    }

    public function test_defaults_to_the_floor_when_nothing_usable_is_given(): void
    {
        $this->assertSame('2g', $this->memoryFlag($this->argv(null)));
        $this->assertSame('2g', $this->memoryFlag($this->argv('')));
    }

    public function test_the_operator_can_raise_it(): void
    {
        $this->assertSame('6144m', $this->memoryFlag($this->argv('6g')));
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
        $this->assertSame('4096m', $this->memoryFlag($this->argv('4096')));
        $this->assertSame('2g', $this->memoryFlag($this->argv('1024')));
    }

    /** A byte count, which is how a value this large can only be meant. */
    public function test_a_byte_count_is_understood(): void
    {
        $this->assertSame('4096m', $this->memoryFlag($this->argv((string) (4096 * 1024 * 1024))));
    }

    /**
     * A build container smaller than any build needs is not a hosting plan
     * being enforced, it is a deploy that cannot finish. The floor is not
     * negotiable from here; an operator who wants smaller is asking for
     * something this engine cannot give them.
     */
    public function test_a_value_below_the_floor_is_raised_to_it(): void
    {
        $this->assertSame('2g', $this->memoryFlag($this->argv('512m')));
        $this->assertSame('2g', $this->memoryFlag($this->argv('1g')));
        $this->assertSame('2g', $this->memoryFlag($this->argv('2047m')));
    }

    /**
     * The ceiling is sized from the machine when nothing is configured, because
     * a flat default cannot be right on a 4 GB VPS and a 128 GB box at once.
     * Measured on the 15 GB host this was diagnosed on: 2g/1433 MB heap OOMs
     * Chamilo 2.x's Encore build and ~6g/4300 MB builds it.
     */
    public function test_the_host_sizes_the_ceiling_when_nothing_is_configured(): void
    {
        // One third of the host, so 15 GB -> 5120m. That is the container
        // Chamilo needs; Node then gets 70% of it, 3584 MB, which is the heap
        // its maintainers measured as the smallest that compiles.
        $this->assertSame(5120, ServiceLimits::hostBuildMemoryMb("MemTotal:       15728640 kB\n"));
    }

    public function test_a_small_host_keeps_the_floor(): void
    {
        // A third of 4 GB is 1365m; the floor wins, because a build smaller
        // than 2g is a deploy that cannot finish.
        $this->assertSame(2048, ServiceLimits::hostBuildMemoryMb("MemTotal:        4194304 kB\n"));
        $this->assertSame(2048, ServiceLimits::hostBuildMemoryMb("MemTotal:         524288 kB\n"));
    }

    public function test_a_large_host_is_capped_until_the_operator_says_otherwise(): void
    {
        // Past 8g one build may not take a third of the machine on its own;
        // the operator sets DEPLOY_BUILD_MEMORY when it should.
        $this->assertSame(8192, ServiceLimits::hostBuildMemoryMb("MemTotal:      134217728 kB\n"));
        $this->assertSame(8192, ServiceLimits::hostBuildMemoryMb("MemTotal:      999999999 kB\n"));
    }

    public function test_an_unreadable_host_falls_back_to_the_floor(): void
    {
        $this->assertSame(2048, ServiceLimits::hostBuildMemoryMb(''));
        $this->assertSame(2048, ServiceLimits::hostBuildMemoryMb("SwapTotal: 0 kB\n"));
        $this->assertSame(2048, ServiceLimits::hostBuildMemoryMb("MemTotal: nonsense\n"));
    }

    /**
     * The wiring, not just the arithmetic: an install that never mentions
     * DEPLOY_BUILD_MEMORY gets the host-sized ceiling, and one that sets it
     * gets exactly what it set -- never a silent blend of the two.
     */
    public function test_the_engine_reads_the_host_only_when_the_operator_has_not_spoken(): void
    {
        $meminfo = "MemTotal:       15728640 kB\n"; // 15 GB

        $this->assertSame('5120m', DindEngine::resolveBuildMemory('', $meminfo));
        $this->assertSame('6g', DindEngine::resolveBuildMemory('6g', $meminfo));
        // A typo is the builder's problem, not the precedence's: it still wins
        // here rather than being quietly replaced by a host number.
        $this->assertSame('lots', DindEngine::resolveBuildMemory('lots', $meminfo));
        // Whitespace in .env is stripped, not pasted into `--memory`.
        $this->assertSame('6g', DindEngine::resolveBuildMemory('  6g  ', $meminfo));
    }

    /**
     * Node sizes its heap from the cgroup and keeps well under it, so it has
     * to be told. If the flag and NODE_OPTIONS disagreed, raising the limit
     * would buy a bigger container that Node still refused to use.
     */
    public function test_the_node_heap_follows_the_configured_limit(): void
    {
        $small = $this->envFrom($this->argv('2g'))['NODE_OPTIONS'] ?? '';
        $large = $this->envFrom($this->argv('8g'))['NODE_OPTIONS'] ?? '';

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
        $small = $this->envFrom($this->argv('2g'));
        $large = $this->envFrom($this->argv('8g'));

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
        $env = $this->envFrom($this->argv('2g'));

        $this->assertStringNotContainsString('-Xmx', $env['MAVEN_ARGS']);
        $this->assertSame('-Dmaven.repo.local=/var/cache/pa-js/m2', $env['MAVEN_ARGS']);
    }

    /**
     * The heap and the container must not disagree, or the operator raising
     * `deploy.build_memory` buys a bigger container the JVM still refuses to
     * use -- the exact shape of the bug, one order of magnitude down.
     */
    public function test_every_toolchain_heap_is_inside_the_container(): void
    {
        $env = $this->envFrom($this->argv('2g'));

        // 70% of 2048 MB, which is what the JVM and V8 both get -- and 2.8x
        // the 512 MB HotSpot's 25% default would have given the same
        // container.
        $this->assertSame('-Xmx1433m', $env['JAVA_TOOL_OPTIONS']);
        $this->assertSame('--max-old-space-size=1433', $env['NODE_OPTIONS']);
    }

    /**
     * Cargo's default is one job per CPU, and each job of a C++ `-sys` crate is
     * a compiler of its own. liwan's libduckdb-sys: 8 jobs, 5202m, cc1plus
     * OOM-killed at ~1.2 GB each (engine#104 retest).
     */
    public function test_cargo_jobs_follow_the_configured_limit(): void
    {
        $this->assertSame('1', $this->envFrom($this->argv('2g'))['CARGO_BUILD_JOBS'] ?? null);
        $jobs = (int) ($this->envFrom($this->argv('5202m'))['CARGO_BUILD_JOBS'] ?? 0);

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
        $this->assertSame('2g', $this->memoryFlag($this->argv($configured)));
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
    /** engine#184: what the deploy log reports is what `--memory` gets. */
    public function test_the_reported_limit_matches_the_container_flag(): void
    {
        foreach (['5202m' => 5202, '6g' => 6144, '4096' => 4096, '1g' => 2048, 'lots' => 2048] as $configured => $mb) {
            $builder = new DindHostBuilder((string) $configured);
            $this->assertSame($mb, $builder->memoryLimitMb(), $configured);
            $this->assertSame($mb, ServiceLimits::toMegabytes($this->memoryFlag($this->argv((string) $configured))), $configured);
        }
    }

    /**
     * engine#184, option B: the project's memory limit raises the build and
     * never lowers it, up to half the server; `DEPLOY_BUILD_MEMORY` wins.
     */
    #[DataProvider('projectLimits')]
    public function test_a_project_limit_only_ever_raises_the_build(
        string $configured,
        string $meminfo,
        ?int $projectMb,
        string $limit,
        string $source
    ): void {
        $memory = DindEngine::buildMemory($configured, $meminfo, $projectMb);

        $this->assertSame([$limit, $source], [$memory->limit, $memory->source]);
        $this->assertSame($limit, DindEngine::resolveBuildMemory($configured, $meminfo, $projectMb));
        $this->assertSame(
            ServiceLimits::toMegabytes($limit) < 2048 ? '2g' : ServiceLimits::toMegabytes($limit) . 'm',
            $this->memoryFlag(DindEngine::builderFor($configured, $meminfo, $projectMb)->nodeBuildArgv(
                new EngineAccount('demo', '/home/demo', '1001:1001'),
                'node:24-bookworm-slim',
                'npm ci',
                'npm run build'
            ))
        );
    }

    public static function projectLimits(): array
    {
        $host16 = "MemTotal:       15983292 kB\n"; // share 5202, half 7804
        $host6 = "MemTotal:        6291456 kB\n";  // share 2048 (floor), half 3072
        $host4 = "MemTotal:        4194304 kB\n";  // share 2048, half 2048

        return [
            'no plan' => ['', $host16, null, '5202m', BuildMemory::SERVER],
            'unlimited plan' => ['', $host16, 0, '5202m', BuildMemory::SERVER],
            'plan below the share is not a cut' => ['', $host16, 2000, '5202m', BuildMemory::SERVER],
            'plan equal to the share' => ['', $host16, 5202, '5202m', BuildMemory::SERVER],
            'plan above the share raises it' => ['', $host16, 7000, '7000m', BuildMemory::PROJECT],
            'plan above half the server is capped' => ['', $host16, 12000, '7804m', BuildMemory::PROJECT_CAPPED],
            // Shopware's administration build needs 2800 MB.
            '6 GB host, a 2800 MB plan builds Shopware admin' => ['', $host6, 2800, '2800m', BuildMemory::PROJECT],
            '6 GB host, capped at 3072' => ['', $host6, 8000, '3072m', BuildMemory::PROJECT_CAPPED],
            '4 GB host has no room above the floor' => ['', $host4, 8000, '2048m', BuildMemory::SERVER],
            'unreadable host never sizes by plan' => ['', '', 8000, '2048m', BuildMemory::SERVER],
            'operator setting wins over a larger plan' => ['3g', $host16, 7000, '3g', BuildMemory::SETTING],
            'operator setting wins over a smaller plan' => ['12g', $host16, 1000, '12g', BuildMemory::SETTING],
        ];
    }

    public function test_the_log_can_name_both_figures(): void
    {
        $memory = DindEngine::buildMemory('', "MemTotal:       15983292 kB\n", 12000);

        $this->assertSame(5202, $memory->serverShareMb);
        $this->assertSame(12000, $memory->projectLimitMb);
        $this->assertSame(7804, ServiceLimits::projectBuildMemoryCapMb("MemTotal:       15983292 kB\n"));
        $this->assertNull(ServiceLimits::projectBuildMemoryCapMb(''));
    }
}
