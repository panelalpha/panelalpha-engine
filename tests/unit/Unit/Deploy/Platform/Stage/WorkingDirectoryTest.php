<?php

namespace Tests\Unit\Deploy\Platform\Stage;

use App\Lib\Deploy\Platform\Stage\WorkingDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Running a manifest command somewhere other than the app root - a monorepo
 * workspace, mostly.
 *
 * The two wrappers differ for a reason that only shows up on `docker stop`:
 * a subshell around the serve command would sit between the init process and
 * the server, so Docker's SIGTERM would reach the shell, the server would
 * never hear it, and every deploy would wait out the ten-second kill timer.
 */
class WorkingDirectoryTest extends TestCase
{
    public function test_a_step_is_wrapped_in_a_subshell(): void
    {
        // A subshell, so the cd does not leak into the next step.
        $this->assertSame(
            '( cd apps/web && npm run build )',
            WorkingDirectory::wrap('npm run build', 'apps/web')
        );
    }

    public function test_a_serve_command_keeps_exec_in_front_of_the_server(): void
    {
        $this->assertSame(
            "sh -c 'cd apps/web && exec npm start'",
            WorkingDirectory::wrapForExec('npm start', 'apps/web')
        );
    }

    public function test_the_app_root_needs_no_wrapping_at_all(): void
    {
        foreach ([null, '', '.'] as $workdir) {
            $this->assertSame('npm start', WorkingDirectory::wrap('npm start', $workdir));
            $this->assertSame('npm start', WorkingDirectory::wrapForExec('npm start', $workdir));
        }
    }

    public function test_the_exec_wrapper_quotes_the_whole_command(): void
    {
        // The command reaches sh as a single argument, so a workdir or a
        // command carrying a quote cannot break out of it.
        $this->assertSame(
            "sh -c 'cd apps/we'\\''b && exec npm start'",
            WorkingDirectory::wrapForExec('npm start', "apps/we'b")
        );
    }
}
