<?php

namespace Tests\Integration\Domains;

use App\Integrations\Statistics\Statistics;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Services\Webserver;
use App\System\Services\Webserver\WebserverInterface;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;
use Tests\Integration\Integrations\Statistics\FakeStatistics;

/**
 * Deleting a domain takes the certificate material the engine installed for its name with it, so no private key is
 * left behind in the project's ssl-certs directory.
 */
class DomainDeleteCertificateTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->app->instance(Statistics::class, new FakeStatistics);
        LocalRoot::answerLocally();
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        parent::tearDown();
    }

    public function test_deleting_a_domain_removes_only_its_certificate_material(): void
    {
        $root = sys_get_temp_dir().'/pa-domain-cert-'.bin2hex(random_bytes(4));
        $certDir = $root.'/users/alice/ssl-certs';
        mkdir($certDir, 0777, true);
        foreach (['crt', 'key', 'ca', 'pem'] as $extension) {
            file_put_contents("{$certDir}/shop.example.test.{$extension}", 'material');
        }
        file_put_contents("{$certDir}/other.example.test.crt", 'material');

        try {
            $user = $this->makeUser('alice', ['template' => 'dind']);
            $domain = $this->makeMainDomain($user, 'shop.example.test');

            (new ProjectAggregate($this->systemFor($root), $user))->domain($domain)->delete();

            foreach (['crt', 'key', 'ca', 'pem'] as $extension) {
                $this->assertFileDoesNotExist("{$certDir}/shop.example.test.{$extension}");
            }
            $this->assertFileExists("{$certDir}/other.example.test.crt");
        } finally {
            $this->removeTree($root);
        }
    }

    private function systemFor(string $root): System
    {
        $driver = $this->createStub(WebserverInterface::class);

        return new class($root, $driver) extends System
        {
            public function __construct(
                private readonly string $root,
                private readonly WebserverInterface $driver,
            ) {}

            public function engineDirPath(): string
            {
                return $this->root;
            }

            public function homesDirPath(): string
            {
                return $this->root.'/home';
            }

            public function webserver(): Webserver
            {
                $driver = $this->driver;

                return new class($driver) extends Webserver
                {
                    public function __construct(private readonly WebserverInterface $driver) {}

                    public function driver(?string $slug = null): WebserverInterface
                    {
                        return $this->driver;
                    }
                };
            }
        };
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
            $full = "{$path}/{$item}";
            is_dir($full) ? $this->removeTree($full) : unlink($full);
        }
        rmdir($path);
    }
}
