<?php

namespace Tests\Unit\Console;

use App\Http\Requests\SshCommandRunRequest;
use App\System\Project\Dind;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\PhpHosting;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Shell access is the one endpoint whose whole purpose is to run whatever it
 * is handed, so what it refuses matters more than what it runs: an unbounded
 * timeout would pin a worker on a command that never returns, and a missing
 * command would reach bash as an empty line.
 */
class ProjectSshTest extends TestCase
{
    /** @param array<string, mixed> $payload */
    private function fails(array $payload): bool
    {
        return Validator::make($payload, (new SshCommandRunRequest())->rules())->fails();
    }

    public function test_a_command_is_required(): void
    {
        $this->assertTrue($this->fails([]));
        $this->assertTrue($this->fails(['command' => '']));
        $this->assertFalse($this->fails(['command' => 'ls -la']));
    }

    public function test_the_timeout_is_bounded(): void
    {
        $this->assertFalse($this->fails(['command' => 'ls', 'timeout' => 1]));
        $this->assertFalse($this->fails(['command' => 'ls', 'timeout' => SshCommandRunRequest::MAX_TIMEOUT]));
        $this->assertTrue($this->fails(['command' => 'ls', 'timeout' => 0]));
        $this->assertTrue($this->fails(['command' => 'ls', 'timeout' => SshCommandRunRequest::MAX_TIMEOUT + 1]));
        $this->assertTrue($this->fails(['command' => 'ls', 'timeout' => 'soon']));
    }

    public function test_cwd_is_optional_and_may_be_omitted_or_null(): void
    {
        $this->assertFalse($this->fails(['command' => 'ls']));
        $this->assertFalse($this->fails(['command' => 'ls', 'cwd' => null]));
        $this->assertFalse($this->fails(['command' => 'ls', 'cwd' => '/home/demo/project']));
    }

    /**
     * A whole command line, not argv: pipes and redirection are the point, so
     * nothing here may be rejected for containing shell metacharacters.
     */
    public function test_shell_syntax_is_accepted_rather_than_filtered(): void
    {
        foreach (['ls | wc -l', 'cat a > b', 'echo $HOME', 'find . -name "*.php" && echo ok'] as $command) {
            $this->assertFalse($this->fails(['command' => $command]), $command);
        }
    }

    /**
     * The command is passed through untouched; the working directory is not.
     */
    public function test_the_working_directory_is_quoted_and_gates_the_command(): void
    {
        $compose = ShellOperations::composeShellCommand(...);

        $this->assertSame('ls -la', $compose('ls -la', null));
        $this->assertSame('ls -la', $compose('ls -la', ''));
        $this->assertSame('cd ' . escapeshellarg('/home/demo/project') . ' && ls -la', $compose('ls -la', '/home/demo/project'));

        // A cwd carrying shell syntax stays one argument to cd, so the worst
        // it can do is fail to change directory.
        $this->assertSame('cd ' . escapeshellarg('/tmp; rm -rf /') . ' && ls', $compose('ls', '/tmp; rm -rf /'));
        $this->assertSame('cd ' . escapeshellarg("/tmp'x") . ' && ls', $compose('ls', "/tmp'x"));

        // && and not ; - a missing directory must stop the command.
        $this->assertStringContainsString(' && ', $compose('ls', '/nope'));
        $this->assertStringNotContainsString('; ls', $compose('ls', '/nope'));
    }

    /**
     * Symfony Console defines a `command` argument on every command - the
     * command's own name - so a second one of that name makes the command
     * unrunnable, while `--help` still renders fine. Only invoking it shows
     * the clash, so invoke it.
     */
    /**
     * Verified against a real dind container: plain `su` leaves the cwd at `/`,
     * `su -l` lands in the account home directory, which is what an SSH session
     * gives and what the endpoint documents as its default.
     */
    public function test_the_command_runs_through_a_login_shell(): void
    {
        $reflection = new \ReflectionMethod(
            ShellOperations::class,
            'runShellAsUser'
        );
        $source = implode('', array_slice(
            file($reflection->getFileName() ?: ''),
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));

        $this->assertStringContainsString("'-l'", $source, 'su must run a login shell so the command starts in the home directory');
    }

    public function test_the_command_binds_its_arguments_and_runs(): void
    {
        $exit = \Illuminate\Support\Facades\Artisan::call('project:ssh', [
            'project' => 'demo',
            'cmd' => 'ls',
            '--timeout' => 99999,
        ]);

        // Rejected by the timeout bound, which is only reachable if binding worked.
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--timeout must be between 1 and 900', \Illuminate\Support\Facades\Artisan::output());
    }

    /**
     * Writing to stderr is the one path a dry run never reaches: it needs a
     * command that actually failed. OutputStyle::getErrorOutput() is protected,
     * so the obvious spelling is a fatal Error at runtime and nothing earlier
     * catches it. Both output shapes are exercised - a console output with a
     * separate error stream, and a plain buffer without one.
     */
    public function test_stderr_is_written_without_reaching_for_a_protected_method(): void
    {
        $command = new \App\Console\Commands\Users\ProjectSshCommand();

        $buffer = new \Symfony\Component\Console\Output\BufferedOutput();
        $command->setOutput(new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            $buffer
        ));
        $command->writeStreams('out-text', 'err-text');
        $combined = $buffer->fetch();
        $this->assertStringContainsString('out-text', $combined);
        $this->assertStringContainsString('err-text', $combined, 'with no separate error stream, stderr falls back to stdout');

        $console = new \Symfony\Component\Console\Output\ConsoleOutput();
        $command->setOutput(new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            $console
        ));
        $this->assertTrue($console instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface);
        $command->writeStreams('', '');
    }

    public function test_the_route_is_registered_under_both_prefixes(): void
    {
        $registered = [];
        foreach (RouteFacade::getRoutes() as $route) {
            foreach ($route->methods() as $verb) {
                $registered[] = $verb . ' ' . $route->uri();
            }
        }

        $this->assertContains('POST api/projects/{username}/ssh/command', $registered);
        $this->assertContains('POST api/users/{username}/ssh/command', $registered);
    }

    /**
     * The shared-hosting templates run every account inside one service, so
     * there is no session there to hand out. The default has to refuse rather
     * than quietly run the command somewhere shared.
     */
    public function test_non_dind_projects_refuse_shell_access(): void
    {
        $this->assertTrue(method_exists(Dind::class, 'runSshCommand'));
        $this->assertFalse(method_exists(PhpHosting::class, 'runSshCommand'));
    }
}
