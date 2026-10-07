<?php

namespace Tests\Unit\Console;

use App\Console\Commands\Mcp\CheckCommand;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * `mcp:check {token}` took the secret as an argument, where
 * `ps` and the shell history both keep it. The argument still works, with a
 * deprecation notice; the token can also come from stdin or a prompt.
 */
class McpCheckTokenInputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://engine.test:2011']);
        Http::fake(['*' => Http::response(['valid' => true], 200)]);
    }

    public function test_the_token_can_be_piped_in(): void
    {
        [$tester] = $this->run_(['--stdin' => true], "1|piped-token\n");

        $this->assertBearer('1|piped-token');
        $this->assertStringNotContainsString('deprecated', $tester->getDisplay(true));
    }

    public function test_stdin_that_is_not_a_terminal_is_read_without_the_flag(): void
    {
        $this->run_([], "1|from-a-pipe\n");

        $this->assertBearer('1|from-a-pipe');
    }

    public function test_the_argument_still_works_but_says_it_is_deprecated(): void
    {
        [$tester] = $this->run_(['token' => '1|argv-token'], '', captureStderr: true);

        $this->assertBearer('1|argv-token');
        $this->assertStringContainsString('deprecated', $tester->getErrorOutput(true));
    }

    public function test_no_token_at_all_is_an_error_and_nothing_is_sent(): void
    {
        [$tester, $exit] = $this->run_([], '');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No token given', $tester->getDisplay(true));
        Http::assertNothingSent();
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: CommandTester, 1: int}
     */
    private function run_(array $input, string $stdin, bool $captureStderr = false): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $stdin);
        rewind($stream);

        $command = new class ($stream) extends CheckCommand {
            /** @param resource $stream */
            public function __construct(private $stream)
            {
                parent::__construct();
            }

            protected function stdin()
            {
                return $this->stream;
            }
        };
        $command->setLaravel($this->app);

        $tester = new CommandTester($command);
        $exit = $tester->execute($input, ['interactive' => false, 'capture_stderr_separately' => $captureStderr]);

        return [$tester, $exit];
    }

    private function assertBearer(string $token): void
    {
        Http::assertSent(fn (ClientRequest $request) => $request->hasHeader('Authorization', 'Bearer ' . $token));
    }
}
