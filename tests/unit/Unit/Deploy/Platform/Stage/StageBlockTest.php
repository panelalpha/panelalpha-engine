<?php

namespace Tests\Unit\Deploy\Platform\Stage;

use App\Lib\Deploy\Platform\PlatformCommand;
use App\Lib\Deploy\Platform\Stage\StageBlock;
use Tests\TestCase;

/**
 * One phase of the entrypoint: the block that runs only on the boot that is
 * that phase.
 *
 * The ordering is the point. `prepend` carries what only the engine knows -
 * that this account got a MySQL sidecar, and therefore that the migration has
 * to wait for it - so it has to run before the manifest's own commands.
 * `extra` is the project's own setup script, which runs last and whose
 * failure is not the deploy's failure.
 */
class StageBlockTest extends TestCase
{
    /**
     * @param array<string, mixed> $raw
     */
    private function command(array $raw, string $stage = 'install'): PlatformCommand
    {
        return PlatformCommand::fromArray($raw + ['stages' => [$stage]], 'test', 0);
    }

    public function test_an_empty_block_knows_it_is_empty(): void
    {
        $this->assertTrue((new StageBlock('install', []))->isEmpty());
    }

    public function test_a_block_with_only_engine_supplied_lines_is_not_empty(): void
    {
        // No manifest commands, but the engine still has to wait for the
        // sidecar it created.
        $this->assertFalse(
            (new StageBlock('install', [], [], ['wait-for-db' => 'pa_wait_tcp db 3306']))->isEmpty()
        );
        $this->assertFalse(
            (new StageBlock('install', [], [], [], ['setup' => './panelalpha-setup.sh']))->isEmpty()
        );
    }

    public function test_the_block_only_runs_in_its_own_phase(): void
    {
        $rendered = (new StageBlock('install', [$this->command(['id' => 'migrate', 'run' => 'true'])]))->render();

        $this->assertStringStartsWith('if [ "$PA_PHASE" = "install" ]; then', $rendered);
        $this->assertStringEndsWith('fi', $rendered);
    }

    public function test_engine_lines_come_first_and_the_projects_own_script_last(): void
    {
        $rendered = (new StageBlock(
            'install',
            [$this->command(['id' => 'migrate', 'run' => 'php artisan migrate --force'])],
            [],
            ['wait-for-db' => 'pa_wait_tcp db 3306'],
            ['setup' => './panelalpha-setup.sh']
        ))->render();

        $this->assertSame(
            ['pa_wait_tcp db 3306', 'php artisan migrate --force', './panelalpha-setup.sh || pa_skip'],
            $this->runLines($rendered)
        );
    }

    public function test_the_projects_own_script_may_fail_without_taking_the_boot_down(): void
    {
        // A setup script is a courtesy, not a contract. It still has to be
        // recorded, or a half-configured container looks healthy.
        $rendered = (new StageBlock('install', [], [], [], ['setup' => './panelalpha-setup.sh']))->render();

        $this->assertStringContainsString("./panelalpha-setup.sh || pa_skip install 'setup'", $rendered);
    }

    public function test_an_engine_supplied_line_must_succeed(): void
    {
        $rendered = (new StageBlock('install', [], [], ['wait-for-db' => 'pa_wait_tcp db 3306']))->render();

        $this->assertStringContainsString("pa_step install 'wait-for-db'", $rendered);
        $this->assertStringNotContainsString('pa_skip', $rendered);
    }

    public function test_every_command_announces_its_step(): void
    {
        $rendered = (new StageBlock(
            'build',
            [
                $this->command(['id' => 'deps', 'run' => 'npm ci'], 'build'),
                $this->command(['id' => 'assets', 'run' => 'npm run build'], 'build'),
            ]
        ))->render();

        $this->assertStringContainsString("pa_step build 'deps'", $rendered);
        $this->assertStringContainsString("pa_step build 'assets'", $rendered);
    }

    public function test_overrides_reach_the_manifests_commands(): void
    {
        $rendered = (new StageBlock(
            'install',
            [$this->command(['id' => 'install', 'run' => 'npm ci'])],
            ['install' => 'pnpm install --frozen-lockfile']
        ))->render();

        $this->assertStringContainsString('pnpm install --frozen-lockfile', $rendered);
        $this->assertStringNotContainsString('npm ci', $rendered);
    }

    /**
     * The rendered block minus its `if` wrapper, its comments and its step
     * markers - what actually runs, in order.
     *
     * @return list<string>
     */
    private function runLines(string $rendered): array
    {
        $lines = [];
        foreach (preg_split('/\r?\n/', $rendered) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line === 'fi' || str_starts_with($line, 'if [ "$PA_PHASE"')
                || str_starts_with($line, '#') || str_starts_with($line, 'pa_step ')
            ) {
                continue;
            }
            // The skip marker names the step; the command is what matters here.
            $lines[] = (string) preg_replace("/ \|\| pa_skip .*/", ' || pa_skip', $line);
        }

        return $lines;
    }
}
