<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployLogReader;
use PHPUnit\Framework\TestCase;

/** head() and last() read the ends of a log without the middle. */
class DeployLogReaderBoundsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir() . '/pa-reader-bounds-' . bin2hex(random_bytes(4)) . '.log';
        $lines = '';
        for ($i = 1; $i <= 50; $i++) {
            $lines .= json_encode(['ts' => $i, 'stage' => null, 'level' => 'info', 'msg' => "line {$i}"]) . "\n";
        }
        file_put_contents($this->path, $lines);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_head_reads_the_first_lines(): void
    {
        $lines = (new DeployLogReader($this->path))->head(3);

        $this->assertSame(['line 1', 'line 2', 'line 3'], array_column($lines, 'msg'));
        $this->assertSame(1, $lines[0]['ts']);
    }

    public function test_last_reads_the_last_lines(): void
    {
        $lines = (new DeployLogReader($this->path))->last(3);

        $this->assertSame(['line 48', 'line 49', 'line 50'], array_column($lines, 'msg'));
    }

    public function test_last_drops_the_line_its_window_cuts_into(): void
    {
        $window = 100;

        $lines = (new DeployLogReader($this->path))->last(50, $window);

        $this->assertNotSame([], $lines);
        $this->assertSame('line 50', end($lines)['msg']);
        $this->assertLessThan(50, count($lines));
        foreach ($lines as $line) {
            $this->assertMatchesRegularExpression('/^line \d+$/', $line['msg'], 'no half line parsed');
        }
    }

    public function test_a_missing_log_or_no_lines_asked_reads_nothing(): void
    {
        $missing = new DeployLogReader($this->path . '.gone');

        $this->assertSame([], $missing->head(5));
        $this->assertSame([], $missing->last(5));
        $this->assertSame([], (new DeployLogReader($this->path))->head(0));
        $this->assertSame([], (new DeployLogReader($this->path))->last(0));
    }
}
