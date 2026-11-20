<?php

namespace Tests\Integration\Support;

use App\Support\DockerHubLogin;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Support\EnvFile;
use App\System\Privileged\Root;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\RecordingSystem;

/**
 * Saving or clearing the registry-proxy login writes the engine's `.env`, keeps
 * the previous one beside it, and recreates registry-proxy alone.
 */
class DockerHubLoginSaveTest extends TestCase
{
    private string $dir;

    private RecordingSystem $system;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/dockerhub-login-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $dir = $this->dir;
        $this->system = new class($dir) extends RecordingSystem
        {
            public function __construct(private readonly string $dir) {}

            public function engineDirPath(): string
            {
                return $this->dir;
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null
            ): Process {
                return $this->runProcess($cmd, $env, $timeout);
            }
        };
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->dir);
    }

    public function test_a_saved_login_replaces_the_keys_and_keeps_a_backup(): void
    {
        $before = "COMPOSE_PROFILES=full\nREGISTRY_PROXY_USERNAME=old\nREGISTRY_PROXY_PASSWORD=old-token\n";
        file_put_contents($this->dir.'/.env', $before);

        (new DockerHubLogin($this->system))->save('me', 'dckr_pat_abcdefgh');

        $this->assertSame(
            "COMPOSE_PROFILES=full\nREGISTRY_PROXY_USERNAME=me\nREGISTRY_PROXY_PASSWORD=dckr_pat_abcdefgh\n",
            file_get_contents($this->dir.'/.env')
        );
        $this->assertSame($before, file_get_contents($this->dir.'/.env'.EnvFile::BACKUP_SUFFIX));
        $this->assertCount(1, $this->system->commands);
        $this->assertSame('compose.engineUpWithoutDependencies', Root::askedAction($this->system->commands[0]));
        $this->assertContains('registry-proxy', Root::askedValues($this->system->commands[0]));
    }

    public function test_clearing_a_host_without_an_env_writes_empty_keys_and_no_backup(): void
    {
        (new DockerHubLogin($this->system))->clear();

        $this->assertSame("REGISTRY_PROXY_USERNAME=\nREGISTRY_PROXY_PASSWORD=\n", file_get_contents($this->dir.'/.env'));
        $this->assertFileDoesNotExist($this->dir.'/.env'.EnvFile::BACKUP_SUFFIX);
        $this->assertCount(1, $this->system->commands);
    }

    public function test_a_proxy_that_fails_to_recreate_is_reported_after_the_file_is_written(): void
    {
        $this->system->exitCode = 1;
        $this->system->output = 'compose failed';

        try {
            (new DockerHubLogin($this->system))->save('me', 'dckr_pat_abcdefgh');
            $this->fail('a failed recreate must raise');
        } catch (\Exception $e) {
            $this->assertStringContainsString('compose failed', $e->getMessage());
        }

        $this->assertStringContainsString('REGISTRY_PROXY_USERNAME=me', (string) file_get_contents($this->dir.'/.env'));
    }
}
