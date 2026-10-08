<?php

namespace Tests\Unit;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * FileManager::stat() runs `stat` as a bare argv array via `docker compose
 * exec` -- there is no shell anywhere in that chain to strip quotes. A
 * quoted --printf value used to come out with literal `"` characters stuck
 * to the first and last fields (file_name, create_time). This exercises the
 * real `stat` binary directly (no docker/FileManager plumbing needed) to
 * prove the fixed, unquoted format string parses cleanly.
 */
class FileManagerStatFormatTest extends TestCase
{
    public function test_the_fixed_format_string_produces_no_stray_quotes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pa_stat_');
        try {
            $process = new Process(['stat', '--printf=%n %s %u %g %X %Y %Z %W', $path]);
            $process->run();

            $this->assertTrue($process->isSuccessful());
            $output = $process->getOutput();
            $this->assertStringNotContainsString('"', $output);

            $fields = explode(' ', $output);
            $this->assertCount(8, $fields);
            $this->assertSame($path, $fields[0]);
        } finally {
            @unlink($path);
        }
    }

    public function test_the_old_quoted_format_string_leaked_a_literal_quote(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pa_stat_');
        try {
            $process = new Process(['stat', '--printf="%n %s %u %g %X %Y %Z %W"', $path]);
            $process->run();

            $this->assertTrue($process->isSuccessful());
            $output = $process->getOutput();
            $fields = explode(' ', $output);

            $this->assertStringStartsWith('"', $fields[0]);
            $this->assertStringEndsWith('"', $fields[7]);
        } finally {
            @unlink($path);
        }
    }
}
