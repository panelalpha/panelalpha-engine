<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Compose\DeployCompose;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class DeployComposeDockerfileTest extends TestCase
{
    public function test_adds_no_environment_by_default(): void
    {
        $service = Yaml::parse(DeployCompose::dockerfile('Dockerfile', 3000))['services']['app'];

        $this->assertArrayNotHasKey('environment', $service);
        $this->assertSame(['3000:3000'], $service['ports']);
    }

    /**
     * we-promise/sure ships its own Dockerfile, so the Rails recipe never
     * runs — and config/master.key is gitignored, so the clone has no
     * secret_key_base and Rails aborts on boot with an ArgumentError.
     */
    public function test_carries_only_what_the_repo_cannot_supply_itself(): void
    {
        $yaml = DeployCompose::dockerfile('Dockerfile', 3000, ['env' => ['SECRET_KEY_BASE' => 'abc123']]);
        $service = Yaml::parse($yaml)['services']['app'];

        $this->assertSame(['SECRET_KEY_BASE' => 'abc123'], $service['environment']);
        // The author's Dockerfile decides everything else.
        $this->assertArrayNotHasKey('command', $service);
        $this->assertArrayNotHasKey('entrypoint', $service);
    }

    /** MintHCM's CMD ends in `exec bash`: its compose runs it with a terminal. */
    public function test_carries_the_repository_services_terminal_settings(): void
    {
        $service = Yaml::parse(DeployCompose::dockerfile('docker/Dockerfile', 80, ['tty' => true, 'stdin_open' => true]))['services']['app'];

        $this->assertTrue($service['tty']);
        $this->assertTrue($service['stdin_open']);
    }

    public function test_sets_no_terminal_unless_asked(): void
    {
        $service = Yaml::parse(DeployCompose::dockerfile('Dockerfile', 80))['services']['app'];

        $this->assertArrayNotHasKey('tty', $service);
        $this->assertArrayNotHasKey('stdin_open', $service);
    }

    public function test_port_80_is_published_on_8080(): void
    {
        $service = Yaml::parse(DeployCompose::dockerfile('Dockerfile', 80))['services']['app'];

        $this->assertSame(['8080:80'], $service['ports']);
    }

    /**
     * A Dockerfile whose variant is picked by an ARG cannot be built without
     * one. Kimai's file selects between an FPM base and an Apache one with
     * `ARG BASE`, which the later `FROM ${BASE}-base` stages read — so the
     * default target is the FPM variant, on port 9000, speaking FastCGI.
     *
     * `--target` is not the fix and is not what is emitted: it stops the build
     * *at* the named stage, and Kimai's `apache` stage is a base image with no
     * application in it.
     */
    public function test_build_args_reach_the_build_and_force_the_map_form(): void
    {
        $yaml = DeployCompose::dockerfile('Dockerfile', 8001, ['build_args' => ['BASE' => 'apache']]);
        $service = Yaml::parse($yaml)['services']['app'];

        $this->assertSame('.', $service['build']['context']);
        $this->assertSame(['BASE' => 'apache'], $service['build']['args']);
        $this->assertArrayNotHasKey('dockerfile', $service['build']);
        $this->assertSame(['8001:8001'], $service['ports']);
    }

    /** No args, no map: the common case still reads like a hand-written file. */
    public function test_no_build_args_keeps_the_shorthand(): void
    {
        $service = Yaml::parse(DeployCompose::dockerfile('Dockerfile', 3000))['services']['app'];

        $this->assertSame('.', $service['build']);
    }

    /** An empty map is a claim nobody made; it must not appear. */
    public function test_an_empty_build_args_map_adds_nothing(): void
    {
        $service = Yaml::parse(DeployCompose::dockerfile('Dockerfile', 3000, ['build_args' => []]))['services']['app'];

        $this->assertSame('.', $service['build']);
    }

    /**
     * A database on the account's own MySQL server resolves on the host and
     * nowhere inside the account's nested Docker, so the strategy pins the
     * name — and the compose file has to carry the pin.
     */
    public function test_extra_hosts_are_carried_into_the_app_service(): void
    {
        $yaml = DeployCompose::dockerfile('Dockerfile', 8001, [
            'extra_hosts' => ['database-users.shared-hosting.palocal:172.25.0.14'],
        ]);
        $service = Yaml::parse($yaml)['services']['app'];

        $this->assertSame(['database-users.shared-hosting.palocal:172.25.0.14'], $service['extra_hosts']);
    }
}
