<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\CacheManager\PythonBaseImage;
use App\Lib\Deploy\Dind\DindImageStore;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Engine\ImageStore;
use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\Inner\HostCommands;
use App\System\Project\Dind\Inner\ImageSeeding;
use App\System\Project\Dind\Inner\SharedBaseImages;
use App\System\Project\Dind\InnerDocker;
use Tests\TestCase;

/**
 * Every way SharedBaseImages answers for the shared Python base and the PHP
 * paths around it: what it returns, what it builds where, and what it logs.
 */
class SharedBaseImagesPathsTest extends TestCase
{
    private const PYTHON = 'python:3.12-slim';

    private const PHP = 'php:8.3-apache-bookworm';

    /** @var list<string> */
    private array $onHost = [];

    /** @var list<string> */
    private array $inAccount = [];

    /** @var list<string> */
    private array $inStores = [];

    /** @var list<string> */
    private array $foreground = [];

    /** @var list<string> */
    private array $background = [];

    /** @var list<string> */
    private array $log = [];

    private ?string $buildFails = null;

    private bool $loadFills = true;

    private ?string $catalogue = null;

    protected function tearDown(): void
    {
        if ($this->catalogue !== null) {
            RuntimeImageCatalog::useConfig(null);
            RuntimeImageCatalog::flush();
            @unlink($this->catalogue);
        }
        parent::tearDown();
    }

    public function test_python_without_a_base_for_the_image_is_the_stock_image(): void
    {
        $this->assertNull($this->bases()->ensurePython('ghcr.io/acme/py:1', ['libpq-dev']));
        $this->assertSame([], $this->foreground);
        $this->assertSame([], $this->log);
    }

    public function test_python_the_account_holds_is_used_as_it_is(): void
    {
        $tag = $this->pythonTag();
        $this->inAccount = [$tag];

        $this->assertSame($tag, $this->bases()->ensurePython(self::PYTHON, ['libpq-dev']));
        $this->assertSame([], $this->foreground);
    }

    public function test_python_a_store_provides_is_used_as_it_is(): void
    {
        $tag = $this->pythonTag();
        $this->inStores = [$tag];

        $this->assertSame($tag, $this->bases()->ensurePython(self::PYTHON, ['libpq-dev']));
        $this->assertSame([], $this->foreground);
    }

    public function test_python_the_host_never_built_is_built_now_and_said_so(): void
    {
        $tag = $this->pythonTag();

        $this->assertSame($tag, $this->bases()->ensurePython(self::PYTHON, ['libpq-dev']));
        $this->assertSame(['build ' . $tag, 'load ' . $tag], $this->foreground);
        $this->assertSame([
            "info Shared Python base image {$tag} has not been built on this host yet; building it now. "
                . 'Later deploys on this Python version and dependency set load it in seconds. '
                . 'Prewarming can keep it built ahead of time.',
            "dim Preparing shared Python base image {$tag}",
        ], $this->log);
        $this->assertSame([], $this->background);
    }

    public function test_python_the_host_holds_is_loaded_into_the_account(): void
    {
        $tag = $this->pythonTag();
        $this->onHost = [$tag];

        $this->assertSame($tag, $this->bases()->ensurePython(self::PYTHON, ['libpq-dev']));
        $this->assertSame(['build ' . $tag, 'load ' . $tag], $this->foreground);
        $this->assertSame(["info Preparing shared Python base image {$tag}"], $this->log);
    }

    public function test_python_that_fails_to_build_is_the_stock_image_after_the_disk_check(): void
    {
        $tag = $this->pythonTag();
        $this->buildFails = 'E: Unable to locate package libpq-dev';

        $this->assertNull($this->bases()->ensurePython(self::PYTHON, ['libpq-dev']));
        $this->assertSame([
            "info Shared Python base image {$tag} has not been built on this host yet; building it now. "
                . 'Later deploys on this Python version and dependency set load it in seconds. '
                . 'Prewarming can keep it built ahead of time.',
            "dim Preparing shared Python base image {$tag}",
            'disk-check E: Unable to locate package libpq-dev',
            'info Shared Python base unavailable, using the stock image: E: Unable to locate package libpq-dev',
        ], $this->log);
    }

