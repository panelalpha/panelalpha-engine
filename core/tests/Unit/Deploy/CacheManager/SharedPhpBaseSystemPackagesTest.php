<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Engine\ImageStore;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\Inner\HostCommands;
use App\System\Project\Dind\Inner\ImageSeeding;
use App\System\Project\Dind\Inner\SharedBaseImages;
use App\System\Project\Dind\InnerDocker;
use PHPUnit\Framework\TestCase;

/**
 * A PHP base variant carrying `system_packages:` must be waited for, never
 * swapped for the plain base: the plain base has no ffmpeg, and ClipBucket on
 * it accepts every upload and converts none (engine#193).
 */
class SharedPhpBaseSystemPackagesTest extends TestCase
{
    private const PHP = 'php:8.3-apache-bookworm';

    /** @var list<string> images the host holds */
    private array $onHost = [];

    /** @var list<string> images the account holds */
    private array $inAccount = [];

    /** @var list<string> */
    private array $foreground = [];

    /** @var list<string> */
    private array $background = [];

    private bool $buildFails = false;

    public function test_a_package_variant_the_host_never_built_is_built_now(): void
    {
        $tag = (string) PhpBaseImage::tag(self::PHP, [], ['ffmpeg']);
        $this->onHost = [(string) PhpBaseImage::tag(self::PHP)];

        $result = $this->bases()->ensurePhp(self::PHP, [], ['ffmpeg']);

        $this->assertSame(['tag' => $tag, 'baked' => []], $result);
        $this->assertSame(['build ' . $tag, 'load ' . $tag], $this->foreground);
        $this->assertSame([], $this->background, 'deferring it would deploy this one on the plain base');
    }

    public function test_extras_and_packages_are_one_variant_built_now(): void
    {
        $tag = (string) PhpBaseImage::tag(self::PHP, ['grpc'], ['ffmpeg']);

        $result = $this->bases()->ensurePhp(self::PHP, ['grpc'], ['ffmpeg']);

        $this->assertSame(['tag' => $tag, 'baked' => ['grpc']], $result);
        $this->assertSame([], $this->background);
    }

    public function test_a_variant_that_cannot_be_built_fails_the_deploy(): void
    {
        $this->onHost = [(string) PhpBaseImage::tag(self::PHP)];
        $this->inAccount = [(string) PhpBaseImage::tag(self::PHP)];
        $this->buildFails = true;

        try {
            $this->bases()->ensurePhp(self::PHP, [], ['ffmpeg']);
            $this->fail('a deploy without ffmpeg must not go green on the plain base');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ffmpeg', $e->getMessage());
            $this->assertStringContainsString('system_packages', $e->getMessage());
        }
    }

    public function test_a_custom_php_image_with_packages_fails_the_deploy(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not an image this host builds one from/');
        $this->bases()->ensurePhp('ghcr.io/acme/php:8.3', [], ['ffmpeg']);
    }

    public function test_an_account_that_has_the_variant_loads_nothing(): void
    {
        $tag = (string) PhpBaseImage::tag(self::PHP, [], ['ffmpeg']);
        $this->inAccount = [$tag];

        $this->assertSame(['tag' => $tag, 'baked' => []], $this->bases()->ensurePhp(self::PHP, [], ['ffmpeg']));
        $this->assertSame([], $this->foreground);
    }

    /** Unchanged: an extension variant is still deferred, and this deploy uses the plain base. */
    public function test_an_extras_only_variant_is_still_deferred(): void
    {
        $plain = (string) PhpBaseImage::tag(self::PHP);
        $this->onHost = [$plain];

        $result = $this->bases()->ensurePhp(self::PHP, ['grpc']);

        $this->assertSame(['tag' => $plain, 'baked' => []], $result);
        $this->assertSame(['build ' . PhpBaseImage::tag(self::PHP, ['grpc'])], $this->background);
    }

    /**
     * Wallabag requires ext-tidy: on the plain base the host composer install
     * aborts, so the first deploy after a base bump failed until the
     * background build finished minutes later.
     */
    public function test_a_variant_carrying_a_required_extension_is_built_now(): void
    {
        $tag = (string) PhpBaseImage::tag(self::PHP, ['tidy']);
        $this->onHost = [(string) PhpBaseImage::tag(self::PHP)];

        $result = $this->bases()->ensurePhp(self::PHP, ['tidy'], [], ['iconv', 'tidy']);

        $this->assertSame(['tag' => $tag, 'baked' => ['tidy']], $result);
        $this->assertSame(['build ' . $tag, 'load ' . $tag], $this->foreground);
        $this->assertSame([], $this->background);
    }

    private function bases(): SharedBaseImages
    {
        $store = $this->createStub(ImageStore::class);
        $store->method('hostBuildCommand')->willReturnCallback(fn (string $tag): string => 'build ' . $tag);
        $store->method('loadFromHostCommand')->willReturnCallback(
            fn (EngineAccount $account, string $tag): string => 'load ' . $tag
        );
        $store->method('hostImageInspectArgv')->willReturnCallback(fn (string $image): array => ['inspect', $image]);

        $seeding = $this->createStub(ImageSeeding::class);
        $seeding->method('hasImage')->willReturnCallback(fn (string $image): bool => in_array($image, $this->inAccount, true));

        $host = $this->createStub(HostCommands::class);
        $host->method('inBackground')->willReturnCallback(function (string $script): void {
            $this->background[] = $script;
        });
        $host->method('cancellable')->willReturnCallback(function (array|string $cmd): void {
            $cmd = (string) $cmd;
            if ($this->buildFails && str_starts_with($cmd, 'build ')) {
                throw new \RuntimeException('E: Unable to locate package ffmpeg');
            }
            $this->foreground[] = $cmd;
            if (str_starts_with($cmd, 'load ')) {
                $this->inAccount[] = substr($cmd, 5);
            }
        });

        $system = $this->createStub(System::class);
        $system->method('execOnHost')->willReturnCallback(function (array $argv): string {
            if (!in_array($argv[1], $this->onHost, true)) {
                throw new \RuntimeException('No such image');
            }

            return '[]';
        });

        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('engineAccount')->willReturn(new EngineAccount('alice', '/home/alice'));

        $inner = $this->createStub(InnerDocker::class);
        $inner->method('imageStore')->willReturn($store);
        $inner->method('seeding')->willReturn($seeding);
        $inner->method('host')->willReturn($host);
        $inner->method('dind')->willReturn($dind);

        return new SharedBaseImages($inner);
    }
}
