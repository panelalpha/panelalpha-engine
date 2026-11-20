<?php

namespace Tests\Integration\Console\Wp;

use App\Console\Commands\Wp\WpCommand;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * `wordpress:command` answers WP-CLI's own exit code, so a script reading the
 * shell's status sees the failure the printed output carries.
 */
class WpCommandExitCodeTest extends TestCase
{
    public function test_the_wp_cli_exit_code_is_the_commands_exit_code(): void
    {
        $output = new BufferedOutput;

        $this->assertSame(1, $this->report(
            ['exit_code' => 1, 'stdout' => '', 'stderr' => "Error: Could not get 'no-such-option' option.\n"],
            $output,
        ));
        $this->assertStringContainsString('exit_code: 1', $output->fetch());

        $this->assertSame(0, $this->report(['exit_code' => 0, 'stdout' => "6.7.1\n", 'stderr' => ''], new BufferedOutput));
    }

    /**
     * @param  array{exit_code: int, stdout: string, stderr: string}  $result
     */
    private function report(array $result, BufferedOutput $output): int
    {
        $command = new class extends WpCommand
        {
            /**
             * @param  array{exit_code: int, stdout: string, stderr: string}  $result
             */
            public function reportResult(array $result): int
            {
                return $this->report($result);
            }
        };
        $input = new ArrayInput([]);
        $command->setOutput(new OutputStyle($input, $output));

        return $command->reportResult($result);
    }
}
