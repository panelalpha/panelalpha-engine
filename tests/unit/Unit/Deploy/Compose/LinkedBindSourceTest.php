<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Compose\ComposeOverride;
use App\Lib\Deploy\Compose\ServiceHardener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * `./data:/sock` with `data -> /var/run` committed to the repository is the
 * account's docker socket; the hardener has to see the link, not the text.
 */
class LinkedBindSourceTest extends TestCase
{
    private string $root;

    private string $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/linked-bind-' . bin2hex(random_bytes(4));
        $this->project = $this->root . '/acct/project';
        mkdir($this->project . '/real', 0755, true);
        mkdir($this->root . '/acct/.panelalpha', 0755, true);
        symlink('/var/run', $this->project . '/data');
        symlink('../.panelalpha', $this->project . '/state');
        touch($this->project . '/secret.txt');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_bind_through_a_committed_link_is_removed(): void
    {
        $service = [
            'image' => 'busybox',
            'volumes' => [
                './data:/sock',
                './data/docker.sock:/var/run/docker.sock:ro',
                ['type' => 'bind', 'source' => 'data', 'target' => '/long'],
                ['type' => 'bind', 'source' => './data', 'target' => '/long2'],
                './real:/real',
                './state/app:/state',
                'named:/named',
            ],
        ];

        $this->assertSame(
            ['./real:/real', './state/app:/state', 'named:/named'],
            ServiceHardener::withoutEscapes($service, projectDir: $this->project)['volumes']
        );
        // Without the checkout nothing is followed, as before (the socket
        // target is refused by its text).
        $this->assertCount(6, ServiceHardener::withoutEscapes($service)['volumes']);
    }

    public function test_a_secret_or_config_file_through_a_link_is_removed(): void
    {
        $compose = [
            'services' => ['app' => ['image' => 'busybox', 'secrets' => ['a', 'b']]],
            'secrets' => [
                'a' => ['file' => './data/docker.sock'],
                'b' => ['file' => 'secret.txt'],
            ],
            'configs' => ['c' => ['file' => 'data']],
        ];

        [$clean, $removed] = ServiceHardener::withoutUnsafeFileSources($compose, projectDir: $this->project);

        $this->assertSame(['b' => ['file' => 'secret.txt']], $clean['secrets']);
        $this->assertSame([], $clean['configs']);
        $this->assertSame(['secret a: file ./data/docker.sock', 'config c: file data'], $removed);
    }

    public function test_the_run_file_and_an_override_are_both_checked(): void
    {
        $run = ComposeHarden::apply([
            'services' => ['app' => ['image' => 'busybox', 'volumes' => ['./data:/sock', './real:/real']]],
            'secrets' => ['s' => ['file' => './data']],
        ], projectDir: $this->project);
        $this->assertSame(['./real:/real'], $run['services']['app']['volumes']);
        $this->assertArrayNotHasKey('s', $run['secrets']);

        $override = ComposeOverride::harden(
            "services:\n  app:\n    volumes:\n      - ./data:/o\n      - ./real:/r\nsecrets:\n  s:\n    file: data\n",
            projectDir: $this->project
        );
        $parsed = Yaml::parse((string) $override['yaml']);
        $this->assertSame(['./real:/r'], $parsed['services']['app']['volumes']);
        $this->assertSame([], $parsed['secrets']);
        $this->assertSame(['app: volume ./data:/o', 'secret s: file data'], $override['removed']);
    }
}
