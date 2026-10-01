<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeExtends;
use App\Lib\Deploy\Compose\ComposeHarden;
use PHPUnit\Framework\TestCase;

/**
 * A service's `extends:` was left untouched, so the extended
 * service's privileged/host-network/docker.sock keys were merged in by Compose
 * after the hardener had run and passed straight into the account.
 */
class ComposeExtendsTest extends TestCase
{
    /** @param array<string, string> $files project-relative path => contents */
    private function reader(array $files): callable
    {
        return static fn (string $relative): ?string => $files[$relative] ?? null;
    }

    public function test_extends_file_escapes_are_merged_in_then_stripped_by_the_hardener(): void
    {
        $compose = [
            'services' => [
                'app' => [
                    'image' => 'busybox',
                    'extends' => ['file' => './base.yml', 'service' => 'evil'],
                ],
            ],
        ];
        $base = <<<'YAML'
        services:
          evil:
            privileged: true
            network_mode: host
            cap_add: [SYS_ADMIN]
            volumes:
              - /var/run/docker.sock:/s
              - /:/host
        YAML;

        $resolved = ComposeExtends::resolve($compose, $this->reader(['base.yml' => $base]));
        $app = $resolved['services']['app'];

        // The base's keys are now on the service itself (so the hardener sees them)...
        $this->assertArrayNotHasKey('extends', $app);
        $this->assertTrue($app['privileged']);
        $this->assertSame('host', $app['network_mode']);

        // ...and the hardener removes every one of them.
        $hardened = ComposeHarden::apply($resolved)['services']['app'];
        $this->assertArrayNotHasKey('privileged', $hardened);
        $this->assertArrayNotHasKey('network_mode', $hardened);
        $this->assertArrayNotHasKey('cap_add', $hardened);
        $this->assertArrayNotHasKey('volumes', $hardened);
    }

    public function test_same_file_extends_is_resolved_against_the_sibling_services(): void
    {
        $compose = [
            'services' => [
                'base' => ['privileged' => true, 'image' => 'busybox'],
                'app' => ['extends' => 'base', 'command' => 'run'],
            ],
        ];

        $app = ComposeExtends::resolve($compose, $this->reader([]))['services']['app'];

        $this->assertArrayNotHasKey('extends', $app);
        $this->assertTrue($app['privileged']);
        $this->assertSame('busybox', $app['image']);
        $this->assertSame('run', $app['command']);
    }

    public function test_the_extending_services_own_keys_win_the_merge(): void
    {
        $compose = [
            'services' => [
                'base' => ['image' => 'base:1', 'environment' => ['A' => '1', 'B' => '1']],
                'app' => ['extends' => 'base', 'image' => 'app:2', 'environment' => ['B' => '2']],
            ],
        ];

        $app = ComposeExtends::resolve($compose, $this->reader([]))['services']['app'];

        $this->assertSame('app:2', $app['image']);
        $this->assertSame(['A' => '1', 'B' => '2'], $app['environment']);
    }

    public function test_list_keys_are_appended_base_first(): void
    {
        $compose = [
            'services' => [
                'base' => ['image' => 'x', 'cap_add' => ['NET_RAW']],
                'app' => ['extends' => 'base', 'cap_add' => ['CHOWN']],
            ],
        ];

        $app = ComposeExtends::resolve($compose, $this->reader([]))['services']['app'];

        $this->assertSame(['NET_RAW', 'CHOWN'], $app['cap_add']);
    }

    public function test_extends_file_paths_are_rebased_from_the_files_directory(): void
    {
        $compose = [
            'services' => [
                'app' => ['image' => 'x', 'extends' => ['file' => './docker/base.yml', 'service' => 'b']],
            ],
        ];
        $base = <<<'YAML'
        services:
          b:
            volumes:
              - ./data:/data
        YAML;

        $app = ComposeExtends::resolve($compose, $this->reader(['docker/base.yml' => $base]))['services']['app'];

        // ./data in docker/base.yml means docker/data from the project root.
        $this->assertSame(['./docker/data:/data'], $app['volumes']);
    }

    public function test_extends_file_outside_the_project_is_refused(): void
    {
        $compose = [
            'services' => [
                'app' => ['image' => 'x', 'extends' => ['file' => '../../etc/base.yml', 'service' => 'b']],
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        ComposeExtends::resolve($compose, $this->reader([]));
    }

    public function test_extends_file_that_cannot_be_read_fails_the_deploy(): void
    {
        $compose = [
            'services' => [
                'app' => ['image' => 'x', 'extends' => ['file' => './missing.yml', 'service' => 'b']],
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        ComposeExtends::resolve($compose, $this->reader([]));
    }

    public function test_a_missing_same_file_target_fails_rather_than_leaving_extends(): void
    {
        $compose = ['services' => ['app' => ['extends' => 'nope']]];

        $this->expectException(\InvalidArgumentException::class);
        ComposeExtends::resolve($compose, $this->reader([]));
    }

    public function test_chained_extends_resolves_transitively(): void
    {
        $compose = [
            'services' => [
                'a' => ['privileged' => true, 'image' => 'x'],
                'b' => ['extends' => 'a', 'user' => 'root'],
                'app' => ['extends' => 'b', 'command' => 'go'],
            ],
        ];

        $app = ComposeExtends::resolve($compose, $this->reader([]))['services']['app'];

        $this->assertTrue($app['privileged']);
        $this->assertSame('root', $app['user']);
        $this->assertSame('go', $app['command']);
    }

    public function test_a_same_file_extends_cycle_is_refused(): void
    {
        $compose = [
            'services' => [
                'a' => ['extends' => 'b'],
                'b' => ['extends' => 'a'],
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        ComposeExtends::resolve($compose, $this->reader([]));
    }

    public function test_a_service_without_extends_is_untouched(): void
    {
        $compose = ['services' => ['app' => ['image' => 'x', 'ports' => ['80:80']]]];

        $this->assertSame($compose, ComposeExtends::resolve($compose, $this->reader([])));
    }
}
