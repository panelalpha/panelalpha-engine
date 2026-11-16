<?php

namespace Tests\Unit\Project;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\StreamOutput;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A live project goes after its staging copy: REST refuses with a 409, `project:delete`
 * with one plain line and exit 1, and neither touches either project.
 */
class ProjectDeleteStagingTest extends TestCase
{
    use InMemoryDatabase;

    private string $live;

    private string $staging;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware();

        $this->live = 'del'.bin2hex(random_bytes(4));
        $this->staging = $this->live.'stg';
        $this->beforeApplicationDestroyed(function (): void {
            DeployLogger::deleteUserLogs($this->live);
            DeployLogger::deleteUserLogs($this->staging);
        });

        $live = $this->makeUser($this->live);
        $staging = $this->makeUser($this->staging);
        $staging->staging = $live->id;
        $staging->save();
    }

    public function test_rest_answers_409_with_the_staging_refusal(): void
    {
        $response = $this->deleteJson("/api/projects/{$this->live}");

        $response->assertStatus(409);
        $response->assertJson([
            'message' => $this->refusal(),
            'problems' => [[
                'field' => 'project',
                'code' => 'staging',
                'message' => $this->refusal(),
            ]],
        ]);
        $this->assertTrue(User::existsByUsername($this->live));
        $this->assertTrue(User::existsByUsername($this->staging));
    }

    public function test_rest_does_not_log_the_refusal_as_a_failed_delete(): void
    {
        Log::spy();

        $this->deleteJson("/api/projects/{$this->live}")->assertStatus(409);

        Log::shouldNotHaveReceived('warning', static fn (string $message): bool => str_starts_with($message, 'Could not delete project'));
    }

    public function test_the_cli_prints_a_plain_line_and_exits_1(): void
    {
        $this->artisan('project:delete', ['project' => [$this->live], '--force' => true])
            ->expectsOutput($this->refusal())
            ->assertExitCode(1);

        $this->assertTrue(User::existsByUsername($this->live));
    }

    public function test_the_cli_prints_the_staging_refusal_on_stderr(): void
    {
        $stdout = new StreamOutput(fopen('php://memory', 'w+'));
        $stderr = new StreamOutput(fopen('php://memory', 'w+'));
        $output = new class ($stdout, $stderr) extends ConsoleOutput
        {
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

        $exit = $this->app->make(Kernel::class)->handle(
            new ArrayInput(['command' => 'project:delete', 'project' => [$this->live], '--force' => true]),
            $output
        );

        $read = static function (StreamOutput $o): string {
            rewind($o->getStream());

            return (string) stream_get_contents($o->getStream());
        };

        $this->assertSame(1, $exit);
        $this->assertStringContainsString($this->refusal(), $read($stderr));
        $this->assertStringNotContainsString($this->refusal(), $read($stdout));
        $this->assertTrue(User::existsByUsername($this->live));
    }

    private function refusal(): string
    {
        return "Cannot delete project '{$this->live}' while staging '{$this->staging}' exists. "
            .'Delete the staging project first.';
    }
}
