<?php

namespace Tests\Unit\Apis;

use App\System\Project\Dind\AccountRuntime;
use App\System\Project\Dind\AccountTemplate;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * An account container is isolated by sysbox in production and by privilege
 * only when the engine itself runs inside a sysbox container, where
 * sysbox-in-sysbox is unsupported.
 *
 * The setting has to fail closed: a typo, an empty value or a missing config
 * must land on sysbox, because the privileged spelling gives a tenant root
 * inside the engine. So the fallbacks are tested as carefully as the choice.
 *
 * The template assertions render the real Blade file and parse the result as
 * YAML rather than matching strings, since the failure this guards against is
 * an isolation line that lands at the wrong indentation and silently attaches
 * to nothing.
 */
class DindAccountRuntimeTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../../templates/user/dind/project/docker-compose.yml.blade.php';

    private function renderedService(): array
    {
        $this->assertFileExists(self::TEMPLATE, 'the dind account template moved');

        $yaml = Blade::render((string) file_get_contents(self::TEMPLATE), [
            'user' => 'alice',
            'isolation' => AccountRuntime::composeIsolation(),
            'cpu_limit' => '',
            'memory_limit' => '',
            'device_read_bps' => null,
            'device_write_bps' => null,
            'block_device' => '',
        ]);

        return Yaml::parse($yaml)['services']['dind'] ?? [];
    }

    /**
     * The generated account init script, built the way
     * DindEntrypointInitScriptsTest builds it.
     */
    private function initScript(): string
    {
        $model = new \App\Models\User();
        $model->username = 'acme';
        $model->details = ['UID' => 1234];

        $dind = $this->createStub(\App\System\Project\Dind::class);
        $dind->method('userModel')->willReturn($model);

        $template = (new \ReflectionClass(AccountTemplate::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(AccountTemplate::class, 'project');
        $property->setAccessible(true);
        $property->setValue($template, $dind);

        return $template->entrypointInitScripts()['useradd.sh'] ?? '';
    }

    /**
     * Sysbox virtualises cgroups for the containers it runs, so an account on
     * sysbox must not touch the hierarchy. Emitting the nesting dance there
     * would have production accounts rearranging a cgroup tree that is already
     * correct — this is the assertion that keeps the fix off the default path.
     */
    public function test_no_cgroup_dance_is_emitted_on_sysbox(): void
    {
        config(['env.DIND_RUNTIME' => null]);

        $this->assertStringNotContainsString('cgroup.subtree_control', $this->initScript());
        $this->assertStringNotContainsString('/sys/fs/cgroup/init', $this->initScript());
    }

    /**
     * A privileged account sees the real cgroup2 hierarchy and hits the "no
     * internal processes" rule, so it needs the dance docker:dind performs.
     */
    public function test_the_cgroup_dance_is_emitted_when_privileged(): void
    {
        config(['env.DIND_RUNTIME' => 'privileged']);
        $script = $this->initScript();

        $this->assertStringContainsString('/sys/fs/cgroup/init', $script);
        $this->assertStringContainsString('cgroup.subtree_control', $script);
    }

    /**
     * Rearranging cgroups is best-effort: if it fails the account still has to
     * boot, so the nested daemon can fail later with its own message rather
     * than this taking Docker and every app down with it.
     */
    public function test_the_cgroup_dance_can_never_fail_the_boot(): void
    {
        config(['env.DIND_RUNTIME' => 'privileged']);
        $script = $this->initScript();

        $this->assertStringContainsString('mkdir -p /sys/fs/cgroup/init 2>/dev/null', $script);
        $this->assertMatchesRegularExpression('/cgroup\.subtree_control 2>\/dev\/null \|\| true/', $script);
    }

    public function test_sysbox_is_the_default(): void
    {
        config(['env.DIND_RUNTIME' => null]);

        $this->assertSame(AccountRuntime::SYSBOX, AccountRuntime::configured());
        $this->assertTrue(AccountRuntime::isSysbox());
        $this->assertSame('runtime: sysbox-runc', AccountRuntime::composeIsolation());
        $this->assertSame(['--runtime', 'sysbox-runc'], AccountRuntime::dockerRunArgs());
    }

    public function test_privileged_can_be_selected(): void
    {
        config(['env.DIND_RUNTIME' => 'privileged']);

        $this->assertSame(AccountRuntime::PRIVILEGED, AccountRuntime::configured());
        $this->assertFalse(AccountRuntime::isSysbox());
        $this->assertSame('privileged: true', AccountRuntime::composeIsolation());
        $this->assertSame(['--privileged'], AccountRuntime::dockerRunArgs());
    }

    public function test_the_selection_tolerates_case_and_padding(): void
    {
        config(['env.DIND_RUNTIME' => '  PRIVILEGED ']);

        $this->assertSame(AccountRuntime::PRIVILEGED, AccountRuntime::configured());
    }

    /**
     * Anything unrecognised has to mean sysbox. Falling the other way would
     * turn a typo into privileged tenant containers on a production host.
     */
    public function test_an_unrecognised_value_falls_back_to_sysbox(): void
    {
        foreach (['', '   ', 'sysbox', 'runc', 'true', 'yes'] as $value) {
            config(['env.DIND_RUNTIME' => $value]);
            $this->assertSame(
                AccountRuntime::SYSBOX,
                AccountRuntime::configured(),
                "DIND_RUNTIME='{$value}' must fall back to sysbox"
            );
        }
    }

    public function test_the_template_renders_the_sysbox_runtime_by_default(): void
    {
        config(['env.DIND_RUNTIME' => null]);
        $service = $this->renderedService();

        $this->assertSame('sysbox-runc', $service['runtime'] ?? null);
        $this->assertArrayNotHasKey('privileged', $service);
    }

    public function test_the_template_renders_privileged_when_selected(): void
    {
        config(['env.DIND_RUNTIME' => 'privileged']);
        $service = $this->renderedService();

        $this->assertTrue($service['privileged'] ?? null);
        $this->assertArrayNotHasKey('runtime', $service);
    }

    /**
     * The isolation choice must not be reintroduced as a literal anywhere in
     * the template — that is the bug this seam exists to remove.
     */
    public function test_the_template_does_not_hardcode_a_runtime(): void
    {
        $this->assertStringNotContainsString(
            'runtime: sysbox-runc',
            (string) file_get_contents(self::TEMPLATE),
            'the account template must take its isolation from AccountRuntime'
        );
    }
}
