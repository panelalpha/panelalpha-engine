<?php

namespace Tests\Unit\System\Project;

use App\System;
use App\System\Project\HomeSwap;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class HomeSwapTest extends TestCase
{
    /** @var list<array{argv: list<string>, successful: bool, output: string}> */
    private array $recorded = [];

    public function test_a_push_swap_moves_the_home_aside_and_the_incoming_in(): void
    {
        $system = $this->recordingSystem([
            '/home/alice' => "project\ndocker\n.incoming\n.pre-push\n",
            '/home/alice/.incoming' => "project\n.htaccess\n",
        ]);

        (new HomeSwap($system, '/home/alice', 60))->swap(
            '/home/alice/.incoming',
            '/home/alice/.pre-push',
            ['docker', '.incoming', '.pre-push'],
            'push swap',
        );

        $this->assertSame([
            'sudo mkdir -p /home/alice/.pre-push',
            'sudo ls -A /home/alice',
            'sudo mv /home/alice/project /home/alice/.pre-push/',
            'sudo ls -A /home/alice/.incoming',
            'sudo mv /home/alice/.incoming/project /home/alice/',
            'sudo mv /home/alice/.incoming/.htaccess /home/alice/',
            'sudo test -d /home/alice/.incoming',
            'sudo rmdir /home/alice/.incoming',
        ], $this->commands());
    }

    public function test_a_failed_move_keeps_the_push_context_wording(): void
    {
        $system = $this->recordingSystem(
            ['/home/alice' => "project\n"],
            failingCommand: 'mv',
            failureMessage: 'Permission denied',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Failed to move 'project' aside before push swap: Permission denied");

        (new HomeSwap($system, '/home/alice', 60))->swap(
            '/home/alice/.incoming',
            '/home/alice/.pre-push',
            ['docker', '.incoming', '.pre-push'],
            'push swap',
        );
    }

    public function test_rollback_clears_the_live_home_and_puts_the_aside_back(): void
    {
        $system = $this->recordingSystem([
            '/home/alice' => "project\n.restore-9\n",
            '/home/alice/.pre-restore-9' => "project\n",
        ]);

        (new HomeSwap($system, '/home/alice', 60))->rollback(
            '/home/alice/.restore-9',
            '/home/alice/.pre-restore-9',
            ['.restore-9', '.pre-restore-9'],
            'restore',
        );

        $this->assertSame([
            'sudo ls -A /home/alice',
            'sudo mv /home/alice/project /home/alice/.restore-9/',
            'sudo ls -A /home/alice/.pre-restore-9',
            'sudo mv /home/alice/.pre-restore-9/project /home/alice/',
        ], $this->commands());
    }

    /**
     * @return list<string>
     */
    private function commands(): array
    {
        return array_map(
            static fn (array $record): string => implode(' ', $record['argv']),
            $this->recorded,
        );
    }

    /**
     * @param array<string, string> $listOutputs
     */
    private function recordingSystem(
        array $listOutputs,
        ?string $failingCommand = null,
        string $failureMessage = '',
    ): System {
        $this->recorded = [];

        return new class ($this->recorded, $listOutputs, $failingCommand, $failureMessage) extends System {
            /** @var list<array{argv: list<string>, successful: bool, output: string}> */
            private array $recorded;

            /**
             * @param list<array{argv: list<string>, successful: bool, output: string}> $recorded
             * @param array<string, string> $listOutputs
             */
            public function __construct(
                array &$recorded,
                private array $listOutputs,
                private ?string $failingCommand,
                private string $failureMessage,
            ) {
                $this->recorded = &$recorded;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
                $output = '';
                if (($argv[1] ?? null) === 'ls') {
                    $output = $this->listOutputs[$argv[3] ?? ''] ?? '';
                }
                $fails = $this->failingCommand !== null && in_array($this->failingCommand, $argv, true);
                $this->recorded[] = ['argv' => $argv, 'successful' => !$fails, 'output' => $output];

                return new class ($fails, $output, $this->failureMessage) extends Process {
                    public function __construct(private bool $fails, private string $out, private string $message)
                    {
                        parent::__construct(['php', '-r', '']);
                    }

                    public function isSuccessful(): bool
                    {
                        return !$this->fails;
                    }

                    public function getOutput(): string
                    {
                        return $this->out;
                    }

                    public function getErrorOutput(): string
                    {
                        return $this->fails ? $this->message : '';
                    }
                };
            }
        };
    }
}
