<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Engine\ImageStore;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Strategies;
use App\Models\OperatorImage;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\Inner\HostCommands;
use App\System\Project\Dind\Inner\ImageSeeding;
use App\System\Project\Dind\Inner\SharedBaseImages;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ProjectEnvironment;
use App\System\Project\Dind\RegistryLogin;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * Which images ImageSeeding asks the account for, in which order and by which
 * route: the framework, Railpack and compose preloads, the operator bases a
 * build starts FROM, and what an image says about itself.
 */
class ImageSeedingPreloadTest extends TestCase
{
    use InMemoryDatabase;

    private const COMPOSE = '/home/demo/project/docker-compose.yml';

    private const ENV = '/home/demo/project/.env';

    /** @var array<string, string> */
    private array $files = [];

    /** @var list<string> */
    private array $present = [];

    /** @var list<string> */
    private array $scripts = [];

    /** @var list<string> */
    private array $cancellable = [];

    /** @var list<string> */
    private array $logged = [];

    /** @var list<string> */
    private array $provided = [];

    private ?\Closure $seedAnswer = null;

    private bool $seedFills = true;

    private ?\Exception $listFails = null;

    private ?\Exception $parallelFails = null;

    /** @var array<string, string|\Exception> */
    private array $ports = [];

    /** @var array<string, mixed> */
    private array $inspect = [];

    /** @var array<string, string> */
    private array $interpolation = [];

    private bool $environmentFails = false;

    private bool $providePhp = true;

    private ?string $registryAuth = null;

    private bool $registryConfigChecked = true;

