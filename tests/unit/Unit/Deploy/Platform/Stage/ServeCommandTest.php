<?php

namespace Tests\Unit\Deploy\Platform\Stage;

use App\Lib\Deploy\Platform\PlatformCommand;
use App\Lib\Deploy\Platform\Runtime\RustRuntime;
use App\Lib\Deploy\Platform\Stage\ServeCommand;
use PHPUnit\Framework\TestCase;

/**
 * The last line of the entrypoint, where the server replaces the shell.
 *
 * Without `exec`, the shell stays PID 1 and Docker's stop signal reaches it
 * instead of the application: the server never shuts down cleanly and every
 * `docker compose down` waits out the ten-second kill timer. With it in the
 * wrong place - in front of an `if` - the entrypoint is a syntax error and
 * the container never starts at all.
 */
class ServeCommandTest extends TestCase
{
    /**
     * @param array<string, mixed> $raw
     * @param array<string, string> $overrides
     * @return list<string>
     */
    private function lines(array $raw, array $overrides = []): array
    {
        $command = PlatformCommand::fromArray(
            $raw + ['stages' => ['start'], 'serve' => true],
            'test',
            0
        );

        return (new ServeCommand($command, $overrides))->lines();
    }

    public function test_the_server_replaces_the_shell(): void
    {
        $this->assertSame([
            "pa_step start 'serve'",
            'exec node server.js',
        ], $this->lines(['id' => 'serve', 'run' => 'node server.js']));
    }

    public function test_a_command_that_already_execs_is_not_prefixed_again(): void
    {
        $lines = $this->lines(['id' => 'serve', 'run' => 'exec php -S 0.0.0.0:8080']);

        $this->assertSame('exec php -S 0.0.0.0:8080', end($lines));
    }

    public function test_a_shell_construct_carries_its_own_exec(): void
    {
        // PHP's built-in server has to know whether the project has a public/
        // directory. `exec if ...` is a syntax error, so the branches exec
        // themselves and the prefix is dropped.
        $run = 'if [ -d public ]; then exec php -S 0.0.0.0:8080 -t public; else exec php -S 0.0.0.0:8080; fi';

        $lines = $this->lines(['id' => 'serve', 'run' => $run]);

        $this->assertSame($run, end($lines));
    }

    public function test_every_self_executing_construct_is_recognised(): void
    {
        foreach (['for ', 'while ', 'case ', '{ '] as $keyword) {
            $run = $keyword . 'x; do exec true; done';

            $lines = $this->lines(['id' => 'serve', 'run' => $run]);

            $this->assertSame($run, end($lines), $keyword);
        }
    }

    public function test_a_guard_that_execs_at_the_end_is_left_alone(): void
    {
        // The Rust and Go serve commands check the binary exists before
        // handing over. Prefixed with `exec`, the shell is replaced by `[`,
        // the guard's message never prints, and the container restart-loops
        // with nothing in the log -- exactly what the guard exists to prevent.
        $run = '[ -x ./target/release/app ]'
            . ' || { echo "PANELALPHA: no runnable Rust binary"; exit 1; };'
            . ' exec ./target/release/app';

        $lines = $this->lines(['id' => 'serve', 'run' => $run]);

        $this->assertSame($run, end($lines));
    }

    public function test_an_exec_inside_quotes_is_not_the_outer_command(): void
    {
        $lines = $this->lines(['id' => 'serve', 'run' => "sh -c 'echo exec me'"]);

        $this->assertSame("exec sh -c 'echo exec me'", end($lines));
    }

    public function test_a_workdir_keeps_exec_in_front_of_the_server(): void
    {
        // sh -c, not a subshell: a subshell would leave a shell between init
        // and the server, which is the whole problem exec is here to avoid.
        $lines = $this->lines(['id' => 'serve', 'run' => 'node server.js', 'workdir' => 'apps/web']);

        $this->assertSame("exec sh -c 'cd apps/web && exec node server.js'", end($lines));
    }

    public function test_an_override_replaces_the_manifests_serve_command(): void
    {
        $lines = $this->lines(['id' => 'serve', 'run' => 'node server.js'], ['serve' => './app --port 8080']);

        $this->assertSame('exec ./app --port 8080', end($lines));
    }

    public function test_a_user_start_command_wins_over_the_rust_binary_lookup(): void
    {
        $lines = $this->lines(
            ['id' => 'serve', 'run' => RustRuntime::startCommand(sys_get_temp_dir())],
            ['serve' => './target/release/km serve']
        );

        $this->assertSame('exec ./target/release/km serve', end($lines));
    }

    public function test_the_rust_start_command_is_not_prefixed_with_exec(): void
    {
        $run = RustRuntime::startCommand(sys_get_temp_dir());

        $lines = $this->lines(['id' => 'serve', 'run' => $run]);

        $this->assertSame($run, end($lines));
    }

    /**
     * `exec` binds to the first simple command, so prefixing a chain runs its
     * first word and discards the rest. The Python last-resort server is
     * `mkdir … && printf … > index.html && python -m http.server`: prefixed,
     * the shell became `mkdir`, exited 0, and the account restart-looped with
     * nothing in the log but `start: serve` -- the exact failure this class
     * exists to prevent, arriving by a different road.
     */
    public function test_a_chained_command_is_not_truncated_by_exec(): void
    {
        $run = "mkdir -p .x && printf '%s' '<h1>hi</h1>' > .x/index.html && python -m http.server 8000";

        $this->assertSame([
            "pa_step start 'serve'",
            $run,
        ], $this->lines(['id' => 'serve', 'run' => $run]));
    }

    public function test_an_or_chain_is_not_truncated_either(): void
    {
        $run = 'test -x ./app || echo missing; ./app';

        $this->assertSame([
            "pa_step start 'serve'",
            $run,
        ], $this->lines(['id' => 'serve', 'run' => $run]));
    }

    public function test_a_pipe_is_not_truncated(): void
    {
        $run = 'generate-config | tee /etc/app.conf';

        $this->assertSame([
            "pa_step start 'serve'",
            $run,
        ], $this->lines(['id' => 'serve', 'run' => $run]));
    }

    /**
     * The working-directory wrapper puts its chain inside quotes, so the outer
     * `sh` is still a single command and still has to be exec'd.
     */
    public function test_a_quoted_chain_still_execs_the_outer_shell(): void
    {
        $lines = $this->lines([
            'id' => 'serve',
            'run' => 'node server.js',
            'workdir' => 'apps/web',
        ]);

        $this->assertStringStartsWith('exec ', $lines[1]);
        $this->assertStringContainsString('cd ', $lines[1]);
    }
}
