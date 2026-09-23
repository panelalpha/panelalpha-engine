<?php

namespace Tests\Unit\Deploy\Platform\Stage;

use App\Lib\Deploy\Platform\PlatformCommand;
use App\Lib\Deploy\Platform\Stage\CommandScript;
use PHPUnit\Framework\TestCase;

/**
 * One manifest command rendered as the entrypoint lines that run it.
 *
 * Two of these lines exist for reasons that only show at 3am: `pa_step`,
 * because a deploy with no step markers gives a timing report with one
 * undifferentiated block; and `|| pa_skip`, because an optional step that
 * fails silently is how a half-configured container reaches production
 * looking healthy.
 */
class CommandScriptTest extends TestCase
{
    /**
     * @param array<string, mixed> $raw
     */
    private function command(array $raw): PlatformCommand
    {
        return PlatformCommand::fromArray($raw + ['stages' => ['install']], 'test', 0);
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, string> $overrides
     * @return list<string>
     */
    private function lines(array $raw, array $overrides = []): array
    {
        return (new CommandScript($this->command($raw), 'install', $overrides))->lines();
    }

    public function test_a_command_announces_itself_and_runs(): void
    {
        $this->assertSame([
            "pa_step install 'migrate'",
            'php artisan migrate --force',
        ], $this->lines(['id' => 'migrate', 'run' => 'php artisan migrate --force']));
    }

    public function test_a_description_becomes_a_comment(): void
    {
        $lines = $this->lines([
            'id' => 'migrate',
            'run' => 'php artisan migrate --force',
            'description' => 'Apply pending migrations',
        ]);

        $this->assertSame('# Apply pending migrations', $lines[0]);
        $this->assertCount(3, $lines);
    }

    public function test_every_line_of_a_multiline_description_is_commented(): void
    {
        // A YAML folded `>-` description with a blank line folds to real "\n".
        // Commenting only the first line drops the rest into the entrypoint as
        // bare shell: a syntax error that crash-loops the container while the
        // deploy reports success (engine#198).
        $lines = $this->lines([
            'id' => 'migrate',
            'run' => 'php artisan migrate --force',
            'description' => "Apply pending migrations.\n\nSafe to re-run.",
        ]);

        $this->assertSame("# Apply pending migrations.\n# \n# Safe to re-run.", $lines[0]);

        foreach (explode("\n", $lines[0]) as $line) {
            $this->assertStringStartsWith('#', $line);
        }
    }

    public function test_an_optional_command_records_the_skip_rather_than_failing(): void
    {
        $lines = $this->lines([
            'id' => 'seed',
            'run' => 'php artisan db:seed',
            'optional' => true,
        ]);

        $this->assertSame("php artisan db:seed || pa_skip install 'seed'", end($lines));
    }

    public function test_a_timeout_kills_the_command_and_not_only_the_timer(): void
    {
        // Without --foreground, timeout signals its own process group and the
        // hung command lives on, outliving the step that was meant to bound it.
        $lines = $this->lines([
            'id' => 'build',
            'run' => 'npm run build',
            'timeout' => 600,
        ]);

        $this->assertSame("timeout --foreground 600 sh -c 'npm run build'", end($lines));
    }

    public function test_a_timed_optional_command_gets_both(): void
    {
        $lines = $this->lines([
            'id' => 'build',
            'run' => 'npm run build',
            'timeout' => 600,
            'optional' => true,
        ]);

        $this->assertSame(
            "timeout --foreground 600 sh -c 'npm run build' || pa_skip install 'build'",
            end($lines)
        );
    }

    public function test_a_workdir_is_applied_before_the_timeout_wraps_it(): void
    {
        // The subshell has to be inside the timeout, or the timeout bounds the
        // `cd` and not the command.
        $lines = $this->lines([
            'id' => 'build',
            'run' => 'npm run build',
            'workdir' => 'apps/web',
            'timeout' => 600,
        ]);

        $this->assertSame(
            "timeout --foreground 600 sh -c '( cd apps/web && npm run build )'",
            end($lines)
        );
    }

    public function test_an_override_replaces_the_manifests_command(): void
    {
        // How a recipe's generic default becomes what this project needs -
        // the resolved package manager, the detected PHP binary.
        $lines = $this->lines(
            ['id' => 'install', 'run' => 'npm ci'],
            ['install' => 'pnpm install --frozen-lockfile']
        );

        $this->assertSame('pnpm install --frozen-lockfile', end($lines));
    }

    public function test_an_override_for_another_command_is_ignored(): void
    {
        $lines = $this->lines(['id' => 'install', 'run' => 'npm ci'], ['build' => 'pnpm build']);

        $this->assertSame('npm ci', end($lines));
    }

    public function test_the_step_id_is_quoted(): void
    {
        $lines = $this->lines(['id' => "it's", 'run' => 'true']);

        $this->assertSame("pa_step install 'it'\\''s'", $lines[0]);
    }
}
