<?php

namespace Tests\Unit\Apis;

use App\System\Project\Dind\AccountTemplate;
use PHPUnit\Framework\TestCase;

/**
 * The init scripts an account runs before s6 starts dockerd, and the
 * daemon.json rendered next to them.
 *
 * The scripts run from `/entrypoint.sh` as `entrypoint.d/*.sh` one-shots ahead
 * of `exec s6-svscan` — so this is the hook for anything that must be true
 * before the nested Docker daemon comes up, without rebuilding the account image.
 *
 * Since `/run` became a tmpfs it starts empty on every boot, and the
 * directories Debian's packages expect there — `/run/php` owned by www-data,
 * `/run/lock`, `/run/dbus` — are not recreated by anything else: there is no
 * systemd in the container to run tmpfiles at boot.
 */
class DindEntrypointInitScriptsTest extends TestCase
{
    private function template(): AccountTemplate
    {
        // Mocks rather than stubs, because the property and accessors are
        // typed against the concrete classes. Only username and uid are read.
        // A real model: getUid() reads details['UID'], so nothing needs
        // stubbing and the test exercises the same accessors production does.
        $model = new \App\Models\User();
        $model->username = 'acme';
        $model->details = ['UID' => 1234];

        $dind = $this->createStub(\App\System\Project\Dind::class);
        $dind->method('userModel')->willReturn($model);

        $template = (new \ReflectionClass(AccountTemplate::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(AccountTemplate::class, 'project');
        $property->setAccessible(true);
        $property->setValue($template, $dind);

        return $template;
    }

    private function script(): string
    {
        return $this->template()->entrypointInitScripts()['useradd.sh'] ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    private function daemonJson(): array
    {
        $json = json_decode($this->template()->daemonJson(), true);
        $this->assertIsArray($json, 'daemonJson() did not return valid JSON');

        return $json;
    }

    /**
     * Outside a booted application AccountRuntime falls back to sysbox, so the
     * privileged-only cgroup handling must not appear. Belt and braces with
     * DindAccountRuntimeTest: this is the path a production account takes.
     */
    public function test_the_cgroup_dance_is_absent_by_default(): void
    {
        $this->assertStringNotContainsString('cgroup.subtree_control', $this->script());
    }

    public function test_run_is_repopulated_before_the_daemon_starts(): void
    {
        $this->assertStringContainsString('systemd-tmpfiles --create', $this->script());
    }

    public function test_repopulating_run_can_never_fail_the_boot(): void
    {
        // If tmpfiles is unhappy the account must still come up: an init
        // script that exits non-zero would take Docker and every app with it,
        // which is a worse failure than the one being prevented.
        $this->assertMatchesRegularExpression('/systemd-tmpfiles --create[^\n]*\|\| true/', $this->script());
    }

    public function test_the_account_user_is_still_created(): void
    {
        // The script's original job, unchanged.
        $script = $this->script();

        $this->assertStringContainsString('useradd --uid 1234', $script);
        $this->assertStringContainsString('acme', $script);
    }

    public function test_the_daemon_data_root_dir_is_still_created(): void
    {
        $this->assertStringContainsString('mkdir -p "/home/$(hostname)/docker"', $this->script());
    }

    /**
     * daemon.json moved to a host-rendered, bind-mounted file:
     * nothing inside the account writes it any more, so running anything in
     * the account to patch an old account's registry settings is no longer
     * needed in the first place.
     */
    public function test_the_init_script_no_longer_writes_daemon_json(): void
    {
        $script = $this->script();

        $this->assertStringNotContainsString('/etc/docker/daemon.json', $script);
        $this->assertStringNotContainsString('cat >', $script);
    }

    public function test_the_daemon_data_root_matches_the_mkdir_above(): void
    {
        $this->assertSame('/home/acme/docker', $this->daemonJson()['data-root'] ?? null);
    }

    /**
     * A mirror endpoint is subject to the same TLS enforcement as any named
     * registry: without it in insecure-registries too, the daemon would
     * refuse the plain-HTTP proxy and every pull would fall straight through
     * to Docker Hub unauthenticated, silently defeating the proxy.
     */
    public function test_registry_proxy_is_configured_and_marked_insecure(): void
    {
        $json = $this->daemonJson();

        $this->assertSame(
            ['http://panelalpha-registry-proxy:5000'],
            $json['registry-mirrors'] ?? null
        );
        $this->assertContains('panelalpha-registry-proxy:5000', $json['insecure-registries'] ?? []);
    }

    public function test_the_existing_image_store_registry_is_untouched(): void
    {
        $this->assertContains('panelalpha-cache-registry:5000', $this->daemonJson()['insecure-registries'] ?? []);
    }

    /** Without it, a dockerd OOM kill left every container Exited after s6 restarted it. */
    /** Docker's default json-file log has no cap and lives inside the account's quota. */
    public function test_container_logs_are_rotated_by_default(): void
    {
        $json = $this->daemonJson();

        $this->assertSame('json-file', $json['log-driver'] ?? null);
        $this->assertSame(['max-size' => '10m', 'max-file' => '3'], $json['log-opts'] ?? null);
    }

    public function test_containers_outlive_a_daemon_restart(): void
    {
        $this->assertTrue($this->daemonJson()['live-restore'] ?? null);
    }

    public function test_the_account_group_matches_its_username(): void
    {
        $this->assertSame('acme', $this->daemonJson()['group'] ?? null);
    }
}
