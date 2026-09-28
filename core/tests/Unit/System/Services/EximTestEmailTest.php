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
}
