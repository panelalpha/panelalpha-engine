<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\ComposeLogLayout;
use App\Lib\Deploy\DeployLog\DependencyFailure;
use PHPUnit\Framework\TestCase;

/**
 * Container tails laid out as `docker compose logs` without `-t` prints them: `name | line`, the name column padded
 * to the longest name plus one.
 */
class ComposeLogLayoutTest extends TestCase
{
    public function test_one_container_is_its_lines_under_its_name_without_their_times(): void
    {
        $this->assertSame(
            "project-db-1  | starting\nproject-db-1  | Error: no space left\n",
            ComposeLogLayout::of([['project-db-1', "2026-11-10T08:00:00.000000001Z starting\n2026-11-10T08:00:01.000000000Z Error: no space left\n"]])
        );
    }

    public function test_the_name_column_is_padded_to_the_longest_name(): void
    {
        $this->assertSame(
            "project-worker-1   | a\nproject-worker-10  | b\n",
            ComposeLogLayout::of([
                ['project-worker-1', "2026-11-10T08:00:00.000000001Z a\n"],
                ['project-worker-10', "2026-11-10T08:00:00.000000002Z b\n"],
            ])
        );
    }

    public function test_the_containers_lines_are_interleaved_in_the_order_they_were_logged(): void
    {
        $this->assertSame(
            "web-2  | booting\nweb-1  | booting\nweb-2  | listening\nweb-1  | Error: port taken\n",
            ComposeLogLayout::of([
                ['web-1', "2026-11-10T08:00:00.500000000Z booting\n2026-11-10T08:00:03.000000000Z Error: port taken\n"],
                ['web-2', "2026-11-10T08:00:00.100000000Z booting\n2026-11-10T08:00:02.000000000Z listening\n"],
            ])
        );
    }

    public function test_lines_logged_at_the_same_time_keep_the_order_of_the_containers_and_their_own(): void
    {
        $this->assertSame(
            "a-1  | one\na-1  | two\nb-1  | three\n",
            ComposeLogLayout::of([
                ['a-1', "2026-11-10T08:00:00.000000000Z one\n2026-11-10T08:00:00.000000000Z two\n"],
                ['b-1', "2026-11-10T08:00:00.000000000Z three\n"],
            ])
        );
    }

    public function test_a_line_the_daemon_did_not_time_goes_with_the_line_before_it(): void
    {
        $this->assertSame(
            "a-1  | 50%\na-1  | 100%\nb-1  | done\n",
            ComposeLogLayout::of([
                ['a-1', "2026-11-10T08:00:00.000000000Z 50%\r100%\n"],
                ['b-1', "2026-11-10T08:00:01.000000000Z done\n"],
            ])
        );
    }

    public function test_an_empty_line_keeps_its_place_and_a_silent_container_adds_nothing(): void
    {
        $this->assertSame(
            "app-1  | \napp-1  | up\n",
            ComposeLogLayout::of([
                ['app-1', "2026-11-10T08:00:00.000000000Z \n2026-11-10T08:00:01.000000000Z up\n"],
                ['app-2', ''],
            ])
        );
        $this->assertSame('', ComposeLogLayout::of([]));
    }

    /** The cause read from the layout is the newest error of any container, without compose's prefix. */
    public function test_the_dependency_failure_reads_its_cause_from_the_layout(): void
    {
        $logs = ComposeLogLayout::of([
            ['project-migrate-1', "2026-11-10T08:00:05.000000000Z Error: no database\n"],
            ['project-migrate-10', "2026-11-10T08:00:01.000000000Z waiting\n2026-11-10T08:00:09.000000000Z exiting\n"],
        ]);

        $this->assertSame("Error: no database\nexiting", DependencyFailure::cause($logs));
    }
}
