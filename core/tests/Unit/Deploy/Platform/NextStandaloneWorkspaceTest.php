<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\DetectProjectStrategy;
use PHPUnit\Framework\TestCase;

/**
 * storyden: a Go API at the root, the Next frontend in web/ with its own
 * package.json and lockfile, and no root package.json. Node was resolved at
 * the root, found nothing, and detection threw "Runtime 'node' is required
 * but could not be resolved".
 */
class NextStandaloneWorkspaceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pa-next-ws-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/web', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function test_a_next_app_in_web_without_a_root_package_json_becomes_the_app_root(): void
    {
        file_put_contents($this->dir . '/go.mod', "module example.com/app\n\ngo 1.22\n");
        file_put_contents($this->dir . '/.nvmrc', "v24.x\n");
        file_put_contents($this->dir . '/web/package.json', json_encode([
            'scripts' => ['build' => 'next build', 'start' => 'next start'],
            'dependencies' => ['next' => '16.0.0', 'react' => '19.0.0'],
            'engines' => ['node' => '24.x'],
        ]));
        file_put_contents($this->dir . '/web/package-lock.json', '{"lockfileVersion":3,"packages":{}}');

        $decision = DetectProjectStrategy::detect($this->dir);

        $this->assertSame('nextjs', $decision['strategy']);
        $this->assertSame('web', $decision['app_root']);
        $this->assertSame('', $decision['workspace_relative']);
        $this->assertSame('.next', $decision['output_directory']);
        $this->assertStringStartsWith('node:24', (string) $decision['image']);
        $this->assertSame('npm run build', $decision['build_command']);
    }

    /** A root that installs the workspace keeps the root as the app. */
    public function test_a_root_workspace_is_not_moved(): void
    {
        mkdir($this->dir . '/apps/web', 0777, true);
        file_put_contents($this->dir . '/package.json', json_encode([
            'private' => true,
            'workspaces' => ['apps/*'],
        ]));
        file_put_contents($this->dir . '/package-lock.json', '{"lockfileVersion":3,"packages":{}}');
        file_put_contents($this->dir . '/apps/web/package.json', json_encode([
            'name' => 'web',
            'dependencies' => ['next' => '16.0.0'],
        ]));

        $decision = DetectProjectStrategy::detect($this->dir);

        $this->assertSame('nextjs', $decision['strategy']);
        $this->assertSame('', (string) ($decision['app_root'] ?? ''));
        $this->assertSame('apps/web', $decision['workspace_relative']);
    }
}
