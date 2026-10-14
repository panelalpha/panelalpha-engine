<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Detect\DeployabilityCheck;
use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Source\GitUrl;
use App\Lib\Deploy\Source\ProjectArchive;
use PHPUnit\Framework\TestCase;

/**
 * Engine-only deploy contract (no panel): git URL handling, zip unwrap, detect.
 *
 * Smoke paths this locks:
 *   POST /api/users { git_repo, git_branch?, git_token? }
 *   POST /api/users { template: dind } then files/upload + deploy-archive
 */
class EngineDeployContractTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/engine-deploy-contract-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    public function test_git_token_uses_askpass_and_remote_stays_clean(): void
    {
        $remote = 'https://git.example.com/org/app.git';
        GitUrl::assertSafeForToken($remote);
        $command = GitUrl::withAskPass(
            ['git', 'clone', '--depth=1', $remote, '/home/user/project'],
            '/home/user/.panelalpha-git-askpass-test'
        );

        $this->assertStringNotContainsString('super-secret', implode(' ', $command));
        $this->assertContains($remote, $command);
        $this->assertSame($remote, GitUrl::sanitize($remote));
    }

    public function test_zip_of_folder_then_dockerfile_detect(): void
    {
        mkdir($this->tmpDir . '/my-app');
        file_put_contents($this->tmpDir . '/my-app/Dockerfile', "FROM nginx\nEXPOSE 8080\n");
        file_put_contents($this->tmpDir . '/my-app/index.html', '<h1>nope</h1>');
        file_put_contents($this->tmpDir . '/my-app/package.json', '{}');

        $root = ProjectArchive::resolveProjectRoot($this->tmpDir, "d my-app\0");
        $decision = DetectProjectStrategy::detect($root);

        $this->assertSame($this->tmpDir . '/my-app', $root);
        $this->assertSame(Strategies::DOCKERFILE, $decision['strategy']);
        $this->assertSame(8080, $decision['port_hint']);
    }

    public function test_flat_zip_static_detect(): void
    {
        file_put_contents($this->tmpDir . '/index.html', '<h1>hi</h1>');

        $root = ProjectArchive::resolveProjectRoot($this->tmpDir, "f index.html\0");
        $decision = DetectProjectStrategy::detect($root);

        $this->assertSame(Strategies::STATIC, $decision['strategy']);
        DeployabilityCheck::assert($decision, $root);
    }

    public function test_zip_of_folder_then_nextjs_not_railpack(): void
    {
        mkdir($this->tmpDir . '/web');
        file_put_contents($this->tmpDir . '/web/package.json', json_encode([
            'dependencies' => ['next' => '15.0.0'],
            'scripts' => ['build' => 'next build', 'start' => 'next start'],
        ]));
        file_put_contents($this->tmpDir . '/web/next.config.mjs', 'export default {};');

        $root = ProjectArchive::resolveProjectRoot($this->tmpDir, "d web\0");
        $decision = DetectProjectStrategy::detect($root);

        $this->assertSame($this->tmpDir . '/web', $root);
        $this->assertSame(Strategies::NEXTJS, $decision['strategy']);
        $this->assertSame(PlatformManifest::RUNTIME_NODE, $decision['runtime']);
        DeployabilityCheck::assert($decision, $root);
    }

    private function removeDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
