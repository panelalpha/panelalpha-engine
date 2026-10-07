<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeOverride;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A compose override is layered over the hardened run file
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

    public function test_an_extends_in_an_override_is_dropped_so_it_cannot_pull_in_an_unhardened_service(): void
    {
        // The override path has no file reader, so an unresolved
        // extends would let Compose merge an unhardened service. It is dropped.
        $raw = "services:\n  app:\n    extends:\n      file: ./base.yml\n      service: evil\n    environment:\n      A: b\n";

        $result = ComposeOverride::harden($raw);
        $app = Yaml::parse((string) $result['yaml'])['services']['app'];

        $this->assertArrayNotHasKey('extends', $app);
        $this->assertSame(['A' => 'b'], $app['environment']);
        $this->assertContains('app: extends', $result['removed']);
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

    /** An override can redefine a volume the base file's service already mounts. */
    public function test_a_volume_redefined_to_bind_a_path_is_cleared_even_without_services(): void
    {
        $raw = "volumes:\n  data:\n    driver_opts:\n      type: none\n      o: bind\n      device: /entrypoint.d\n";

        $result = ComposeOverride::harden($raw);

        $this->assertSame(['volume data: driver_opts'], $result['removed']);
        $this->assertSame(['volumes' => ['data' => []]], Yaml::parse((string) $result['yaml']));
    }

    public function test_an_unreadable_override_is_reported_rather_than_passed_through(): void
    {
        $this->assertNull(ComposeOverride::harden("services:\n  app: [unclosed\n")['yaml']);
    }

    /** An override that pulls services in with include: used to be layered as written. */
    public function test_services_an_override_includes_are_merged_in_and_hardened(): void
    {
        $files = [
            'evil.yml' => "services:\n  x:\n    image: alpine\n    privileged: true\n    healthcheck:\n      test: []\n"
                . "    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n      - ./data:/data\n",
        ];
        $raw = "include:\n  - evil.yml\nservices:\n  app:\n    ports: !reset []\n";

        $result = ComposeOverride::harden($raw, fn (string $path): ?string => $files[$path] ?? null);

        $this->assertNotNull($result['yaml']);
        $this->assertStringContainsString('!reset', $result['yaml']);
        $parsed = Yaml::parse($result['yaml'], Yaml::PARSE_CUSTOM_TAGS);
        $this->assertArrayNotHasKey('include', $parsed);
        $x = $parsed['services']['x'];
        $this->assertArrayNotHasKey('privileged', $x);
        $this->assertSame(['./data:/data'], $x['volumes']);
        $this->assertSame([], $x['healthcheck']['test']);
        $this->assertStringContainsString('test: []', $result['yaml']);
        $this->assertContains('x: privileged', $result['removed']);
    }

    public function test_an_include_only_override_is_not_passed_through(): void
    {
        $files = ['evil.yml' => "services:\n  x:\n    image: alpine\n    pid: host\n"];

        $result = ComposeOverride::harden("include:\n  - path: evil.yml\n", fn (string $path): ?string => $files[$path] ?? null);

        $this->assertSame(['x: pid'], $result['removed']);
        $this->assertSame(['image' => 'alpine'], Yaml::parse((string) $result['yaml'])['services']['x']);
    }

    public function test_an_include_that_cannot_be_checked_fails(): void
    {
        foreach (["include:\n  - ../evil.yml\n", "include:\n  - missing.yml\n"] as $raw) {
            try {
                ComposeOverride::harden($raw, fn (string $path): ?string => null);
                $this->fail("passed through: {$raw}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('could not be read', $e->getMessage());
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        ComposeOverride::harden("include:\n  - evil.yml\n");
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

        // What a recipe's prepare hook writes to .env before the override is hardened.
        $env = ['CRAFTY_DATA' => ['/home/acct/.panelalpha/crafty']];
        foreach ($files as $path) {
            $raw = (string) file_get_contents($path);
            $this->assertSame(['yaml' => $raw, 'removed' => []], ComposeOverride::harden($raw, null, $env, 'acct', '/nonexistent-core-view/acct/project'), $path);
        }
    }
    public function test_an_interpolated_mount_source_is_checked_against_the_projects_env(): void
    {
        $raw = <<<'YAML'
        services:
          app:
            volumes:
              - ${SOCK:-/var/run/docker.sock}:/sock
              - ${X}:/y
              - ${DATA_DIR:-./data}:/data
        secrets:
          key:
            file: ${KEY_FILE}
        YAML;

        $result = ComposeOverride::harden($raw, env: ['X' => ['/etc'], 'KEY_FILE' => ['/proc/self/environ']]);
        $parsed = Yaml::parse((string) $result['yaml']);

        $this->assertSame(['${DATA_DIR:-./data}:/data'], $parsed['services']['app']['volumes']);
        $this->assertEmpty($parsed['secrets'] ?? []);
        $this->assertSame([
            'app: volume ${SOCK:-/var/run/docker.sock}:/sock',
            'app: volume ${X}:/y',
            'secret key: file ${KEY_FILE}',
        ], $result['removed']);
    }

    public function test_a_service_only_the_override_names_and_cannot_run_is_dropped(): void
    {
        // BookStack's recipe silences a `node` service the run file no longer has.
        $raw = "services:\n  node:\n    entrypoint: [\"/bin/sh\", \"-c\", \"exit 0\"]\n    restart: \"no\"\n"
            . "  app:\n    environment: { A: b }\n  cache:\n    image: redis:7\n";

        $result = ComposeOverride::withoutUndefinedServices($raw, ['app', 'db']);

        $this->assertSame(['node'], $result['dropped']);
        $parsed = Yaml::parse((string) $result['yaml']);
        $this->assertSame(['app', 'cache'], array_keys($parsed['services']));
    }

    public function test_an_override_whose_services_all_exist_is_returned_as_written(): void
    {
        $raw = "# keep me\nservices:\n  node:\n    restart: \"no\"\n";

        $this->assertSame(['yaml' => $raw, 'dropped' => []], ComposeOverride::withoutUndefinedServices($raw, ['node', 'app']));
    }

    public function test_an_override_left_with_no_services_still_parses_as_a_map(): void
    {
        $result = ComposeOverride::withoutUndefinedServices("services:\n  node:\n    restart: \"no\"\n", ['app']);

        $this->assertSame(['node'], $result['dropped']);
        $this->assertSame(['services' => []], Yaml::parse((string) $result['yaml']));
        $this->assertStringContainsString('{', (string) $result['yaml']);
    }
}
