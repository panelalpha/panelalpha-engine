<?php

namespace Tests\Unit\System\Project\Dind;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\Dind\DindImageStore;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\Inner\HostCommands;
use App\System\Project\Dind\Inner\SharedBaseImages;
use PHPUnit\Framework\TestCase;

/**
 * The host composer pass runs our base image by its plain name. The host prune
 * can remove it while the cache registry no longer holds it either, and
 * docker.io does not publish our tags, so the deploy used to die on the
 * docker run. It must rebuild the base itself and carry on.
 */
class HostCompilePrunedBaseTest extends TestCase
{
    private const IMAGE = 'panelalpha/php:8.3-apache-bookworm-pa20260924';

    private const VARIANT = 'panelalpha/php:8.3-apache-bookworm-pa20260924-xdeadbeef';

    public function test_a_missing_variant_is_rebuilt_with_its_extensions(): void
    {
        [$bases, $commands] = $this->bases(hostHasAfterPull: false);

        $bases->ensureOnHost(self::VARIANT, ['vips'], []);

        $this->assertCount(1, $commands->built);
        $this->assertStringContainsString(self::VARIANT, $commands->built[0]);
        $this->assertStringContainsString('vips', $commands->built[0]);
    }

    public function test_our_image_is_rebuilt_when_the_pull_back_finds_nothing(): void
    {
        [$bases, $commands] = $this->bases(hostHasAfterPull: false);

        $bases->ensureOnHost(self::IMAGE, [], []);

        $this->assertCount(1, $commands->built);
        $this->assertStringContainsString('docker build', $commands->built[0]);
        $this->assertStringContainsString('docker push', $commands->built[0]);
        $this->assertStringContainsString('flock -x', $commands->built[0]);
    }

    public function test_our_image_is_not_rebuilt_when_the_pull_back_works(): void
    {
        [$bases, $commands] = $this->bases(hostHasAfterPull: true);

        $bases->ensureOnHost(self::IMAGE, [], []);

        $this->assertSame([], $commands->built);
    }

    public function test_a_public_image_is_never_built(): void
    {
        [$bases, $commands] = $this->bases(hostHasAfterPull: false);

        $bases->ensureOnHost('composer:2', [], []);

        $this->assertSame([], $commands->built);
    }

    public function test_a_failed_rebuild_is_surfaced_with_its_reason(): void
    {
        [$bases] = $this->bases(hostHasAfterPull: false, buildFails: true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/rebuilding it failed.*Unable to locate package imagick/s');

        $bases->ensureOnHost(self::IMAGE, [], []);
    }

    /**
     * The registry writer stopped or full: the image was built, only its push
     * failed. The host build runs on the host's copy, so the deploy goes on
     * and says why the registry does not have it.
     */
    public function test_a_push_that_fails_after_a_good_build_is_a_warning_not_a_failed_rebuild(): void
    {
        [$bases, $commands] = $this->bases(hostHasAfterPull: false, afterBuild: new \Exception('push refused: registry-writer not running'));

        $bases->ensureOnHost(self::IMAGE, [], []);

        $this->assertCount(1, $commands->built);
        $this->assertCount(1, $commands->warnings);
        $this->assertStringContainsString('publishing it to the cache registry failed', $commands->warnings[0]);
        $this->assertStringContainsString('push refused: registry-writer not running', $commands->warnings[0]);
    }

    public function test_a_deploy_cancelled_after_the_build_still_stops(): void
    {
        [$bases, $commands] = $this->bases(hostHasAfterPull: false, afterBuild: new DeployCancelledException('Deployment cancelled by user'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/rebuilding it failed.*cancelled/s');

        try {
            $bases->ensureOnHost(self::IMAGE, [], []);
        } finally {
            $this->assertSame([], $commands->warnings);
        }
    }

    /**
     * @param  \Exception|null  $afterBuild  thrown once the image is on the host, as a failed push is
     * @return array{SharedBaseImages, object}
     */
    private function bases(bool $hostHasAfterPull, bool $buildFails = false, ?\Exception $afterBuild = null): array
    {
        $commands = new class
        {
            /** @var list<string> */
            public array $built = [];

            /** @var list<string> */
            public array $warnings = [];

            public bool $hostHas = false;
        };
        $commands->hostHas = $hostHasAfterPull;

        $system = $this->createStub(System::class);
        $system->method('execOnHost')->willReturnCallback(
            function (string|array $cmd) use ($commands): string {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                if (str_starts_with($line, 'docker image inspect') && ! $commands->hostHas) {
                    throw new \Exception('Error: No such image');
                }

                return '';
            }
        );

        $host = $this->createStub(HostCommands::class);
        $host->method('cancellable')->willReturnCallback(
            function (string|array $cmd) use ($commands, $buildFails, $afterBuild): void {
                if ($buildFails) {
                    throw new \Exception('E: Unable to locate package imagick');
                }
                $commands->built[] = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                if ($afterBuild !== null) {
                    $commands->hostHas = true;
                    throw $afterBuild;
                }
            }
        );
        $host->method('logWarn')->willReturnCallback(
            function (string $message) use ($commands): void {
                $commands->warnings[] = $message;
            }
        );

        $project = $this->createStub(Dind::class);
        $project->method('system')->willReturn($system);

        $inner = $this->createStub(InnerDocker::class);
        $inner->method('imageStore')->willReturn(new DindImageStore());
        $inner->method('host')->willReturn($host);
        $inner->method('dind')->willReturn($project);

        return [new SharedBaseImages($inner), $commands];
    }
}
