<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ServiceLimits;
use PHPUnit\Framework\TestCase;

/**
 * How much memory each service gets, and how much of it Node may use.
 *
 * The heap cap is the one that bites: without it Node sizes its heap from the
 * host's total memory, so a container limited to 384m is killed by the kernel
 * before V8 ever decides a collection is due. The symptom is an exit 137 with
 * no application error anywhere, on a build that worked on the developer's
 * laptop.
 */
class ServiceLimitsTest extends TestCase
{
    public function test_a_catalogued_engine_gets_the_limit_its_entry_names(): void
    {
        $this->assertSame('512m', ServiceLimits::memoryFor('db', ['image' => 'postgres:16']));
    }

    public function test_the_engine_is_recognised_however_the_service_is_named(): void
    {
        $this->assertSame('512m', ServiceLimits::memoryFor('storage', ['image' => 'mysql:8']));
    }

    public function test_a_datastore_pinning_a_larger_heap_gets_twice_the_heap(): void
    {
        $service = ['image' => 'docker.elastic.co/elasticsearch/elasticsearch:9.3.0', 'environment' => ['ES_JAVA_OPTS=-Xms512m -Xmx512m']];

        $this->assertSame('1024m', ServiceLimits::memoryFor('elasticsearch', $service));
    }

    public function test_the_heap_is_read_from_a_map_and_through_an_unset_default(): void
    {
        $opensearch = ['image' => 'opensearchproject/opensearch:2.9.0', 'environment' => ['OPENSEARCH_JAVA_OPTS' => '-Xms1g -Xmx1g']];
        $defaulted = ['image' => 'elasticsearch:8.17.0', 'environment' => ['ES_JAVA_OPTS' => '${ES_JAVA_OPTS:--Xms512m -Xmx512m}']];

        $this->assertSame('2048m', ServiceLimits::memoryFor('search', $opensearch));
        $this->assertSame('1024m', ServiceLimits::memoryFor('es', $defaulted));
    }

    public function test_a_small_pinned_heap_keeps_the_catalogue_limit(): void
    {
        $service = ['image' => 'elasticsearch:8.13.0', 'environment' => ['ES_JAVA_OPTS' => '-Xms128m -Xmx128m']];

        $this->assertSame('384m', ServiceLimits::memoryFor('elasticsearch', $service));
    }

    public function test_an_unpinned_or_unresolvable_heap_changes_nothing(): void
    {
        $this->assertSame('384m', ServiceLimits::memoryFor('elasticsearch', ['image' => 'elasticsearch:8.13.0']));
        $this->assertSame('384m', ServiceLimits::memoryFor('elasticsearch', [
            'image' => 'opensearchproject/opensearch:3.7.0', 'environment' => ['OPENSEARCH_JAVA_OPTS' => '${OPENSEARCH_JAVA_OPTS}'],
        ]));
    }

    public function test_an_application_role_is_capped_below_the_default(): void
    {
        // Several of these run per account - web, worker, scheduler - so the
        // per-service default has to leave room for the rest of them.
        foreach (['app', 'web', 'worker', 'horizon', 'scheduler', 'queue'] as $name) {
            $this->assertSame('384m', ServiceLimits::memoryFor($name, ['image' => 'acme/app']), $name);
        }
    }

    public function test_the_role_is_matched_case_insensitively(): void
    {
        $this->assertSame('384m', ServiceLimits::memoryFor('Worker', ['image' => 'acme/app']));
    }

    public function test_anything_unrecognised_gets_the_default(): void
    {
        $this->assertSame('512m', ServiceLimits::memoryFor('acme-thing', ['image' => 'acme/thing']));
    }

    public function test_the_heap_leaves_the_runtime_room_to_live_in(): void
    {
        // 70% of the container, so the rest of the process - buffers, native
        // modules, the runtime itself - is not competing with the heap.
        $this->assertSame(358, ServiceLimits::nodeHeapMbFor('512m'));
        $this->assertSame(268, ServiceLimits::nodeHeapMbFor('384m'));
    }

    public function test_the_heap_never_reaches_the_container_limit(): void
    {
        // Two bounds, whichever is tighter: 70% of the limit, and the limit
        // less 64m of headroom. Below ~213m the headroom binds; above it the
        // share does. Neither ever equals the limit.
        foreach (['256m', '384m', '512m', '1g', '2g'] as $limit) {
            $heap = ServiceLimits::nodeHeapMbFor($limit);

            $this->assertLessThan(ServiceLimits::toMegabytes($limit), $heap, $limit);
        }
        $this->assertSame(1433, ServiceLimits::nodeHeapMbFor('2g'));
    }

