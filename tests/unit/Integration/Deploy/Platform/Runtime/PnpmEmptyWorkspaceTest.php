<?php

namespace Tests\Integration\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use PHPUnit\Framework\TestCase;

/**
 * An empty pnpm-workspace.yaml declares no build policy, so the install still allows dependency builds.
 */
class PnpmEmptyWorkspaceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-pnpm-empty-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_an_empty_workspace_file_keeps_the_allow_all_builds_flag(): void
    {
        file_put_contents($this->dir.'/pnpm-workspace.yaml', '');

        $command = JsPackageManager::installCommand('pnpm', ['pnpm-lock.yaml' => true], ['packageManager' => 'pnpm@10.20.0'], $this->dir);

        $this->assertStringContainsString('--dangerously-allow-all-builds', $command);
    }
}
