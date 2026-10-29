<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\Strategies;
use App\Models\User;
use App\System\Project\Dind;
use App\System\Project\Dind\CopiedImages;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\Inner\ImageSeeding;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\RegistryLogin;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Dind\Strategy\RailpackStrategy;
use Tests\TestCase;
use Tests\Unit\System\Project\LocalHostSystem;

/**
 * A staging, clone or push target has an image store of its own, so before
 * `compose create` asks, every image its copied run file names is fetched the
 * way a deploy fetches it, or built again from the copied tree, and one of
 * ours that cannot be had is named instead of reported as a Docker Hub 404.
 */
class CopiedImagesTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $calls = [];

    /** @var list<string> */
    private array $present = [];

    /** @var list<string> */
    private array $missing = [];

    private bool $railpackBuilds = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-copied-images-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/project', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_php_copy_gets_the_shared_base_and_the_compose_images_before_create(): void
    {
        $base = (string) PhpBaseImage::tag('php:8.3-apache-bookworm');
        $this->writeRunFile("services:\n  app:\n    image: '{$base}'\n");

        $this->images($this->user(Strategies::PHP, 'php', $base))->provide($this->path());

        $this->assertSame(["ensure {$base}", 'compose ' . $this->path()], $this->calls);
    }

    public function test_a_generated_build_gets_its_framework_bases(): void
    {
        $this->writeRunFile("services:\n  app:\n    build: .\n");

        $this->images($this->user(Strategies::DOCKERFILE, null, null))->provide($this->path());

        $this->assertSame(['framework ' . Strategies::DOCKERFILE, 'compose ' . $this->path()], $this->calls);
    }

    public function test_one_of_our_images_that_cannot_be_had_is_named(): void
    {
        $base = (string) PhpBaseImage::tag('php:8.3-apache-bookworm');
        $this->writeRunFile("services:\n  app:\n    image: '{$base}'\n");
        $this->missing = [$base];

        try {
            $this->images($this->user(Strategies::PHP, 'php', $base))->provide($this->path());
            $this->fail('a missing base of ours must stop the copy');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("needs {$base}", $e->getMessage());
            $this->assertStringContainsString('Redeploy the project', $e->getMessage());
        }
    }

    public function test_a_public_image_still_missing_is_left_for_compose_to_pull(): void
    {
        $this->writeRunFile("services:\n  app:\n    image: mariadb:11\n");
        $this->missing = ['mariadb:11'];

        $this->images($this->user(Strategies::COMPOSE, null, null))->provide($this->path());

        $this->assertSame(['framework ' . Strategies::COMPOSE, 'compose ' . $this->path()], $this->calls);
    }

    public function test_a_railpack_image_only_the_source_had_is_built_from_the_copied_tree_first(): void
    {
        $this->writeRunFile(DeployCompose::railpack('panelalpha-live-app:latest', 3000));

        $this->images($this->user(Strategies::RAILPACK, null, 'panelalpha-live-app:latest'))->provide($this->path());

        $this->assertSame(
            [
                'railpack ' . $this->root . '/project 1002:1002 panelalpha-live-app:latest',
                'ensure panelalpha-live-app:latest',
                'compose ' . $this->path(),
            ],
            $this->calls
        );
    }

    public function test_a_railpack_image_an_earlier_push_left_in_the_account_is_built_again_from_the_pushed_tree(): void
    {
        $this->writeRunFile(DeployCompose::railpack('panelalpha-stg-app:latest', 3000));
        $this->present = ['panelalpha-stg-app:latest'];

        $this->images($this->user(Strategies::RAILPACK, null, null))->provide($this->path());

        $this->assertSame(
            [
                'railpack ' . $this->root . '/project 1002:1002 panelalpha-stg-app:latest',
                'compose ' . $this->path(),
            ],
            $this->calls
        );
    }

    public function test_the_railpack_image_a_push_target_already_has_is_held_before_it_is_built_again(): void
    {
        $this->writeRunFile(DeployCompose::railpack('panelalpha-stg-app:latest', 3000));
        $this->present = ['panelalpha-stg-app:latest'];

        $held = $this->images($this->user(Strategies::RAILPACK, null, null))->holdRailpackImage($this->path());

        $this->assertSame('panelalpha-stg-app:pre-push', $held?->holdingTag());
    }

    public function test_nothing_is_held_for_a_tag_the_account_lacks_or_an_image_it_does_not_build(): void
    {
        $this->writeRunFile(DeployCompose::railpack('panelalpha-stg-app:latest', 3000));

        $this->assertNull($this->images($this->user(Strategies::RAILPACK, null, null))->holdRailpackImage($this->path()));

        $this->present = ['panelalpha-stg-app:latest'];
        $this->assertNull($this->images($this->user(Strategies::PHP, 'php', null))->holdRailpackImage($this->path()));
    }

    public function test_a_railpack_build_that_fails_stops_the_copy_before_any_registry_is_asked(): void
    {
        $this->writeRunFile(DeployCompose::railpack('panelalpha-live-app:latest', 3000));
        $this->railpackBuilds = false;

        try {
            $this->images($this->user(Strategies::RAILPACK, null, 'panelalpha-live-app:latest'))->provide($this->path());
            $this->fail('an image that cannot be built must stop the copy');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('needs panelalpha-live-app:latest', $e->getMessage());
        }
        $this->assertSame(['railpack ' . $this->root . '/project 1002:1002 panelalpha-live-app:latest'], $this->calls);
    }

    public function test_a_project_without_a_run_file_asks_for_nothing(): void
    {
        $this->images($this->user(Strategies::PHP, 'php', 'panelalpha/php:8.3'))->provide($this->path());

        $this->assertSame([], $this->calls);
    }

    private function images(User $user): CopiedImages
    {
        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn(new LocalHostSystem($this->root, $this->root));
        $dind->method('userModel')->willReturn($user);
        $dind->method('homeDirPath')->willReturn($this->root);
        $dind->method('registryLogin')->willReturn(new RegistryLogin($dind));
        $dind->method('innerDocker')->willReturn($this->innerDocker());
        $dind->method('strategy')->willReturn($this->strategy());
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        return new CopiedImages($dind);
    }

    private function innerDocker(): InnerDocker
    {
        $seeding = $this->createStub(ImageSeeding::class);
        $seeding->method('hasImage')->willReturnCallback(fn (string $image): bool => in_array($image, $this->present, true));
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('seeding')->willReturn($seeding);
        $inner->method('ensureImage')->willReturnCallback(function (string $image): void {
            $this->calls[] = "ensure {$image}";
        });
        $inner->method('preloadFrameworkBaseImages')->willReturnCallback(function (?string $strategy): void {
            $this->calls[] = "framework {$strategy}";
        });
        $inner->method('preloadComposeImages')->willReturnCallback(function (string $path): void {
            $this->calls[] = "compose {$path}";
        });
        $inner->method('missingComposeImages')->willReturnCallback(fn (): array => $this->missing);

        return $inner;
    }

    private function strategy(): DeployStrategy
    {
        $railpack = $this->createStub(RailpackStrategy::class);
        $railpack->method('buildImage')->willReturnCallback(function (string $dir, ?string $chown, string $image): bool {
            $this->calls[] = "railpack {$dir} {$chown} {$image}";
            if ($this->railpackBuilds) {
                $this->present[] = $image;
            }

            return $this->railpackBuilds;
        });
        $strategy = $this->createStub(DeployStrategy::class);
        $strategy->method('railpack')->willReturn($railpack);

        return $strategy;
    }

    private function user(string $strategy, ?string $runtime, ?string $image): User
    {
        $user = new User();
        $user->username = 'stg';
        $user->details = array_filter([
            'UID' => 1002,
            'GID' => 1002,
            'deploy_strategy' => $strategy,
            'deploy_runtime' => $runtime,
            'deploy_image' => $image,
        ], static fn (mixed $value): bool => $value !== null);

        return $user;
    }

    private function writeRunFile(string $yaml): void
    {
        file_put_contents($this->path(), $yaml);
    }

    private function path(): string
    {
        return $this->root . '/project/' . EngineArtifacts::RUN_COMPOSE;
    }
}