    public function test_python_loaded_but_not_in_the_account_is_the_stock_image(): void
    {
        $this->onHost = [$this->pythonTag()];
        $this->loadFills = false;

        $this->assertNull($this->bases()->ensurePython(self::PYTHON, ['libpq-dev']));
    }

    public function test_python_a_catalogue_does_not_wait_for_is_built_in_the_background(): void
    {
        $this->catalogueWith('python', false);
        $tag = $this->pythonTag();

        $this->assertNull($this->bases()->ensurePython(self::PYTHON, ['libpq-dev']));
        $this->assertSame(['build ' . $tag], $this->background);
        $this->assertSame([], $this->foreground);
        $this->assertSame(
            ["info Building shared Python base image {$tag} in the background; this deploy uses the stock image"],
            $this->log
        );
    }

    public function test_php_with_no_shared_base_at_all_runs_the_stock_image(): void
    {
        $this->assertSame(['tag' => null, 'baked' => []], $this->bases()->ensurePhp('ghcr.io/acme/php:8.3', ['grpc']));
        $this->assertSame([], $this->foreground);
        $this->assertSame([], $this->background);
    }

    public function test_php_a_catalogue_cannot_run_falls_back_to_the_stock_image(): void
    {
        $this->catalogueWith('php', false);
        $this->buildFails = 'E: build broke';

        $this->assertSame(['tag' => null, 'baked' => []], $this->bases()->ensurePhp(self::PHP));
        $this->assertContains(
            'info Shared PHP base image unavailable, compiling extensions in the account: E: build broke',
            $this->log
        );
    }

    public function test_php_an_unsafe_tag_is_never_provided(): void
    {
        $this->assertFalse($this->bases()->providePhp('bad tag;rm', self::PHP));
        $this->assertSame([], $this->foreground);
    }

