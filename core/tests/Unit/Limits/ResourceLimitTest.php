<?php

namespace Tests\Unit\Limits;

use App\Lib\Limits\ResourceLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResourceLimitTest extends TestCase
{
    public function test_the_table_covers_every_limit_the_model_stores(): void
    {
        $this->assertSame([
            'disk_space_limit',
            'memory_limit',
            'cpu_limit',
            'device_read_bps',
            'device_write_bps',
            'bandwidth_limit',
            'mysql_databases_limit',
            'ftp_accounts_limit',
            'sftp_accounts_limit',
            'addon_domains_limit',
            'subdomains_limit',
            'inodes_limit',
        ], ResourceLimit::keys());
    }

    public function test_keys_and_options_are_unique_and_spelled_consistently(): void
    {
        $keys = ResourceLimit::keys();
        $options = array_map(fn (ResourceLimit $l): string => $l->option, ResourceLimit::all());

        $this->assertSame($keys, array_unique($keys));
        $this->assertSame($options, array_unique($options));

        foreach (ResourceLimit::all() as $limit) {
            $this->assertSame(
                str_replace('_', '-', $limit->key),
                $limit->option,
                "{$limit->key} and --{$limit->option} must be the same name"
            );
        }
    }

    public function test_by_key_finds_a_limit_and_rejects_an_unknown_one(): void
    {
        $this->assertSame('cpu_limit', ResourceLimit::byKey('cpu_limit')->key);

        $this->expectException(\InvalidArgumentException::class);
        ResourceLimit::byKey('nope_limit');
    }

    public function test_read_returns_null_for_an_absent_or_null_value(): void
    {
        $limit = ResourceLimit::byKey('memory_limit');

        $this->assertNull($limit->read([]));
        $this->assertNull($limit->read(['memory_limit' => null]));
    }

    public function test_read_casts_to_the_declared_type(): void
    {
        $this->assertSame(512, ResourceLimit::byKey('memory_limit')->read(['memory_limit' => '512']));
        $this->assertSame(1.5, ResourceLimit::byKey('cpu_limit')->read(['cpu_limit' => '1.5']));
    }

    public function test_only_cpu_is_a_float(): void
    {
        foreach (ResourceLimit::all() as $limit) {
            $this->assertSame(
                $limit->key === 'cpu_limit',
                $limit->isFloat,
                "{$limit->key} float-ness"
            );
        }
    }

    /**
     * The one asymmetry in the table, kept because it is what is already on
     * disk: disk_space_limit stores -1 for "no limit", everything else null.
     */
    public function test_only_disk_space_keeps_minus_one(): void
    {
        $this->assertSame(-1, ResourceLimit::byKey('disk_space_limit')->normalize('-1'));

        foreach (ResourceLimit::all() as $limit) {
            if ($limit->key === 'disk_space_limit') {
                continue;
            }
            $this->assertNull($limit->normalize('-1'), "{$limit->key} should store null for -1");
        }
    }

    public function test_normalize_clamps_below_minus_one(): void
    {
        $this->assertSame(-1, ResourceLimit::byKey('disk_space_limit')->normalize('-99'));
        $this->assertNull(ResourceLimit::byKey('bandwidth_limit')->normalize('-99'));
        $this->assertNull(ResourceLimit::byKey('cpu_limit')->normalize('-99'));
    }

    /**
     * Every project has a memory limit, so it is the one limit the table
     * refuses to take a clearing value for (#294).
     */
    public function test_memory_is_the_only_limit_that_cannot_be_cleared(): void
    {
        foreach (ResourceLimit::all() as $limit) {
            $this->assertSame(
                $limit->key === 'memory_limit',
                $limit->alwaysApplies,
                "{$limit->key} alwaysApplies"
            );
        }
    }

    public function test_a_non_positive_memory_limit_is_rejected(): void
    {
        $memory = ResourceLimit::byKey('memory_limit');

        $this->assertSame(
            'The memory limit is in MB and must be a positive number.',
            $memory->rejectionReason('-1')
        );
        $this->assertSame(
            'The memory limit is in MB and must be a positive number.',
            $memory->rejectionReason('0')
        );
        $this->assertNull($memory->rejectionReason('512'));
        $this->assertNull(ResourceLimit::byKey('bandwidth_limit')->rejectionReason('-1'));
    }

    public function test_normalize_keeps_a_real_value(): void
    {
        $this->assertSame(2048, ResourceLimit::byKey('memory_limit')->normalize('2048'));
        $this->assertSame(0, ResourceLimit::byKey('inodes_limit')->normalize('0'));
        $this->assertSame(1.5, ResourceLimit::byKey('cpu_limit')->normalize('1.5'));
    }

    #[DataProvider('formatting')]
    public function test_format_reads_back_the_way_the_cli_printed_it(
        string $key,
        int|float|null $value,
        string $expected
    ): void {
        $this->assertSame($expected, ResourceLimit::byKey($key)->format($value));
    }

    /** @return array<string, array{string, int|float|null, string}> */
    public static function formatting(): array
    {
        return [
            'disk null' => ['disk_space_limit', null, 'no limit'],
            'disk -1' => ['disk_space_limit', -1, 'no limit'],
            'disk value' => ['disk_space_limit', 1024, '1024 MB'],
            'memory value' => ['memory_limit', 512, '512 MB'],
            'cpu null' => ['cpu_limit', null, 'no limit'],
            'cpu value' => ['cpu_limit', 1.5, '1.5 CPUs'],
            'read bps' => ['device_read_bps', 1000, '1000 bps'],
            'write bps' => ['device_write_bps', 1000, '1000 bps'],
            'bandwidth' => ['bandwidth_limit', 50, '50 MB'],
            'bare count' => ['mysql_databases_limit', 5, '5'],
            'bare count null' => ['ftp_accounts_limit', null, 'no limit'],
            'inodes' => ['inodes_limit', 900, '900'],
        ];
    }

    public function test_rebuild_flags_match_what_the_runtime_bakes_in(): void
    {
        $needsRebuild = array_values(array_map(
            fn (ResourceLimit $l): string => $l->key,
            array_filter(ResourceLimit::all(), fn (ResourceLimit $l): bool => $l->needsRebuild)
        ));

        $this->assertSame([
            'disk_space_limit',
            'memory_limit',
            'cpu_limit',
            'device_read_bps',
            'device_write_bps',
            'inodes_limit',
        ], $needsRebuild);
    }
}
