<?php

namespace Tests\Integration\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeFileInspector;
use PHPUnit\Framework\TestCase;

/**
 * A docker-compose.yml is the engine's own static bootstrap only in its exact shape; anything else is the client's.
 */
class GeneratedBootstrapComposeShapesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-bootstrap-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function compose(string $yaml): string
    {
        file_put_contents($this->dir.'/docker-compose.yml', $yaml);

        return $this->dir.'/docker-compose.yml';
    }

    public function test_the_unlabelled_bootstrap_is_the_engines(): void
    {
        $path = $this->compose("services:\n  app:\n    image: nginx:alpine\n    volumes:\n      - ./:/usr/share/nginx/html\n");

        $this->assertTrue(ComposeFileInspector::isGeneratedBootstrapCompose($path));
    }

    public function test_a_bootstrap_whose_volumes_are_not_a_list_is_the_clients(): void
    {
        $path = $this->compose("services:\n  app:\n    image: nginx:alpine\n    volumes: ./:/usr/share/nginx/html\n");

        $this->assertFalse(ComposeFileInspector::isGeneratedBootstrapCompose($path));
    }

    public function test_a_service_that_is_not_a_map_is_skipped_when_looking_for_the_engines_label(): void
    {
        $path = $this->compose("services:\n  note: just text\n  app:\n    image: nginx:alpine\n    labels:\n      com.example: x\n");

        $this->assertFalse(ComposeFileInspector::isGeneratedBootstrapCompose($path));
    }

    public function test_an_empty_file_is_not_the_engines(): void
    {
        $this->assertFalse(ComposeFileInspector::isGeneratedBootstrapCompose($this->compose('')));
    }
}
