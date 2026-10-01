<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\DockerErrorException;
use App\Exceptions\NotFoundException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\StreamOutput;
use Tests\TestCase;

class ConsoleFailureRenderingTest extends TestCase
{
    /** @return array{int, string, string} exit code, stdout, stderr */
    private function runThrowing(\Throwable $e): array
    {
        Artisan::command('test:throws', fn () => throw $e);

        $stdout = new StreamOutput(fopen('php://memory', 'w+'));
        $stderr = new StreamOutput(fopen('php://memory', 'w+'));
        $output = new class ($stdout, $stderr) extends ConsoleOutput {
            public function __construct(private StreamOutput $out, private StreamOutput $err)
            {
                parent::__construct();
                $this->setErrorOutput($err);
            }

            public function doWrite(string $message, bool $newline): void
            {
                $this->out->doWrite($message, $newline);
            }
        };

        $exit = $this->app->make(Kernel::class)->handle(new ArrayInput(['command' => 'test:throws']), $output);

        $read = static function (StreamOutput $o): string {
            rewind($o->getStream());
            return (string) stream_get_contents($o->getStream());
        };

        return [$exit, $read($stdout), $read($stderr)];
    }

    public function test_not_found_prints_its_message_on_stderr_and_exits_1(): void
    {
        [$exit, $stdout, $stderr] = $this->runThrowing(new NotFoundException("Domain 'a.test' not found."));

        $this->assertSame(1, $exit);
        $this->assertSame('', $stdout);
        $this->assertSame("Domain 'a.test' not found.\n", $stderr);
    }

    public function test_validation_prints_every_message_on_its_own_line(): void
    {
        [$exit, , $stderr] = $this->runThrowing(ValidationException::withMessages([
            'start' => 'The start field is required.',
            'end' => ['The end field is required.', 'The end field must be a date.'],
        ]));

        $this->assertSame(1, $exit);
        $this->assertSame("The start field is required.\nThe end field is required.\nThe end field must be a date.\n", $stderr);
    }

    public function test_a_deploy_conflict_prints_its_message(): void
    {
        [$exit, , $stderr] = $this->runThrowing(new DeployAlreadyRunningException('A deploy is already running for <alice>.'));

        $this->assertSame(1, $exit);
        $this->assertSame("A deploy is already running for <alice>.\n", $stderr);
    }

    public function test_a_git_failure_prints_its_message(): void
    {
        [$exit, , $stderr] = $this->runThrowing(new \App\System\Project\Git\Exception('Git is not connected.'));

        $this->assertSame(1, $exit);
        $this->assertSame("Git is not connected.\n", $stderr);
    }

    public function test_a_docker_error_prints_without_its_trailing_newline(): void
    {
        [$exit, , $stderr] = $this->runThrowing(new DockerErrorException("Error response from daemon: container alice is restarting\n"));

        $this->assertSame(1, $exit);
        $this->assertSame("Error response from daemon: container alice is restarting\n", $stderr);
    }

    public function test_anything_else_keeps_artisans_rendering(): void
    {
        [$exit, $stdout, $stderr] = $this->runThrowing(new \RuntimeException('boom'));

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('boom', $stdout . $stderr);
        $this->assertStringContainsString('In ConsoleFailureRenderingTest.php line', $stdout . $stderr);
    }

    public function test_http_still_answers_a_plain_not_found(): void
    {
        $response = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            ->render(Request::create('/api/x', 'GET', server: ['HTTP_ACCEPT' => 'application/json']), new NotFoundException("Project 'x' not found."));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not found', json_decode((string) $response->getContent(), true)['message']);
    }
}
