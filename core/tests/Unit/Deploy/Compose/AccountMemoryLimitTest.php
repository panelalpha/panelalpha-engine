<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Compose\ServiceHardener;
use App\Lib\Deploy\Compose\ServiceLimits;
use PHPUnit\Framework\TestCase;

/**
 * The project's own memory limit reaches the containers inside it.
 *
 * `memory_limit` on an account already capped the account's *own* container
 * and stopped there, so raising it did nothing for the application running
 * inside — every compose service was pinned to a hard-coded 512m regardless.
 *
 * Wekan is what that cost: OOM-killed every 3.8 seconds, `OOMKilled: true`,
 * exit 137, restarting indefinitely, on a host with 5.9 GB free and an
 * account with no limit set at all. There was no setting anywhere that could
 * have changed it.
 *
 * Handing the same figure to several services is not additive: the account's
 * own container carries that limit too, so it is a parent cgroup and the sum
 * inside cannot exceed it.
 */
class AccountMemoryLimitTest extends TestCase
{
    public function test_the_accounts_limit_replaces_the_built_in_default(): void
    {
        $this->assertSame('512m', ServiceLimits::memoryFor('wekan', []), 'the default when nothing is set');
        $this->assertSame('1792m', ServiceLimits::memoryFor('wekan', [], 2048), 'the budget minus headroom');
    }

    /** Down as well as up: the number means how big this account may be. */
    public function test_it_lowers_as_well_as_raises(): void
    {
        $this->assertSame('192m', ServiceLimits::memoryFor('wekan', [], 256));
    }

    /**
     * A service never gets the whole account budget. The account's own
     * container carries the same limit, and dockerd, the sidecars and the
     * page cache for all of them live inside it — so an application handed
     * 100% is one that can starve the daemon supervising it.
     *
     * With Wekan at 2048 MB before this, the app never touched its own ceiling
     * (`memory.events: max 0`) while the account cgroup sat at exactly
     * 2147491840 bytes with 2500 reclaim events.
     */
    public function test_a_service_never_gets_the_whole_account_budget(): void
    {
        foreach ([512, 1024, 2048, 4096, 8192] as $accountMb) {
            $service = (int) rtrim(ServiceLimits::memoryFor('wekan', [], $accountMb), 'm');

            $this->assertLessThan($accountMb, $service, "{$accountMb} MB account left no headroom");
        }
    }

    /** The headroom is bounded, so a large account is not taxed for it. */
    public function test_the_reserve_is_capped(): void
    {
        $this->assertSame('7680m', ServiceLimits::memoryFor('wekan', [], 8192), '512 MB reserve, not an eighth');
    }

    /**
     * The floor is small on purpose. A 128 MB floor took a quarter of a
     * 512 MB project and left the application with *less* than the 512m it
     * gets when no limit is set at all — a surprising thing to hand someone
     * who has just raised a limit.
     */
    public function test_a_small_project_is_not_taxed_a_quarter(): void
    {
        $this->assertSame('448m', ServiceLimits::memoryFor('wekan', [], 512));
        $this->assertSame('896m', ServiceLimits::memoryFor('wekan', [], 1024));
    }

    /** Wekan's measured peak was 1.459 GiB; 2 GB has to clear it. */
    public function test_a_2gb_account_clears_wekans_measured_peak(): void
    {
        $service = (int) rtrim(ServiceLimits::memoryFor('wekan', [], 2048), 'm');

        $this->assertGreaterThan(1494, $service, 'must exceed the 1.459 GiB peak actually observed');
    }

    /** A named application role was capped tighter; the account still wins. */
    public function test_it_overrides_the_capped_role_default(): void
    {
        $this->assertSame('384m', ServiceLimits::memoryFor('worker', []));
        $this->assertSame('3584m', ServiceLimits::memoryFor('worker', [], 4096));
    }

    /**
     * A datastore keeps its catalogued size. Those are chosen for the engine
     * in question, and the application's headroom is not theirs to take.
     */
    public function test_a_catalogued_datastore_is_unaffected(): void
    {
        $withLimit = ServiceLimits::memoryFor('db', ['image' => 'postgres:16'], 4096);

        $this->assertNotSame('3584m', $withLimit);
        $this->assertSame(ServiceLimits::memoryFor('db', ['image' => 'postgres:16']), $withLimit);
    }

    /** Nothing set is the behaviour that was there before. */
    public function test_no_limit_keeps_the_previous_defaults(): void
    {
        $this->assertSame(
            ServiceLimits::memoryFor('wekan', []),
            ServiceLimits::memoryFor('wekan', [], null)
        );
        $this->assertSame('512m', ServiceLimits::memoryFor('wekan', [], 0), 'zero is not a limit');
    }

    /** It reaches the hardened service, which is the point of the change. */
    public function test_the_hardened_service_carries_it(): void
    {
        $hardened = ServiceHardener::harden('wekan', ['image' => 'ghcr.io/wekan/wekan:latest'], 2048);

        $this->assertSame('1792m', $hardened['mem_limit']);
    }

    /** And the Node heap cap is derived from the limit actually applied. */
    public function test_the_node_heap_follows_the_raised_limit(): void
    {
        $small = ServiceHardener::harden('app', ['image' => 'node:22'], null);
        $large = ServiceHardener::harden('app', ['image' => 'node:22'], 2048);

        $this->assertStringContainsString('--max-old-space-size=', implode(' ', (array) $small['environment']));
        $heap = static function (array $service): int {
            preg_match('/--max-old-space-size=(\d+)/', implode(' ', (array) $service['environment']), $m);

            return (int) ($m[1] ?? 0);
        };
        $this->assertGreaterThan($heap($small), $heap($large), 'a bigger container gets a bigger heap');
    }

    /** The whole file is hardened with it, not just one service. */
    public function test_it_applies_across_the_compose_file(): void
    {
        $compose = ComposeHarden::apply([
            'services' => [
                'wekan' => ['image' => 'ghcr.io/wekan/wekan:latest'],
                'ferretdb' => ['image' => 'ghcr.io/ferretdb/ferretdb:1'],
            ],
        ], 2048);

        $this->assertSame('1792m', $compose['services']['wekan']['mem_limit']);
    }
}