    private int $daemonJsonRenders = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['env.DIND_SEED_CONCURRENCY' => 1]);
    }

    public function test_a_php_project_preloads_the_shared_base_from_the_stores(): void
    {
        $tag = (string) PhpBaseImage::tag(Images::PHP_IMAGE);

        $this->seeding()->preloadFramework(Strategies::PHP);

        $this->assertSame(["seed {$tag} ours"], $this->scripts);
        $this->assertSame([], $this->provided);
        $this->assertSame(["dim Fetching base image {$tag}", "info Using shared base image {$tag}"], $this->logged);
    }

    public function test_a_shared_base_no_store_has_is_rebuilt_from_its_source(): void
    {
        $tag = (string) PhpBaseImage::tag(Images::PHP_IMAGE);
        $this->seedFills = false;

        $this->seeding()->preloadFramework(Strategies::PHP);

        $this->assertSame(["{$tag} from " . Images::PHP_IMAGE], $this->provided);
        $this->assertSame(["dim Fetching base image {$tag}"], $this->logged);
    }

    public function test_a_shared_base_that_cannot_be_rebuilt_is_said_and_skipped(): void
    {
        $tag = (string) PhpBaseImage::tag(Images::PHP_IMAGE);
        $this->seedFills = false;
        $this->providePhp = false;

        $this->seeding()->preloadFramework(Strategies::PHP);

        $this->assertSame(
            ["dim Fetching base image {$tag}", "info Base image {$tag} is one of ours and is not on this host; continuing without it"],
            $this->logged
        );
    }

    public function test_a_variant_tag_is_never_rebuilt_from_the_tag_alone(): void
    {
        $tag = (string) PhpBaseImage::tag(Images::PHP_IMAGE, ['imagick']);
        $this->seedFills = false;

        $this->seeding()->ensure($tag);

        $this->assertSame([], $this->provided);
        $this->assertSame("info Base image {$tag} is one of ours and is not on this host; continuing without it", end($this->logged));
    }

    public function test_a_vite_project_preloads_node_and_nginx_as_public_images(): void
    {
        $this->seeding()->preloadFramework(Strategies::VITE, PlatformManifest::RUNTIME_NGINX);

        $this->assertSame(['seed ' . Images::NODE_IMAGE . ' public', 'seed ' . Images::NGINX_IMAGE . ' public'], $this->scripts);
    }

    public function test_a_strategy_without_preloads_asks_nothing(): void
    {
        $this->seeding()->preloadFramework(Strategies::STATIC);

        $this->assertSame([], $this->scripts);
        $this->assertSame([], $this->logged);
    }

    public function test_a_railpack_image_the_account_holds_is_not_fetched_again(): void
    {
        $image = 'ghcr.io/railwayapp/railpack-runtime:mise-2025.1.1';
        $this->present = [$image];

        $this->seeding()->preloadRailpack([$image, 'node:22-bookworm-slim']);

        $this->assertSame(['seed node:22-bookworm-slim public'], $this->scripts);
    }

    public function test_a_compose_file_that_does_not_exist_preloads_nothing(): void
    {
        $this->seeding()->preloadCompose(self::COMPOSE);

        $this->assertSame([], $this->scripts);
        $this->assertSame([], $this->seeding()->missingFrom(self::COMPOSE));
    }

    public function test_an_empty_compose_file_preloads_nothing(): void
    {
        $this->files[self::COMPOSE] = '';

        $this->seeding()->preloadCompose(self::COMPOSE);

        $this->assertSame([], $this->scripts);
    }

    public function test_one_seed_at_a_time_fetches_each_missing_image_in_order(): void
    {
        $this->files[self::COMPOSE] = $this->compose(['db' => 'mysql:8.0', 'cache' => 'redis:7-alpine']);
        $this->present = ['redis:7-alpine'];

        $this->seeding()->preloadCompose(self::COMPOSE);

        $this->assertSame(['seed mysql:8.0 public'], $this->scripts);
        $this->assertSame([], $this->cancellable);
    }

    public function test_an_image_named_through_the_environment_is_interpolated(): void
    {
        $this->files[self::COMPOSE] = $this->compose(['wp' => 'wordpress:${WORDPRESS_VERSION:-latest}']);
        $this->interpolation = ['WORDPRESS_VERSION' => '6.6'];

        $this->assertSame(['wordpress:6.6'], $this->seeding()->missingFrom(self::COMPOSE));
    }

    public function test_an_environment_that_cannot_be_read_falls_back_to_the_defaults(): void
    {
        $this->files[self::COMPOSE] = $this->compose(['wp' => 'wordpress:${WORDPRESS_VERSION:-latest}']);
        $this->environmentFails = true;

        $this->assertSame(['wordpress:latest'], $this->seeding()->missingFrom(self::COMPOSE));
    }

    public function test_only_the_profiles_the_env_file_turns_on_are_preloaded(): void
    {
        $this->files[self::COMPOSE] = "services:\n"
            . "  app:\n    image: nginx:alpine\n"
            . "  worker:\n    image: redis:7-alpine\n    profiles: [\"queue\"]\n"
            . "  debug:\n    image: adminer:4\n    profiles: [\"debug\"]\n";
        $this->files[self::ENV] = "# profiles\nCOMPOSE_PROFILES= queue ,\nOTHER=1\n";

        $this->assertSame(['nginx:alpine', 'redis:7-alpine'], $this->seeding()->missingFrom(self::COMPOSE));
    }

    public function test_missing_images_are_those_the_account_lacks(): void
    {
        $this->files[self::COMPOSE] = $this->compose(['db' => 'mysql:8.0', 'cache' => 'redis:7-alpine']);
        $this->present = ['mysql:8.0'];

        $this->assertSame(['redis:7-alpine'], $this->seeding()->missingFrom(self::COMPOSE));
    }

    public function test_several_images_are_seeded_in_parallel_and_the_rest_fetched_after(): void
    {
        config(['env.DIND_SEED_CONCURRENCY' => 3]);
        $this->files[self::COMPOSE] = $this->compose(['db' => 'mysql:8.0', 'cache' => 'redis:7-alpine', 'mail' => 'axllent/mailpit:v1.20']);
        $this->seedFills = false;
        $this->present = ['redis:7-alpine'];

        $this->seeding()->preloadCompose(self::COMPOSE);

        $this->assertSame(['bash -c parallel 3 mysql:8.0,redis:7-alpine,axllent/mailpit:v1.20'], $this->cancellable);
        $this->assertSame(['seed mysql:8.0 public', 'seed axllent/mailpit:v1.20 public'], $this->scripts);
        $this->assertSame('dim Seeding 3 base images at a time', $this->logged[0]);
    }

    public function test_a_private_image_is_left_out_of_the_parallel_seed(): void
    {
        config(['env.DIND_SEED_CONCURRENCY' => 2]);
        $this->registryAuth = 'ghcr.io acme ghp_abcdefghijklmnop';
        $this->files[self::COMPOSE] = $this->compose(['app' => 'ghcr.io/acme/app:1.0', 'db' => 'mysql:8.0']);

        $this->seeding()->preloadCompose(self::COMPOSE);

        $this->assertSame(['bash -c parallel 2 mysql:8.0'], $this->cancellable);
        $this->assertCount(1, $this->scripts);
        $this->assertStringStartsWith('seed ghcr.io/acme/app:1.0 public private ', $this->scripts[0]);
    }

    public function test_only_private_images_skip_the_parallel_seed_entirely(): void
    {
        config(['env.DIND_SEED_CONCURRENCY' => 2]);
        $this->registryAuth = 'ghcr.io acme ghp_abcdefghijklmnop';
        $this->files[self::COMPOSE] = $this->compose(['app' => 'ghcr.io/acme/app:1.0', 'worker' => 'ghcr.io/acme/worker:1.0']);

        $this->seeding()->preloadCompose(self::COMPOSE);

        $this->assertSame([], $this->cancellable);
        $this->assertCount(2, $this->scripts);
    }

    public function test_a_parallel_seed_that_fails_falls_back_to_one_at_a_time(): void
    {
        config(['env.DIND_SEED_CONCURRENCY' => 2]);
        $this->files[self::COMPOSE] = $this->compose(['db' => 'mysql:8.0', 'cache' => 'redis:7-alpine']);
        $this->parallelFails = new \RuntimeException('bash: wait: -n: invalid option');

        $this->seeding()->preloadCompose(self::COMPOSE);

        $this->assertContains('disk-check bash: wait: -n: invalid option', $this->logged);
        $this->assertContains('info Parallel image seed failed, falling back: bash: wait: -n: invalid option', $this->logged);
        $this->assertSame(['seed mysql:8.0 public', 'seed redis:7-alpine public'], $this->scripts);
    }

    public function test_an_account_that_cannot_list_its_images_has_every_image_fetched(): void
    {
        config(['env.DIND_SEED_CONCURRENCY' => 2]);
        $this->files[self::COMPOSE] = $this->compose(['db' => 'mysql:8.0', 'cache' => 'redis:7-alpine']);
        $this->listFails = new \RuntimeException('daemon gone');
        $this->seedFills = false;
        $this->present = ['redis:7-alpine'];

        $this->seeding()->preloadCompose(self::COMPOSE);

        $this->assertSame(['seed mysql:8.0 public'], $this->scripts);
    }

    public function test_an_operator_base_a_build_starts_from_is_seeded_from_the_cache_registry(): void
    {
        $this->operatorImages(['whmcs-base:php8.3', 'unused-base:1']);
        $this->files[self::COMPOSE] = "services:\n  app:\n    build:\n      context: ./app\n";
        $this->files['/home/demo/project/app/Dockerfile'] = "FROM whmcs-base:php8.3 AS base\nFROM base\nRUN true\n";

        $this->seeding()->preloadOperatorBases(self::COMPOSE);

        $this->assertSame(['operator whmcs-base:php8.3'], $this->scripts);
        $this->assertSame(['info Fetching base image whmcs-base:php8.3', 'info Pulled base image whmcs-base:php8.3'], $this->logged);
    }

    public function test_no_operator_images_means_nothing_is_read(): void
    {
        $this->operatorImages([]);
        $this->files[self::COMPOSE] = "services:\n  app:\n    build: .\n";

        $this->seeding()->preloadOperatorBases(self::COMPOSE);

        $this->assertSame([], $this->scripts);
    }

    public function test_an_operator_base_without_a_compose_file_is_not_seeded(): void
    {
        $this->operatorImages(['whmcs-base:php8.3']);

        $this->seeding()->preloadOperatorBases(self::COMPOSE);

        $this->assertSame([], $this->scripts);
    }

    public function test_a_dockerfile_that_cannot_be_read_names_no_operator_base(): void
    {
        $this->operatorImages(['whmcs-base:php8.3']);
        $this->files[self::COMPOSE] = "services:\n  app:\n    build: .\n";
        $this->files['/home/demo/project/Dockerfile'] = new \RuntimeException('Permission denied');

        $this->seeding()->preloadOperatorBases(self::COMPOSE);

        $this->assertSame([], $this->scripts);
    }

    public function test_the_ports_an_image_declares_come_from_the_host_store(): void
    {
        $this->ports['redis:7-alpine'] = '{"6379/tcp":{}}';

        $this->assertSame([6379], $this->seeding()->declaredImagePorts('redis:7-alpine'));
        $this->assertSame([], $this->seeding()->declaredImagePorts(''));
        $this->assertSame([], $this->seeding()->declaredImagePorts('bad ref;rm'));
    }

    public function test_the_environment_an_image_sets_comes_from_the_account(): void
    {
        $this->inspect['postgres:16'] = ['Config' => ['Env' => ['PGDATA=/var/lib/postgresql/data', 7, 'LANG=en_US.utf8']]];
        $this->inspect['busybox:1'] = ['Config' => []];

        $this->assertSame(['PGDATA=/var/lib/postgresql/data', 'LANG=en_US.utf8'], $this->seeding()->imageEnvironment('postgres:16'));
        $this->assertSame([], $this->seeding()->imageEnvironment('busybox:1'));
        $this->assertSame([], $this->seeding()->imageEnvironment(''));
        $this->assertSame([], $this->seeding()->imageEnvironment('bad ref;rm'));
    }

    public function test_an_absent_engine_picked_base_is_fetched_not_refreshed(): void
    {
        $this->seeding()->ensureCurrent('node:22-bookworm-slim');

        $this->assertSame(['seed node:22-bookworm-slim public'], $this->scripts);
    }

    public function test_a_seed_that_prints_nothing_logs_only_the_fetch(): void
    {
        $this->seedAnswer = static fn (): string => "\n";

        $this->seeding()->ensure('redis:7-alpine');

        $this->assertSame(['info Fetching base image redis:7-alpine'], $this->logged);
    }

    public function test_a_container_that_cannot_say_whether_it_mounts_daemon_json_gets_the_plain_render(): void
    {
        $this->registryConfigChecked = false;
        $this->present = ['redis:7-alpine'];

        $seeding = $this->seeding();
        $this->assertTrue($seeding->hasImage('redis:7-alpine'));
        $this->assertTrue($seeding->hasImage('redis:7-alpine'));

        $this->assertSame(1, $this->daemonJsonRenders);
        $this->assertSame([], $this->logged);
    }

    public function test_a_shared_image_from_an_unnamed_store_is_used_without_a_source(): void
    {
        $this->assertSame('Using shared base image panelalpha/php:8.3', ImageSeeding::usingShared('panelalpha/php:8.3', 'Pulled it'));
        $this->assertSame('Could not get x:1 into the account', ImageSeeding::seedFailure('x:1', "\nCould not get x:1 into the account\n"));
    }

    /** @param list<string> $refs */
    private function operatorImages(array $refs): void
    {
        $this->bootInMemoryDatabase();
        foreach ($refs as $ref) {
            OperatorImage::query()->create(['ref' => $ref, 'source' => 'local', 'status' => 'ready']);
        }
    }

    /** @param array<string, string> $services */
    private function compose(array $services): string
    {
        $yaml = "services:\n";
        foreach ($services as $name => $image) {
            $yaml .= "  {$name}:\n    image: \"{$image}\"\n";
        }

        return $yaml;
    }

    private function seeding(): ImageSeeding
    {
        $test = $this;
        $system = new class($test) extends System
        {
            public function __construct(private readonly ImageSeedingPreloadTest $test) {}

            public function filesystem(): Filesystem
            {
                return new class($this, $this->test) extends Filesystem
                {
                    public function __construct(System $system, private readonly ImageSeedingPreloadTest $test)
                    {
                        parent::__construct($system);
                    }

                    public function fileExists(string $path): bool
                    {
                        return $this->test->hasFile($path);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return $this->test->file($path);
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return $this->test->answer($cmd);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                throw new \LogicException('No process may start: ' . implode(' ', (array) $cmd));
            }

            public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
            {
                throw new \LogicException('No process may start: ' . implode(' ', (array) $cmd));
            }
        };

        $user = $this->createStub(User::class);
        $user->method('getRegistryAuth')->willReturn($this->registryAuth);
        $environment = $this->createStub(ProjectEnvironment::class);
        if ($this->environmentFails) {
            $environment->method('forInterpolation')->willThrowException(new \RuntimeException('unreadable .env'));
        } else {
            $environment->method('forInterpolation')->willReturn($this->interpolation);
        }

        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn('demo');
        $dind->method('userModel')->willReturn($user);
        $dind->method('userAppDirPath')->willReturn('/home/demo/project-missing');
        $dind->method('environment')->willReturn($environment);
        $dind->method('engineAccount')->willReturn(new EngineAccount('demo', '/home/demo', '33:33'));
        $dind->method('accountDaemon')->willReturnCallback(static fn (): AccountDaemon => new AccountDaemon($dind));
        $dind->method('shell')->willReturnCallback(static fn (): ShellOperations => new ShellOperations($dind));
        $login = new RegistryLogin($dind);
        $dind->method('registryLogin')->willReturn($login);
        $dind->method('daemonJsonPath')->willReturn('/opt/panelalpha/shared-hosting/users/demo/daemon.json');
        $dind->method('daemonJsonContents')->willReturn('{"live-restore":true}');
        $dind->method('setupDaemonJson')->willReturnCallback(function (): void {
            $this->daemonJsonRenders++;
        });

        $store = $this->createStub(ImageStore::class);
        $store->method('seedCommand')->willReturnCallback(
            static fn (EngineAccount $a, string $image, bool $ours = false, bool $private = false, ?string $dir = null): string => "seed {$image} " . ($ours ? 'ours' : 'public') . ($private ? " private {$dir}" : '')
        );
        $store->method('operatorSeedCommand')->willReturnCallback(static fn (EngineAccount $a, string $image): string => "operator {$image}");
        $store->method('seedThroughHostCommand')->willReturnCallback(static fn (EngineAccount $a, string $image): string => "through-host {$image}");
        $store->method('parallelImportCommand')->willReturnCallback(
            static fn (EngineAccount $a, array $images, int $concurrency): string => "parallel {$concurrency} " . implode(',', $images)
        );
        $store->method('hostImageExposedPortsArgv')->willReturnCallback(static fn (string $image): array => ['ports', $image]);

        $host = $this->createStub(HostCommands::class);
        foreach (['logInfo' => 'info', 'logDim' => 'dim', 'logWarn' => 'warn', 'failDeployIfDiskFull' => 'disk-check'] as $method => $level) {
            $host->method($method)->willReturnCallback(function (string $message) use ($level): void {
                $this->logged[] = "{$level} {$message}";
            });
        }
        $host->method('cancellable')->willReturnCallback(function (array $argv): void {
            $this->cancellable[] = implode(' ', $argv);
            if ($this->parallelFails !== null) {
                throw $this->parallelFails;
            }
            if ($this->seedFills) {
                array_push($this->present, ...explode(',', explode(' ', $argv[2])[2]));
            }
        });

        $bases = $this->createStub(SharedBaseImages::class);
        $bases->method('providePhp')->willReturnCallback(function (string $tag, string $source): bool {
            $this->provided[] = "{$tag} from {$source}";

            return $this->providePhp;
        });

        $inner = $this->createStub(InnerDocker::class);
        $inner->method('dind')->willReturn($dind);
        $inner->method('host')->willReturn($host);
        $inner->method('imageStore')->willReturn($store);
        $inner->method('bases')->willReturn($bases);

        $seeding = new ImageSeeding($inner);
        (new \ReflectionProperty(ImageSeeding::class, 'registryConfigChecked'))->setValue($seeding, $this->registryConfigChecked);

        return $seeding;
    }

    public function hasFile(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    public function file(string $path): string
    {
        $contents = $this->files[$path] ?? '';
        if ($contents instanceof \Exception) {
            throw $contents;
        }

        return $contents;
    }

    /** @param string|list<string> $cmd */
    public function answer(string|array $cmd): string
    {
        if (is_string($cmd)) {
            return $this->seedScript($cmd);
        }
        if (($cmd[0] ?? '') === 'ports') {
            $answer = $this->ports[$cmd[1]] ?? '';
            if ($answer instanceof \Exception) {
                throw $answer;
            }

            return $answer;
        }
        $values = LocalRoot::values($cmd);

        return match (LocalRoot::action($cmd)) {
            'account-docker.hasImage' => in_array((string) end($values), $this->present, true) ? '' : throw new \RuntimeException('No such image'),
            'account-docker.listImages' => $this->listImages(),
            'account-container.daemonConfigMounted' => throw new \RuntimeException('No such container: demo'),
            'account-docker.images' => (string) json_encode(array_map(fn (string $image): mixed => $this->inspect[$image] ?? null, array_slice($values, 1))),
            default => '',
        };
    }

    private function listImages(): string
    {
        if ($this->listFails !== null) {
            throw $this->listFails;
        }

        return (string) json_encode([
            ...array_map(static fn (string $image): array => ['RepoTags' => [$image], 'RepoDigests' => []], $this->present),
            ['RepoTags' => [], 'RepoDigests' => []],
        ]);
    }

    private function seedScript(string $script): string
    {
        $this->scripts[] = $script;
        $image = explode(' ', $script)[1] ?? '';
        $answer = $this->seedAnswer !== null ? ($this->seedAnswer)($script) : "Pulled base image {$image}\n";
        if ($this->seedFills) {
            $this->present[] = $image;
        }

        return $answer;
    }
}
