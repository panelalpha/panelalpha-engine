<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployLogReader;
use PHPUnit\Framework\TestCase;

/**
 * Parsed views over one deploy's JSON-lines log.
 *
 * Three callers want three different windows: the panel pages forward from an
 * offset it remembers, telemetry wants the other end and does not know the
 * total, and the build breakdown needs the whole file - a `#N [x/y] <cmd>`
 * and its `#N DONE <s>` can be thousands of lines apart, and a window that
 * split the two would report the step as costing nothing.
 */
class DeployLogReaderTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir() . '/pa-log-' . bin2hex(random_bytes(8)) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    private function write(string ...$lines): DeployLogReader
    {
        file_put_contents($this->path, implode("\n", $lines) . "\n");

        return new DeployLogReader($this->path);
    }

    private function entry(int $ts, string $msg, string $stage = 'building', string $level = 'info'): string
    {
        return (string) json_encode(['ts' => $ts, 'stage' => $stage, 'level' => $level, 'msg' => $msg]);
    }

    public function test_every_entry_is_returned_in_order(): void
    {
        $reader = $this->write($this->entry(100, 'first'), $this->entry(200, 'second'));

        $this->assertSame([
            ['ts' => 100, 'level' => 'info', 'msg' => 'first'],
            ['ts' => 200, 'level' => 'info', 'msg' => 'second'],
        ], $reader->entries());
    }

    public function test_a_page_starts_where_the_caller_left_off(): void
    {
        $reader = $this->write(
            $this->entry(100, 'a'),
            $this->entry(200, 'b'),
            $this->entry(300, 'c')
        );

        $page = $reader->page(1, 10);

        $this->assertSame(['b', 'c'], array_column($page['lines'], 'msg'));
    }

    public function test_a_page_reports_where_to_resume_from(): void
    {
        // The end of this page, not the end of the log: jumping to the total
        // skipped every line between the two for good. A page that reaches
        // the end is the same number either way, so a poll that asks again
        // still gets only what was appended since.
        $reader = $this->write($this->entry(100, 'a'), $this->entry(200, 'b'), $this->entry(300, 'c'));

        $this->assertSame(['next_offset' => 1, 'more' => true], array_diff_key($reader->page(0, 1), ['lines' => 0]));
        $this->assertSame(['next_offset' => 3, 'more' => false], array_diff_key($reader->page(2, 10), ['lines' => 0]));
    }

    public function test_paging_until_there_is_no_more_reads_every_line_once(): void
    {
        $reader = $this->write(...array_map(fn (int $i): string => $this->entry($i, "line {$i}"), range(1, 7)));

        $seen = [];
        $offset = 0;
        do {
            $page = $reader->page($offset, 3);
            array_push($seen, ...array_column($page['lines'], 'msg'));
            $offset = $page['next_offset'];
        } while ($page['more']);

        $this->assertSame(array_map(fn (int $i): string => "line {$i}", range(1, 7)), $seen);
    }

    public function test_a_byte_budget_ends_a_page_early(): void
    {
        $reader = $this->write($this->entry(100, str_repeat('a', 600)), $this->entry(200, str_repeat('b', 600)), $this->entry(300, 'c'));

        $page = $reader->page(0, 10, 1024);

        $this->assertCount(1, $page['lines']);
        $this->assertSame(1, $page['next_offset']);
        $this->assertTrue($page['more']);
    }

    public function test_a_line_bigger_than_the_budget_is_cut_rather_than_stalling_the_reader(): void
    {
        $reader = $this->write($this->entry(100, str_repeat('x', 5000)), $this->entry(200, 'next'));

        $page = $reader->page(0, 10, 1024);

        $this->assertCount(1, $page['lines']);
        $this->assertStringStartsWith(str_repeat('x', 1024) . ' [... 3976 more bytes cut]', $page['lines'][0]['msg']);
        $this->assertSame(['next'], array_column($reader->page($page['next_offset'], 10, 1024)['lines'], 'msg'));
    }

    public function test_a_page_past_the_end_is_empty_but_still_says_where_to_resume(): void
    {
        // What every poll after the deploy finishes looks like.
        $reader = $this->write($this->entry(100, 'a'));
        $page = $reader->page(5, 10);

        $this->assertSame([], $page['lines']);
        $this->assertSame(1, $page['next_offset']);
    }

    public function test_a_negative_offset_is_read_from_the_start(): void
    {
        $reader = $this->write($this->entry(100, 'a'), $this->entry(200, 'b'));

        $this->assertSame(['a', 'b'], array_column($reader->page(-5, 10)['lines'], 'msg'));
    }

    public function test_a_page_carries_the_stage_each_line_belonged_to(): void
    {
        $reader = $this->write(
            $this->entry(100, 'cloning', 'cloning'),
            $this->entry(200, 'building', 'building')
        );

        $this->assertSame(['cloning', 'building'], array_column($reader->page()['lines'], 'stage'));
    }

    public function test_the_tail_returns_the_most_recent_lines(): void
    {
        $reader = $this->write($this->entry(100, 'a'), $this->entry(200, 'b'), $this->entry(300, 'c'));

        $this->assertSame(['b', 'c'], array_column($reader->tail(2), 'msg'));
    }

    public function test_a_tail_longer_than_the_log_returns_all_of_it(): void
    {
        $reader = $this->write($this->entry(100, 'a'));

        $this->assertCount(1, $reader->tail(50));
    }

    public function test_a_tail_of_nothing_is_nothing(): void
    {
        $reader = $this->write($this->entry(100, 'a'));

        $this->assertSame([], $reader->tail(0));
        $this->assertSame([], $reader->tail(-1));
    }

    public function test_a_corrupt_line_is_skipped_rather_than_fatal(): void
    {
        // A deploy killed mid-write leaves a half-line. The rest of the log
        // is still the only record of what happened.
        $reader = $this->write($this->entry(100, 'a'), '{"ts": 200, "msg"', $this->entry(300, 'c'));

        $this->assertSame(['a', 'c'], array_column($reader->entries(), 'msg'));
        $this->assertSame(['a', 'c'], array_column($reader->page()['lines'], 'msg'));
    }

    public function test_an_entry_with_no_message_is_not_an_entry(): void
    {
        $reader = $this->write('{"ts": 100, "level": "info"}', $this->entry(200, 'b'));

        $this->assertSame(['b'], array_column($reader->entries(), 'msg'));
    }

    public function test_missing_fields_fall_back_rather_than_throwing(): void
    {
        $reader = $this->write('{"msg": "no timestamp"}');
        $line = $reader->page()['lines'][0];

        $this->assertSame(0, $line['ts']);
        $this->assertNull($line['stage']);
        $this->assertNotSame('', $line['level']);
    }

    public function test_a_log_that_is_not_there_reads_as_empty(): void
    {
        $reader = new DeployLogReader($this->path);

        $this->assertSame([], $reader->entries());
        $this->assertSame([], $reader->tail(10));
        $this->assertSame(['lines' => [], 'next_offset' => 0, 'more' => false], $reader->page());
    }
}
