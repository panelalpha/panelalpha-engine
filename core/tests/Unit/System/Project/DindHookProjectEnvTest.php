<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Recipe precheck/prepare hooks see the project's env vars, so a hook can refuse
 * a deploy for a missing token. The values travel in a 0600 file, not in argv.
 */
class DindHookProjectEnvTest extends TestCase
{
    /** @var list<list<string>> */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
    }

    /** @var array<string, string> target => contents of every file copied into place */
    private array $written = [];

    public function test_the_hook_runs_with_the_project_env_vars_sourced_from_a_private_file(): void
    {
        $dind = $this->dind(['FOO_TOKEN' => "it's \$2y\$10\$abc", 'PATH' => '/nope']);

        $dind->shell()->execAsUserWithProjectEnv(['bash', '-c', 'exec bash "$0" 1>&2', '/home/alice/.panelalpha-precheck.sh']);

        $this->assertCount(1, $this->written);
        $envFile = array_key_first($this->written);
        $this->assertStringStartsWith('/home/alice/.panelalpha-hook-env-', $envFile);
        $this->assertSame("FOO_TOKEN='it'\\''s \$2y\$10\$abc'\n", $this->written[$envFile], 'PATH is left to the shell');
        $this->assertContains(['sudo', 'chmod', '600', $envFile], $this->commands);

        $run = $this->commandContaining('su');
        $this->assertStringContainsString("'hook-env' '{$envFile}' 'bash' '-c' 'exec bash \"\$0\" 1>&2' '/home/alice/.panelalpha-precheck.sh'", end($run));
        foreach ($this->commands as $cmd) {
            $this->assertStringNotContainsString('abc', implode(' ', $cmd), 'no value in any argv');
        }
        $this->assertSame(['rm', '-f', $envFile], array_slice($this->commandContaining('rm'), -3));
    }

    public function test_without_env_vars_the_hook_runs_as_before(): void
    {
        $dind = $this->dind([]);

        $dind->shell()->execAsUserWithProjectEnv(['bash', '-c', 'true']);

        $this->assertSame([], $this->written);
        $last = end($this->commands);
        $this->assertSame("'bash' '-c' 'true'", end($last));
    }

    public function test_the_precheck_and_prepare_hooks_use_it(): void
    {
        foreach ([
            [\App\System\Project\Dind\PrepareFromSource::class, 'preCheck'],
            [\App\System\Project\Dind\Strategy\PrepareStage::class, 'execute'],
        ] as [$class, $method]) {
            $reflected = new \ReflectionMethod($class, $method);
            $lines = (array) file((string) $reflected->getFileName());
            $body = implode('', array_slice($lines, $reflected->getStartLine() - 1, $reflected->getEndLine() - $reflected->getStartLine() + 1));

            $this->assertStringContainsString('execAsUserWithProjectEnv(', $body, "{$class}::{$method}");
        }
    }

    /** @return list<string> */
    private function commandContaining(string $word): array
    {
        foreach ($this->commands as $cmd) {
            if (in_array($word, $cmd, true)) {
                return $cmd;
            }
        }
        $this->fail("no `{$word}` command was run");
    }

    /** @param array<string, string> $envVars */
    private function dind(array $envVars): Dind
    {
        $model = new ModelsUser;
        $model->username = 'alice';
        $model->setDetails(['template' => 'dind', 'UID' => 1000, 'GID' => 1000, 'env_vars' => $envVars]);

        $commands = &$this->commands;
        $written = &$this->written;
        $system = new class($commands, $written) extends System
        {
            public function __construct(private array &$commands, private array &$written) {}

            public function homesDirPath(): string
            {
                return '/home';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = (array) $cmd;
                $this->commands[] = $cmd;
                if (($cmd[0] ?? '') === 'sudo' && ($cmd[1] ?? '') === 'cp') {
                    $this->written[$cmd[3]] = (string) file_get_contents($cmd[2]);
                }

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $process = new Process(['true']);
                $process->run();

                return $process;
            }
        };

        $runtime = (new ProjectAggregate($system, $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }
}
