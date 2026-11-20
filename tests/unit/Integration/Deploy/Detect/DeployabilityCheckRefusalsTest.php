<?php

namespace Tests\Integration\Deploy\Detect;

use App\Lib\Deploy\Detect\DeployabilityCheck;
use App\Lib\Deploy\Detect\DeployRefusal;
use App\Lib\Deploy\Platform\Strategies;
use PHPUnit\Framework\TestCase;

/** Refusals that need the project's own files read: its PHP extensions, its compose build and its app_root. */
class DeployabilityCheckRefusalsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/deployability-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    public function test_php_extensions_no_image_carries_are_refused(): void
    {
        $require = [];
        foreach (['solr', 'yaml', 'rdkafka', 'oci8', 'snmp', 'tidy'] as $name) {
            $require['ext-'.$name] = '*';
        }
        $this->write('composer.json', (string) json_encode(['require' => $require]));

        $refusal = $this->refusal(['strategy' => Strategies::PHP]);

        $this->assertSame(DeployabilityCheck::PHP_EXTENSION_UNAVAILABLE, $refusal->refusal);
        $this->assertStringContainsString('ext-solr', $refusal->getMessage());
        $this->assertStringContainsString('ext-tidy', $refusal->getMessage());
    }

    public function test_php_extensions_the_image_has_pass(): void
    {
        $this->write('composer.json', (string) json_encode(['require' => ['ext-json' => '*']]));

        DeployabilityCheck::assert(['strategy' => Strategies::PHP], $this->dir);

        $this->addToAssertionCount(1);
    }

    public function test_a_compose_build_from_a_missing_dockerfile_names_it(): void
    {
        $compose = $this->write('docker-compose.yml', "services:\n  app:\n    build:\n      context: .\n      dockerfile: docker/web.Dockerfile\n    ports:\n      - \"8080:8080\"\n");

        $refusal = $this->refusal(['strategy' => Strategies::COMPOSE, 'compose_path' => $compose]);

        $this->assertSame(DeployabilityCheck::COMPOSE_BUILD_FILE_MISSING, $refusal->refusal);
        $this->assertSame(['file' => 'docker/web.Dockerfile'], $refusal->facts);
        $this->assertSame('Compose file builds from docker/web.Dockerfile which does not exist.', $refusal->getMessage());
    }

    public function test_a_compose_build_whose_dockerfile_exists_passes(): void
    {
        $compose = $this->write('docker-compose.yml', "services:\n  app:\n    build:\n      context: .\n      dockerfile: docker/web.Dockerfile\n");
        $this->write('docker/web.Dockerfile', "FROM nginx:alpine\n");

        DeployabilityCheck::assert(['strategy' => Strategies::COMPOSE, 'compose_path' => $compose], $this->dir);

        $this->addToAssertionCount(1);
    }

    public function test_the_dockerfile_is_looked_for_under_app_root(): void
    {
        $this->write('README.md', 'monorepo');
        $this->write('Dockerfile', "FROM nginx:alpine\n");

        $refusal = $this->refusal(['strategy' => Strategies::DOCKERFILE, 'app_root' => '/site/']);
        $this->assertSame([DeployabilityCheck::DOCKERFILE_MISSING, ['file' => 'Dockerfile']], [$refusal->refusal, $refusal->facts]);

        $this->write('site/Dockerfile', "FROM nginx:alpine\n");
        DeployabilityCheck::assert(['strategy' => Strategies::DOCKERFILE, 'app_root' => '/site/'], $this->dir);
    }

    /** @param array<string, mixed> $decision */
    private function refusal(array $decision): DeployRefusal
    {
        try {
            DeployabilityCheck::assert($decision, $this->dir);
        } catch (DeployRefusal $e) {
            return $e;
        }
        $this->fail('Expected a refusal');
    }

    private function write(string $relative, string $contents): string
    {
        $path = $this->dir.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);

        return $path;
    }

    private function remove(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) && ! is_link($path) ? $this->remove($path) : unlink($path);
        }
        rmdir($dir);
    }
}