    public function test_php_a_build_the_registry_refuses_fails_with_the_cause(): void
    {
        $this->buildFails = 'push failed: ' . DindImageStore::REGISTRY_DOWN;

        try {
            $this->bases()->providePhp((string) PhpBaseImage::tag(self::PHP), self::PHP);
            $this->fail('the stock image cannot serve a PHP app');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                'The image cache (panelalpha-cache-registry or its writer) is not running, and a PHP deploy '
                . 'cannot get its base image without it. Start it with `docker compose up -d cache-registry cache-registry-writer`.',
                $e->getMessage()
            );
        }
        $this->assertSame('disk-check push failed: ' . DindImageStore::REGISTRY_DOWN, end($this->log));
    }

    public function test_php_a_build_that_fails_otherwise_says_so_and_returns_false(): void
    {
        $tag = (string) PhpBaseImage::tag(self::PHP);
        $this->buildFails = 'E: Unable to locate package imagick';

        $this->assertFalse($this->bases()->providePhp($tag, self::PHP));
        $this->assertSame([
            "info Shared PHP base image {$tag} is not on this host yet; pulling the published copy, or building it now. "
                . 'Later deploys on this PHP version load it in seconds. Prewarming can keep it built ahead of time.',
            "dim Preparing shared PHP base image {$tag}",
            'disk-check E: Unable to locate package imagick',
            'info Shared PHP base image unavailable, compiling extensions in the account: E: Unable to locate package imagick',
        ], $this->log);
    }

    public function test_php_the_announcement_names_the_packages_and_required_extensions(): void
    {
        $tag = (string) PhpBaseImage::tag(self::PHP, ['tidy'], ['ffmpeg']);

        $this->assertTrue($this->bases()->providePhp($tag, self::PHP, ['tidy'], ['ffmpeg'], ['tidy']));
        $this->assertSame(
            "info Shared PHP base image {$tag} has not been built on this host yet; building it now with ffmpeg, "
                . 'since this project requires tidy. Later deploys on this PHP version load it in seconds. '
                . 'Prewarming can keep it built ahead of time.',
            $this->log[0]
        );
    }

    public function test_php_a_variant_the_host_holds_is_not_announced(): void
    {
        $tag = (string) PhpBaseImage::tag(self::PHP, ['grpc']);
        $this->onHost = [$tag];

        $this->assertTrue($this->bases()->providePhp($tag, self::PHP, ['grpc']));
        $this->assertSame(["info Preparing shared PHP base image {$tag}"], $this->log);
        $this->assertSame(['build ' . $tag, 'load ' . $tag], $this->foreground);
    }

    public function test_php_a_variant_the_host_never_built_is_deferred(): void
    {
        $tag = (string) PhpBaseImage::tag(self::PHP, ['grpc']);

        $this->assertFalse($this->bases()->providePhp($tag, self::PHP, ['grpc']));
        $this->assertSame(['build ' . $tag], $this->background);
        $this->assertSame(
            ["info Building shared PHP base image {$tag} in the background; this deploy uses the stock image"],
            $this->log
        );
    }

    private function pythonTag(): string
    {
        return (string) PythonBaseImage::tag(self::PYTHON, ['libpq-dev']);
    }

    private function catalogueWith(string $runtime, bool $runnable): void
    {
        $yaml = (string) file_get_contents(base_path('../config/core/images.yaml'));
        $start = strpos($yaml, "\n  {$runtime}:\n");
        $this->assertNotFalse($start);
        $at = strpos($yaml, 'runnable: true', (int) $start);
        $this->assertNotFalse($at);
        $yaml = substr_replace($yaml, 'runnable: ' . ($runnable ? 'true' : 'false'), (int) $at, strlen('runnable: true'));
        $this->catalogue = (string) tempnam(sys_get_temp_dir(), 'pa-images-');
        file_put_contents($this->catalogue, $yaml);
        RuntimeImageCatalog::useConfig($this->catalogue);
        RuntimeImageCatalog::flush();
        $this->assertSame($runnable, RuntimeImageCatalog::runnable($runtime));
    }

    private function bases(): SharedBaseImages
    {
        $store = $this->createStub(ImageStore::class);
        $store->method('hostBuildArgv')->willReturnCallback(fn (string $tag): array => ['build', $tag]);
        $store->method('hostBuildInBackgroundArgv')->willReturnCallback(fn (string $tag): array => ['build', $tag]);
        $store->method('loadFromHostArgv')->willReturnCallback(
            fn (EngineAccount $account, string $tag): array => ['load', $tag]
        );
        $store->method('hostImageInspectArgv')->willReturnCallback(fn (string $image): array => ['inspect', $image]);

        $seeding = $this->createStub(ImageSeeding::class);
        $seeding->method('hasImage')->willReturnCallback(fn (string $image): bool => in_array($image, $this->inAccount, true));
        $seeding->method('provideFromStores')->willReturnCallback(fn (string $image): bool => in_array($image, $this->inStores, true));

        $host = $this->createStub(HostCommands::class);
        $host->method('inBackground')->willReturnCallback(function (array $argv): void {
            $this->background[] = implode(' ', $argv);
        });
        $host->method('cancellable')->willReturnCallback(function (array $argv): void {
            $cmd = implode(' ', $argv);
            if ($this->buildFails !== null && str_starts_with($cmd, 'build ')) {
                throw new \RuntimeException($this->buildFails);
            }
            $this->foreground[] = $cmd;
            if ($this->loadFills && str_starts_with($cmd, 'load ')) {
                $this->inAccount[] = substr($cmd, 5);
            }
        });
        foreach (['logInfo' => 'info', 'logDim' => 'dim', 'logWarn' => 'warn', 'failDeployIfDiskFull' => 'disk-check'] as $method => $label) {
            $host->method($method)->willReturnCallback(function (string $message) use ($label): void {
                $this->log[] = $label . ' ' . $message;
            });
        }

        $system = $this->createStub(System::class);
        $system->method('execOnHost')->willReturnCallback(function (array $argv): string {
            if (! in_array($argv[1], $this->onHost, true)) {
                throw new \RuntimeException('No such image');
            }

            return '[]';
        });

        $user = new User();
        $user->username = 'alice';

        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('userModel')->willReturn($user);
        $dind->method('engineAccount')->willReturn(new EngineAccount('alice', '/home/alice'));

        $inner = $this->createStub(InnerDocker::class);
        $inner->method('imageStore')->willReturn($store);
        $inner->method('seeding')->willReturn($seeding);
        $inner->method('host')->willReturn($host);
        $inner->method('dind')->willReturn($dind);

        return new SharedBaseImages($inner);
    }
}
