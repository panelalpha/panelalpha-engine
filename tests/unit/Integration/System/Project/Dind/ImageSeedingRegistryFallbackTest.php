<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\CacheManager\RegistryImageConfig;
use App\Lib\Deploy\Dind\DindImageStore;
use App\Lib\Deploy\Engine\ImageStore;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\Inner\ImageSeeding;
use App\System\Project\Dind\InnerDocker;
use Tests\TestCase;

/** An image neither the host nor the account holds is described by the registries, asked in the cache registry first. */
class ImageSeedingRegistryFallbackTest extends TestCase
{
    private const REGISTRY = 'http://'.DindImageStore::CACHE_REGISTRY.'/v2/myorg/queue';

    /** @var list<string> */
    private array $asked = [];

    /** @var string|\Exception what the account daemon answers when asked about an image */
    private string|\Exception $held = '[null]';

    public function test_the_ports_come_from_the_registry_when_the_host_cannot_say(): void
    {
        $this->assertSame([5672, 15672], $this->seeding()->declaredImagePorts('myorg/queue:3'));
        $this->assertSame([self::REGISTRY.'/manifests/3', self::REGISTRY.'/blobs/sha256:cfg'], $this->asked);
    }

    public function test_the_environment_comes_from_the_registry_when_the_account_does_not_hold_the_image(): void
    {
        $this->assertSame(['RABBITMQ_DATA_DIR=/var/lib/rabbitmq'], $this->seeding()->imageEnvironment('myorg/queue:3'));
        $this->assertSame([self::REGISTRY.'/manifests/3', self::REGISTRY.'/blobs/sha256:cfg'], $this->asked);
    }

    public function test_the_environment_comes_from_the_registry_when_the_account_cannot_say(): void
    {
        $this->held = new \RuntimeException('account daemon is not running');

        $this->assertSame(['RABBITMQ_DATA_DIR=/var/lib/rabbitmq'], $this->seeding()->imageEnvironment('myorg/queue:3'));
    }

    public function test_each_question_to_the_registries_gets_a_config_of_its_own(): void
    {
        $seeding = new class ($this->createStub(InnerDocker::class)) extends ImageSeeding {
            public function config(): RegistryImageConfig
            {
                return $this->registryImageConfig();
            }
        };

        $this->assertNotSame($seeding->config(), $seeding->config());
    }

    private function seeding(): ImageSeeding
    {
        $system = new class ($this) extends System {
            public function __construct(private readonly ImageSeedingRegistryFallbackTest $test)
            {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return $this->test->daemonAnswer((array) $cmd);
            }
        };
        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn('demo');
        $dind->method('accountDaemon')->willReturnCallback(static fn (): AccountDaemon => new AccountDaemon($dind));
        $store = $this->createStub(ImageStore::class);
        $store->method('hostImageExposedPortsArgv')->willReturn(['ports']);
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('dind')->willReturn($dind);
        $inner->method('imageStore')->willReturn($store);

        $registry = new RegistryImageConfig(function (string $url): ?string {
            $this->asked[] = $url;

            return match ($url) {
                self::REGISTRY.'/manifests/3' => '{"config":{"digest":"sha256:cfg"}}',
                self::REGISTRY.'/blobs/sha256:cfg' => '{"config":{"ExposedPorts":{"5672/tcp":{},"15672/tcp":{}},"Env":["RABBITMQ_DATA_DIR=/var/lib/rabbitmq",7]}}',
                default => null,
            };
        }, 'amd64');

        return new class ($inner, $registry) extends ImageSeeding {
            public function __construct(InnerDocker $inner, private readonly RegistryImageConfig $registry)
            {
                parent::__construct($inner);
            }

            protected function registryImageConfig(): RegistryImageConfig
            {
                return $this->registry;
            }
        };
    }

    /** @param  list<string>  $argv */
    public function daemonAnswer(array $argv): string
    {
        if ($argv === ['ports']) {
            throw new \RuntimeException('No such image: myorg/queue:3');
        }
        if ($this->held instanceof \Exception) {
            throw $this->held;
        }

        return $this->held;
    }
}
