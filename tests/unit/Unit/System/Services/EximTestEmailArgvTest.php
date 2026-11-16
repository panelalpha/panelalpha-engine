<?php

namespace Tests\Unit\System\Services;

use App\System;
use App\System\EnginePaths;
use App\System\Services\Exim;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/** The exact request a test email is asked for with, and the result built from what the broker printed. */
class EximTestEmailArgvTest extends TestCase
{
    public function test_the_command_and_the_result(): void
    {
        $system = new class extends System {
            /** @var list<array{0: string|list<string>, 1: array, 2: int}> */
            public array $calls = [];

            /** @var list<string|list<string>> */
            public array $requests = [];

            public function __construct()
            {
            }

            public function composeFilePath(): string
            {
                return EnginePaths::ENGINE_DIR . '/docker-compose.yml';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->requests[] = $cmd;
                $this->calls[] = [(array) $cmd, $env, $timeout];

                return new class extends Process {
                    public function __construct()
                    {
                        parent::__construct(['true']);
                    }

                    public function getOutput(): string
                    {
                        return "LOG: MAIN\n";
                    }

                    public function getErrorOutput(): string
                    {
                        return '  ** o\'brien@example.org: Unrouteable address';
                    }

                    public function getExitCode(): ?int
                    {
                        return 0;
                    }
                };
            }
        };

        $result = (new Exim($system))->sendTestEmail("o'brien@example.org");

        $this->assertSame([[[
            LocalRoot::CLIENT,
            'compose.eximSendTestEmail',
            '--',
            EnginePaths::ENGINE_DIR . '/docker-compose.yml',
            "o'brien@example.org",
        ], [], 600]], $system->calls);
        $this->assertSame([
            'status' => 'failed',
            'delivered' => false,
            'reason' => 'Unrouteable address',
            'stdout' => "LOG: MAIN\n",
            'stderr' => '  ** o\'brien@example.org: Unrouteable address',
            'exit_code' => 0,
        ], $result);
    }

    public function test_an_invalid_address_is_refused_with_this_message(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('Invalid test email address.'));

        (new Exim(new class extends System {
            public function __construct()
            {
            }
        }))->sendTestEmail('not an address');
    }
}
