<?php

namespace Tests\Unit\System\Project\Dind;

use App\System\Project\Dind;
use App\System\Project\Dind\AppLauncher;
use App\System\Project\Dind\InnerDocker;
use PHPUnit\Framework\TestCase;

/**
 * The pre-build reclaim is `docker system prune -af`. Run after the preloads it
 * deleted the PHP base the deploy had just pulled (pmwiki on a dev host, a
 * host at 88% disk), and compose then looked for panelalpha/php on Docker Hub.
 */
class AppLauncherReclaimOrderTest extends TestCase
{
    public function test_the_reclaim_runs_before_any_image_is_preloaded(): void
    {
        $calls = [];
        $inner = $this->createStub(InnerDocker::class);
        foreach (['reclaimStorageIfNeeded', 'ensureImage', 'preloadFrameworkBaseImages', 'preloadComposeImages'] as $method) {
            $inner->method($method)->willReturnCallback(function () use (&$calls, $method): void {
                $calls[] = $method;
            });
        }
        $project = $this->createStub(Dind::class);
        $project->method('innerDocker')->willReturn($inner);
        $project->method('userAppComposeFileToRun')->willReturn('/home/acme/project/docker-compose.panelalpha.yml');

        (new \ReflectionMethod(AppLauncher::class, 'preloadImages'))->invoke(
            new AppLauncher($project),
            'dockerfile',
            null,
            false,
            'node:22-bookworm-slim'
        );

        $this->assertSame(
            ['reclaimStorageIfNeeded', 'ensureImage', 'preloadFrameworkBaseImages', 'preloadComposeImages'],
            $calls
        );
    }
}
