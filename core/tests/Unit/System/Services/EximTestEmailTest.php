<?php

namespace Tests\Unit\System\Services;

use App\System;
use App\System\Services\Exim;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** The test address is written into the To: header; a line break there adds headers. */
class EximTestEmailTest extends TestCase
{
    public function test_an_address_with_a_header_injection_is_refused_before_anything_runs(): void
    {
        $system = new class extends System {
            public int $runs = 0;

            public function __construct()
            {
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->runs++;

                return Process::fromShellCommandline('true');
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        try {
            (new Exim($system))->sendTestEmail("a@example.com\nBcc: victim@example.org\n\nspam");
        } finally {
            $this->assertSame(0, $system->runs);
        }
    }

    public function test_a_deferred_message_is_not_reported_as_sent(): void
    {
        $output = <<<'EXIM'
            LOG: MAIN
              <= root@mail U=root P=local S=361
            delivering 1uPq3x-000012-2b
            Connecting to mx.example.org [203.0.113.5]:25 ...  failed: Connection refused
            LOG: MAIN
              == user@example.org R=dnslookup T=remote_smtp defer (111): Connection refused
            EXIM;

        $this->assertSame([
            'status' => 'deferred',
            'delivered' => false,
            'reason' => 'R=dnslookup T=remote_smtp defer (111): Connection refused',
        ], Exim::deliveryOutcome($output, 0));
    }

    public function test_delivery_and_bounce_are_told_apart(): void
    {
        $delivered = "LOG: MAIN\n  <= root@mail U=root P=local S=361\n"
            . "  SMTP<< 250 2.0.0 Ok: queued as 4ABC\n"
            . "LOG: MAIN\n  => user@example.org R=dnslookup T=remote_smtp H=mx.example.org [203.0.113.5] C=\"250 2.0.0 Ok\"\n"
            . "LOG: MAIN\n  Completed\n";
        $this->assertSame('delivered', Exim::deliveryOutcome($delivered, 0)['status']);
        $this->assertTrue(Exim::deliveryOutcome($delivered, 0)['delivered']);

        $bounced = "LOG: MAIN\n  ** user@example.test: Unrouteable address\n";
        $this->assertSame(
            ['status' => 'failed', 'delivered' => false, 'reason' => 'Unrouteable address'],
            Exim::deliveryOutcome($bounced, 0)
        );

        // A timestamped log line reads the same.
        $this->assertSame('deferred', Exim::deliveryOutcome('2026-09-30 10:00:00 1uPq3x-000012-2b == u@example.org R=x defer (-53): retry time not reached', 0)['status']);

        $this->assertSame('not_sent', Exim::deliveryOutcome('service "mail" is not running', 1)['status']);
        $this->assertSame('unknown', Exim::deliveryOutcome('', 0)['status']);
    }

    public function test_the_result_carries_the_outcome_next_to_the_raw_output(): void
    {
        $system = new class extends System {
            public function __construct()
            {
            }

            public function composeFilePath(): string
            {
                return '/dev/null';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $process = new Process(['sh', '-c', 'echo "LOG: MAIN" >&2; echo "  == a@example.org R=dnslookup T=remote_smtp defer (111): Connection refused" >&2']);
                $process->run();

                return $process;
            }
        };

        $result = (new Exim($system))->sendTestEmail('a@example.org');

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('deferred', $result['status']);
        $this->assertFalse($result['delivered']);
        $this->assertStringContainsString('Connection refused', $result['stderr']);
    }
}