    public function test_a_small_container_still_gets_a_workable_heap(): void
    {
        // Below the floor, 70% would leave Node unable to start at all, so
        // the floor wins - and at 128m and under it equals or exceeds the
        // limit, which is why memoryFor() never hands out anything that small.
        $this->assertSame(128, ServiceLimits::nodeHeapMbFor('128m'));
        $this->assertSame(128, ServiceLimits::nodeHeapMbFor('64m'));
        $this->assertGreaterThanOrEqual(
            192,
            ServiceLimits::toMegabytes(ServiceLimits::memoryFor('worker', ['image' => 'acme/app']))
        );
    }

    public function test_an_unreadable_limit_falls_back_to_a_safe_heap(): void
    {
        $this->assertSame(268, ServiceLimits::nodeHeapMbFor(null));
        $this->assertSame(268, ServiceLimits::nodeHeapMbFor('lots'));
    }

    public function test_compose_size_suffixes_are_understood(): void
    {
        $this->assertSame(512, ServiceLimits::toMegabytes('512m'));
        $this->assertSame(512, ServiceLimits::toMegabytes('512M'));
        $this->assertSame(512, ServiceLimits::toMegabytes('512mb'));
        $this->assertSame(512, ServiceLimits::toMegabytes('512MiB'));
        $this->assertSame(1024, ServiceLimits::toMegabytes('1g'));
        $this->assertSame(1, ServiceLimits::toMegabytes('1024k'));
    }

    public function test_a_fractional_size_is_rounded_down(): void
    {
        // Rounding up would set a limit above what the account was given.
        $this->assertSame(1536, ServiceLimits::toMegabytes('1.5g'));
        $this->assertSame(1, ServiceLimits::toMegabytes('1.9m'));
    }

    public function test_a_size_below_one_megabyte_is_still_a_megabyte(): void
    {
        $this->assertSame(1, ServiceLimits::toMegabytes('100k'));
    }

    public function test_a_bare_number_is_read_as_bytes(): void
    {
        // Compose's own form, and what the API reports back.
        $this->assertSame(512, ServiceLimits::toMegabytes(536870912));
        $this->assertSame(512, ServiceLimits::toMegabytes('536870912'));
    }

    public function test_a_bare_number_below_a_megabyte_is_read_as_megabytes(): void
    {
        // Nobody limits a container to 384 bytes; they mean 384m.
        $this->assertSame(384, ServiceLimits::toMegabytes(384));
    }

    public function test_an_unreadable_limit_is_reported_as_such(): void
    {
        $this->assertNull(ServiceLimits::toMegabytes(null));
        $this->assertNull(ServiceLimits::toMegabytes('unlimited'));
        $this->assertNull(ServiceLimits::toMegabytes(0));
        $this->assertNull(ServiceLimits::toMegabytes(-1));
        $this->assertNull(ServiceLimits::toMegabytes('512p'));
    }

    public function test_a_limit_above_the_account_is_held_to_what_the_account_leaves_its_services(): void
    {
        $this->assertSame('3584m', ServiceLimits::heldToAccount('8g', 4096));
        $this->assertSame('3584m', ServiceLimits::heldToAccount(8589934592, 4096));
        $this->assertSame('3584m', ServiceLimits::heldToAccount('4g', 4096));
    }

    public function test_a_limit_within_the_account_is_kept_as_written(): void
    {
        $this->assertSame('3584m', ServiceLimits::heldToAccount('3584m', 4096));
        $this->assertSame('1G', ServiceLimits::heldToAccount('1G', 4096));
        $this->assertSame(536870912, ServiceLimits::heldToAccount(536870912, 4096));
    }

    public function test_a_limit_is_kept_when_there_is_nothing_to_hold_it_to(): void
    {
        $this->assertSame('8g', ServiceLimits::heldToAccount('8g', null));
        $this->assertSame('8g', ServiceLimits::heldToAccount('8g', 0));
        $this->assertSame('${APP_MEMORY:-8g}', ServiceLimits::heldToAccount('${APP_MEMORY:-8g}', 4096));
    }
}
