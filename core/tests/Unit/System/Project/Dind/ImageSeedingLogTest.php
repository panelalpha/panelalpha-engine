<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Dind\DindImageStore;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\Inner\HostCommands;
use App\System\Project\Dind\Inner\ImageSeeding;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\RegistryLogin;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;

/**
 * What a deploy log says about a shared base image: that it uses it, or
 * nothing when it is not built yet (the caller says it is building it), and
 * a warning only for a real failure. A first deploy on a new PHP minor used
 * to log "Could not get X into the account: Could not get X into the account".
 */
class ImageSeedingLogTest extends TestCase
{
    private const IMAGE = 'panelalpha/php:8.4-apache-bookworm-pa20260924';

    /** @var list<array{string, string}> level, message */
    private array $logged = [];

    private ?System $system = null;

    public function test_a_shared_image_not_built_yet_logs_nothing_error_like(): void
    {
        $seeding = $this->seeding(new \Exception(DindImageStore::NOT_BUILT_HERE . "\n"), false);

        $this->assertFalse($seeding->provideFromStores(self::IMAGE));
        $this->assertSame([['dim', 'Fetching base image ' . self::IMAGE]], $this->logged);
    }

    public function test_a_shared_image_from_the_cache_registry_is_one_info_line(): void
    {
        $seeding = $this->seeding('Pulled base image ' . self::IMAGE . " from the cache registry\n", true);

        $this->assertTrue($seeding->provideFromStores(self::IMAGE));
        $this->assertSame(
            [['dim', 'Fetching base image ' . self::IMAGE], ['info', 'Using shared base image ' . self::IMAGE . ' (from the cache registry)']],
            $this->logged
        );
    }

    public function test_a_shared_image_loaded_from_the_host_says_so(): void
    {
        $seeding = $this->seeding('Loaded base image ' . self::IMAGE . " from the host through the cache registry\n", true);

        $this->assertTrue($seeding->provideFromStores(self::IMAGE));
        $this->assertSame(['info', 'Using shared base image ' . self::IMAGE . ' (from this host)'], $this->logged[1]);
    }

    public function test_a_real_failure_is_one_warning_with_the_reason(): void
    {
        $seeding = $this->seeding(new \Exception("push refused\nCould not get " . self::IMAGE . " into the account\n"), false);

        $this->assertFalse($seeding->provideFromStores(self::IMAGE));
        $this->assertSame(
            ['warn', 'Could not get ' . self::IMAGE . ' into the account: push refused'],
            $this->logged[1]
        );
        $this->assertCount(2, $this->logged);
    }

    public function test_a_public_image_still_logs_where_it_came_from(): void
    {
        $seeding = $this->seeding("Pulled base image redis:alpine\n", false);

        $seeding->ensure('redis:alpine');

        $this->assertSame(
            [['info', 'Fetching base image redis:alpine'], ['info', 'Pulled base image redis:alpine']],
            $this->logged
        );
    }

    /** Railpack's images go through the host but log like any public image. */
    public function test_a_railpack_image_through_the_host_logs_like_a_public_one(): void
    {
        $image = 'ghcr.io/railwayapp/railpack-frontend:latest';
        $seeding = $this->seeding("Loaded base image {$image} from the host through the cache registry\n", false);

        $seeding->preloadRailpack([$image]);

        $this->assertSame(
            [['info', "Fetching base image {$image}"], ['info', "Loaded base image {$image} from the host through the cache registry"]],
            $this->logged
        );
    }

    /**
     * An image the project has a registry login for takes the private ladder
     * (straight into the account, never the shared cache registry) and still
     * logs like any public image.
     */
    public function test_a_private_image_is_pulled_straight_into_the_account(): void
    {
        $image = 'ghcr.io/acme/app:1.0';
        $seeding = $this->seeding("Pulled base image {$image}\n", false, 'ghcr.io acme ghp_abcdefghijklmnop');

        $seeding->ensure($image);

        $this->assertSame(
            [['info', "Fetching base image {$image}"], ['info', "Pulled base image {$image}"]],
            $this->logged
        );
        $script = implode("\n", $this->system->scripts);
        $this->assertStringContainsString("docker pull -q '{$image}'", $script);
        $this->assertStringNotContainsString(DindImageStore::CACHE_REGISTRY, $script);
    }

    /**
     * @param string|\Exception $seedResult what the seed script prints, or how it fails
     */
    private function seeding(string|\Exception $seedResult, bool $presentAfter, ?string $registryAuth = null): ImageSeeding
    {
        $system = new class ($seedResult, $presentAfter) extends System {
            /** @var list<string> the seed scripts run */
            public array $scripts = [];

            public function __construct(private string|\Exception $seedResult, private bool $presentAfter)
            {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                // The image question goes through the account's shell as argv;
                // the seed ladder is one script.
                if (is_array($cmd)) {
                    return $this->presentAfter ? "sha256:abc\n" : '';
                }
                $this->scripts[] = $cmd;
                if ($this->seedResult instanceof \Exception) {
                    throw $this->seedResult;
                }

                return $this->seedResult;
            }
        };

        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('composeFilePath')->willReturn('/users/demo/docker-compose.yml');
        $dind->method('engineAccount')->willReturn(new EngineAccount('demo', '/home/demo', '33:33', '/users/demo/docker-compose.yml'));
        $dind->method('shell')->willReturn(new ShellOperations($dind));
        $user = $this->createStub(User::class);
        $user->method('getRegistryAuth')->willReturn($registryAuth);
        $dind->method('userModel')->willReturn($user);
        $login = new RegistryLogin($dind);
        $dind->method('registryLogin')->willReturn($login);
        $this->system = $system;

        $logged = &$this->logged;
        $host = $this->createStub(HostCommands::class);
        foreach (['logInfo' => 'info', 'logDim' => 'dim', 'logWarn' => 'warn'] as $method => $level) {
            $host->method($method)->willReturnCallback(function (string $message) use (&$logged, $level): void {
                $logged[] = [$level, $message];
            });
        }

        $inner = $this->createStub(InnerDocker::class);
        $inner->method('dind')->willReturn($dind);
        $inner->method('host')->willReturn($host);
        $inner->method('imageStore')->willReturn(new DindImageStore());

        $seeding = new ImageSeeding($inner);
        // The registry-config refresh is its own concern.
        (new \ReflectionProperty(ImageSeeding::class, 'registryConfigChecked'))->setValue($seeding, true);

        return $seeding;
    }
}
