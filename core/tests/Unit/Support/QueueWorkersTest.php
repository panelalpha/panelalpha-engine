<?php

namespace Tests\Unit\Support;

use App\Support\QueueWorkers;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QueueWorkersTest extends TestCase
{
    /** @return array<string, array{0: int, 1: bool}> */
    public static function counts(): array
    {
        return [
            'the minimum' => [QueueWorkers::MIN, true],
            'the maximum' => [QueueWorkers::MAX, true],
            'the default' => [QueueWorkers::DEFAULT, true],
            'zero is below the minimum' => [0, false],
            'negative is below the minimum' => [-1, false],
            'one past the maximum' => [QueueWorkers::MAX + 1, false],
        ];
    }

    #[DataProvider('counts')]
    public function test_it_takes_a_count_in_range(int $count, bool $acceptable): void
    {
        $this->assertSame($acceptable, QueueWorkers::badCount($count) === null, (string) $count);
    }

    /**
     * The block still has to be valid supervisord config: `%(program_name)s`
     * and `%(process_num)02d` are supervisord's own placeholders, not this
     * class's -- a naive sprintf() over the whole template would choke on
     * them, which is why str_replace() on a distinct token replaced it.
     */
    public function test_the_generated_block_bakes_in_the_count_and_keeps_supervisords_own_placeholders(): void
    {
        $conf = QueueWorkers::conf(3);

        $this->assertStringContainsString('numprocs=3', $conf);
        $this->assertStringContainsString('[program:queue]', $conf);
        $this->assertStringContainsString('%(program_name)s_%(process_num)02d', $conf);
        $this->assertStringNotContainsString('{{COUNT}}', $conf);
    }

    public function test_a_different_count_only_changes_the_numprocs_line(): void
    {
        $eight = explode("\n", QueueWorkers::conf(8));
        $three = explode("\n", QueueWorkers::conf(3));

        $this->assertCount(count($eight), $three);

        $changedLines = array_keys(array_diff_assoc($eight, $three));

        $this->assertCount(1, $changedLines, 'only one line should differ between two counts');
        $this->assertSame('numprocs=8', $eight[$changedLines[0]]);
        $this->assertSame('numprocs=3', $three[$changedLines[0]]);
    }
}
