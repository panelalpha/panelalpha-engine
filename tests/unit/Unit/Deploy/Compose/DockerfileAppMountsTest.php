<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\DeployCompose;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The repository's own app service carried into the generated Dockerfile compose: its mounts, declared as
 * volumes, its terminal, and a declared VOLUME it already mounts.
 */
class DockerfileAppMountsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private function compose(array $decision): array
    {
        return Yaml::parse(DeployCompose::dockerfile('Dockerfile', 8080, $decision));
    }

    public function test_the_app_services_mounts_are_kept_and_their_volumes_declared(): void
    {
        $compose = $this->compose([
            'app_mounts' => [' uploads:/app/uploads ', '', 7, 'cache:/app/cache'],
            'volumes' => ['uploads' => ['driver' => 'local']],
        ]);

        $this->assertSame(['uploads:/app/uploads', 'cache:/app/cache'], $compose['services']['app']['volumes']);
        $this->assertSame(['driver' => 'local'], $compose['volumes']['uploads']);
        $this->assertArrayHasKey('cache', $compose['volumes']);
    }

    public function test_mounts_that_are_not_a_list_are_ignored(): void
    {
        $compose = $this->compose(['app_mounts' => 'uploads:/app/uploads']);

        $this->assertArrayNotHasKey('volumes', $compose['services']['app']);
    }

    public function test_a_service_that_needs_a_terminal_keeps_one(): void
    {
        $compose = $this->compose(['tty' => true, 'stdin_open' => true]);

        $this->assertTrue($compose['services']['app']['tty']);
        $this->assertTrue($compose['services']['app']['stdin_open']);
        $this->assertArrayNotHasKey('tty', $this->compose(['tty' => 'yes'])['services']['app']);
    }

    public function test_a_declared_volume_the_app_already_mounts_is_not_mounted_twice(): void
    {
        $compose = $this->compose([
            'app_mounts' => ['appdata:/data'],
            'dockerfile_volumes' => ['/data', '/config'],
        ]);

        $this->assertSame(['appdata:/data', 'data-config:/config'], $compose['services']['app']['volumes']);
    }
}
