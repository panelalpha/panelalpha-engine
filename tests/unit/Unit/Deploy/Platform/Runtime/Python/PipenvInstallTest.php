<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Python;

use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use PHPUnit\Framework\TestCase;

/**
 * A Pipfile project installs the lock it committed, not a check that the lock
 * is still current. JARR's Pipfile.lock hash lags its Pipfile, `pipenv install
 * --deploy` raised DeployException, and its own Dockerfile runs `pipenv sync`.
 */
class PipenvInstallTest extends TestCase
{
    public function test_a_committed_lock_is_synced_not_verified(): void
    {
        $command = PythonRuntime::installCommand(
            ['pipfile' => true, 'pipfile.lock' => true, 'pyproject.toml' => true],
            "[project]\nname = \"JARR\"\nrequires-python = \">=3.13\"\n"
        );

        $this->assertStringEndsWith('.venv/bin/pipenv sync', $command);
        $this->assertStringNotContainsString('--deploy', $command);
    }

    /** `pipenv sync` needs a lock; without one pipenv writes it and installs. */
    public function test_a_pipfile_without_a_lock_still_locks_and_installs(): void
    {
        $command = PythonRuntime::installCommand(['pipfile' => true]);

        $this->assertStringEndsWith('.venv/bin/pipenv install --deploy', $command);
    }
}
