<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\ProcessOutput;
use PHPUnit\Framework\TestCase;

/**
 * Subprocess output arriving in whatever chunks the pipe had, turned into
 * whole log lines.
 *
 * Symfony Process hands over reads that routinely end mid-line, so a chunk
 * boundary in the middle of a composer error would otherwise split it into
 * two half-sentences in the customer's log - or, worse, split a connection
 * string so neither half looks like one to the sanitiser.
 */
class ProcessOutputTest extends TestCase
{
    public function test_complete_lines_are_returned(): void
    {
        $output = new ProcessOutput();

        $this->assertSame(
            ['first', 'second'],
            $output->consume('out', "first\nsecond\n")
        );
    }

    public function test_a_line_split_across_chunks_is_rejoined(): void
    {
        $output = new ProcessOutput();

        $this->assertSame([], $output->consume('out', 'added 214 pack'));
        $this->assertSame(['added 214 packages'], $output->consume('out', "ages\n"));
    }

    public function test_a_credential_split_across_chunks_is_still_redacted(): void
    {
        // The reason the carry buffer matters for more than tidiness: half a
        // URL does not match the sanitiser's pattern.
        $output = new ProcessOutput();
        $output->consume('out', 'connecting to postgres://shop:s3c');

        $this->assertSame(
            ['connecting to postgres://***@db:5432/shop'],
            $output->consume('out', "ret@db:5432/shop\n")
        );
    }

    public function test_the_two_streams_carry_separately(): void
    {
        // stdout and stderr interleave; joining a stdout tail to a stderr head
        // would invent a line neither process wrote.
        $output = new ProcessOutput();
        $output->consume('out', 'installing');
        $output->consume('err', 'warning');

        $this->assertSame(['installing dependencies'], $output->consume('out', " dependencies\n"));
        $this->assertSame(['warning: deprecated'], $output->consume('err', ": deprecated\n"));
    }

    public function test_a_repeated_line_is_logged_once(): void
    {
        $output = new ProcessOutput();

        $this->assertSame(['waiting'], $output->consume('out', "waiting\nwaiting\nwaiting\n"));
    }

    public function test_a_line_repeated_after_something_else_is_logged_again(): void
    {
        // Only consecutive duplicates are noise; the same line recurring is
        // information about how long something took.
        $output = new ProcessOutput();

        $this->assertSame(['waiting', 'retrying', 'waiting'], $output->consume('out', "waiting\nretrying\nwaiting\n"));
    }

    public function test_blank_lines_are_dropped(): void
    {
        $output = new ProcessOutput();

        $this->assertSame(['done'], $output->consume('out', "\n\n  \ndone\n"));
    }

    public function test_uninteresting_build_noise_is_dropped(): void
    {
        $output = new ProcessOutput();

        $this->assertSame(
            ['#5 DONE 12.4s'],
            $output->consume('out', "#3 [internal] load metadata\n#4 DONE 0.0s\n#5 DONE 12.4s\n")
        );
    }

    public function test_a_progress_rewrite_is_logged_at_its_final_state(): void
    {
        $output = new ProcessOutput();

        $this->assertSame(
            ['Downloading 100%'],
            $output->consume('out', "Downloading 10%\rDownloading 60%\rDownloading 100%\n")
        );
    }

    public function test_output_that_never_ended_in_a_newline_is_flushed(): void
    {
        // A process killed mid-line still has something worth showing, and it
        // is usually the error that killed it.
        $output = new ProcessOutput();
        $output->consume('out', 'fatal: could not read from remote');

        $this->assertSame(['fatal: could not read from remote'], $output->flush());
    }

    public function test_flushing_twice_yields_nothing_the_second_time(): void
    {
        $output = new ProcessOutput();
        $output->consume('out', 'partial');
        $output->flush();

        $this->assertSame([], $output->flush());
    }

    public function test_flushing_with_nothing_pending_yields_nothing(): void
    {
        $output = new ProcessOutput();
        $output->consume('out', "complete\n");

        $this->assertSame([], $output->flush());
    }
}
