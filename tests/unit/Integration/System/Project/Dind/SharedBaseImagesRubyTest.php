<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\CacheManager\NodeBuildImage;
use App\Lib\Deploy\CacheManager\RubyBaseImage;
use App\Lib\Deploy\CacheManager\RustBuildToolsImage;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Engine\ImageStore;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\Inner\HostCommands;
use App\System\Project\Dind\Inner\ImageSeeding;
use App\System\Project\Dind\Inner\SharedBaseImages;
use App\System\Project\Dind\InnerDocker;
use Tests\TestCase;

/**
 * The shared Ruby base, and the host-only build images and host copies around it: what each returns,
 * what it builds where, and what it logs.
 */
class SharedBaseImagesRubyTest extends TestCase
{
    private const RUBY = 'ruby:3.3-slim-bookworm';

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
    private array $hostExec = [];

    /** @var list<string> */
    private array $log = [];

    private ?string $buildFails = null;

    private ?string $ensureFails = null;

    private bool $loadFills = true;

    public function test_ruby_without_a_base_for_the_image_is_the_stock_image(): void
    {
        $this->assertNull($this->bases()->ensureRuby('ghcr.io/acme/ruby:3', ['libpq-dev']));
        $this->assertSame([], $this->foreground);
        $this->assertSame([], $this->log);
    }

    public function test_ruby_the_account_holds_is_used_as_it_is(): void
    {
        $tag = $this->rubyTag();
        $this->inAccount = [$tag];

        $this->assertSame($tag, $this->bases()->ensureRuby(self::RUBY, ['libpq-dev']));
        $this->assertSame([], $this->foreground);
    }

    public function test_ruby_a_store_provides_is_used_as_it_is(): void
    {
        $tag = $this->rubyTag();
        $this->inStores = [$tag];

        $this->assertSame($tag, $this->bases()->ensureRuby(self::RUBY, ['libpq-dev']));
        $this->assertSame([], $this->foreground);
    }

    public function test_ruby_the_host_never_built_is_built_in_the_background(): void
    {
        $tag = $this->rubyTag();

        $this->assertNull($this->bases()->ensureRuby(self::RUBY, ['libpq-dev']));
        $this->assertSame(['build ' . $tag], $this->background);
        $this->assertSame([], $this->foreground);
        $this->assertSame(
            ["info Building shared Ruby base image {$tag} in the background; this deploy uses the stock image"],
            $this->log
        );
    }

    public function test_ruby_the_host_holds_is_loaded_into_the_account(): void
    {
        $tag = $this->rubyTag();
        $this->onHost = [$tag];

        $this->assertSame($tag, $this->bases()->ensureRuby(self::RUBY, ['libpq-dev']));
        $this->assertSame(['build ' . $tag, 'load ' . $tag], $this->foreground);
        $this->assertSame(["info Preparing shared Ruby base image {$tag}"], $this->log);
    }

    public function test_ruby_that_fails_to_build_says_so_and_runs_the_stock_image(): void
    {
        $tag = $this->rubyTag();
        $this->onHost = [$tag];
        $this->buildFails = 'E: Unable to locate package libpq-dev';

        $this->assertNull($this->bases()->ensureRuby(self::RUBY, ['libpq-dev']));
        $this->assertSame(
            ["info Preparing shared Ruby base image {$tag}", "info Could not provide {$tag}: E: Unable to locate package libpq-dev"],
            $this->log
        );
    }

    public function test_ruby_loaded_but_not_in_the_account_is_the_stock_image(): void
    {
        $this->onHost = [$this->rubyTag()];
        $this->loadFills = false;

        $this->assertNull($this->bases()->ensureRuby(self::RUBY, ['libpq-dev']));
    }

    public function test_a_node_build_image_the_host_holds_is_used_without_a_build(): void
    {
        $tag = (string) NodeBuildImage::tag('gradle:9-jdk25', 'node:22-bookworm-slim');
        $this->onHost = [$tag];

        $this->assertSame($tag, $this->bases()->ensureNodeBuild('gradle:9-jdk25', 'node:22-bookworm-slim'));
        $this->assertSame([], $this->foreground);
        $this->assertSame(["info The build runs a JS tool; building in {$tag} (gradle:9-jdk25 with Node from node:22-bookworm-slim)"], $this->log);
    }

    public function test_a_rust_tools_image_the_host_holds_is_used_without_a_build(): void
    {
        $tag = (string) RustBuildToolsImage::tag('rust:1-bookworm', ['cmake', 'protobuf-compiler']);
        $this->onHost = [$tag];

        $this->assertSame($tag, $this->bases()->ensureRustBuild('rust:1-bookworm', ['cmake', 'protobuf-compiler']));
        $this->assertSame([], $this->foreground);
        $this->assertSame(["info The Rust build needs cmake, protobuf-compiler; building in {$tag}"], $this->log);
    }

    public function test_build_images_that_cannot_be_named_build_nothing(): void
    {
        $this->assertNull($this->bases()->ensureNodeBuild('gradle:9-jdk25', 'node;rm -rf /'));
        $this->assertNull($this->bases()->ensureRustBuild('rust:1-bookworm', []));
        $this->assertSame([], $this->foreground);
        $this->assertSame([], $this->log);
    }

    public function test_an_image_the_host_already_ensures_is_there(): void
    {
        $this->assertTrue($this->bases()->ensureOnHost('noop:1', [], []));
        $this->assertSame([], $this->hostExec);
    }

    public function test_a_host_copy_pulled_back_is_there(): void
    {
        $this->onHost = ['acme/base:1'];

        $this->assertTrue($this->bases()->ensureOnHost('acme/base:1', [], []));
        $this->assertSame(['ensure acme/base:1'], $this->hostExec);
        $this->assertSame([], $this->log);
    }

    public function test_a_host_copy_that_cannot_be_pulled_nor_rebuilt_is_missing(): void
    {
        $this->ensureFails = 'manifest unknown';

        $this->assertFalse($this->bases()->ensureOnHost('acme/base:1', [], []));
        $this->assertSame(['info Could not pull base image acme/base:1 back onto the host: manifest unknown'], $this->log);
        $this->assertSame([], $this->foreground);
    }

    public function test_an_unsafe_image_is_never_on_the_host(): void
    {
        $this->assertFalse($this->bases()->ensureOnHost('bad ref;rm', [], []));
        $this->assertSame(['ensure bad ref;rm'], $this->hostExec);
        $this->assertSame([], $this->foreground);
    }

    private function rubyTag(): string
    {
        $tag = RubyBaseImage::tag(self::RUBY, ['libpq-dev']);
        $this->assertNotNull($tag);

        return $tag;
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
        $store->method('hostEnsureImageArgv')->willReturnCallback(
            fn (string $image): array => $image === 'noop:1' ? ['true'] : ['ensure', $image]
        );

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
        $system->method('exec')->willReturnCallback(function (string|array $cmd): string {
            $this->hostExec[] = implode(' ', (array) $cmd);
            if ($this->ensureFails !== null) {
                throw new \RuntimeException($this->ensureFails);
            }

            return '';
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
