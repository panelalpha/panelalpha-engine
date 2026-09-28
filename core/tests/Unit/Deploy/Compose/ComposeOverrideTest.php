<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeOverride;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * engine#48, item 9: a compose override is layered over the hardened run file
 * with `-f`, so what the run file had removed came back through it.
 */
class ComposeOverrideTest extends TestCase
{
    public function test_the_escapes_the_run_file_is_cleared_of_are_removed_here_too(): void
    {
        $raw = <<<'YAML'
        services:
          app:
            privileged: true
            cap_add: [ALL]
            security_opt: ['seccomp:unconfined']
            network_mode: host
            pid: host
            volumes:
              - /var/run/docker.sock:/var/run/docker.sock
              - /:/hostfs
              - ./data:/data
            environment:
              KEEP: me
        YAML;

        $result = ComposeOverride::harden($raw);
        $app = Yaml::parse((string) $result['yaml'])['services']['app'];

        foreach (['privileged', 'cap_add', 'security_opt', 'network_mode', 'pid'] as $key) {
            $this->assertArrayNotHasKey($key, $app);
        }
        $this->assertSame(['./data:/data'], $app['volumes']);
        $this->assertSame(['KEEP' => 'me'], $app['environment']);
        $this->assertContains('app: privileged', $result['removed']);
        $this->assertContains('app: volume /var/run/docker.sock:/var/run/docker.sock', $result['removed']);
    }

    public function test_an_override_gets_no_limits_or_defaults_that_would_override_its_base(): void
    {
        $app = Yaml::parse((string) ComposeOverride::harden(
            "services:\n  app:\n    privileged: true\n    environment:\n      A: b\n"
        )['yaml'])['services']['app'];

        $this->assertSame(['environment' => ['A' => 'b']], $app);
    }

    public function test_a_clean_override_comes_back_byte_for_byte(): void
    {
        $raw = "# comment kept\nservices:\n  app:\n    mem_limit: 768m\n    ports: !reset []\n";

        $this->assertSame(['yaml' => $raw, 'removed' => []], ComposeOverride::harden($raw));
    }

    public function test_a_compose_tag_is_not_a_way_around_the_check(): void
    {
        $raw = "services:\n  app:\n    volumes: !override\n      - /var/run/docker.sock:/var/run/docker.sock\n      - ./x:/x\n";

        $result = ComposeOverride::harden($raw);

        $this->assertNotNull($result['yaml']);
        $this->assertStringNotContainsString('docker.sock', $result['yaml']);
        $this->assertStringContainsString('!override', $result['yaml']);
        $this->assertStringContainsString('./x:/x', $result['yaml']);
    }

    public function test_an_unreadable_override_is_reported_rather_than_passed_through(): void
    {
        $this->assertNull(ComposeOverride::harden("services:\n  app: [unclosed\n")['yaml']);
    }

    /**
     * None of the shipped recipes' overrides uses a forbidden key, so every one
     * of them must still be written exactly as it is today.
     */
    public function test_every_shipped_recipe_override_is_unchanged(): void
    {
        $root = dirname(__DIR__, 4) . '/resources/sources';
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getFilename() === 'docker-compose.override.yml' && str_contains($file->getPathname(), '/overrides/')) {
                $files[] = $file->getPathname();
            }
        }
        $this->assertGreaterThan(50, count($files));

        foreach ($files as $path) {
            $raw = (string) file_get_contents($path);
            $this->assertSame(['yaml' => $raw, 'removed' => []], ComposeOverride::harden($raw), $path);
        }
    }
}
