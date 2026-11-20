<?php

namespace Tests\Integration\Console;

use App\Lib\Ip\HostIpSync;
use App\Models\Setting;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The small `system:*` maintenance commands: what each prints and exits with, the host answered by a scripted
 * root broker that records every request.
 */
class SystemMaintenanceCommandsTest extends TestCase
{
    use InMemoryDatabase;

    private ScriptedRoot $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::clearRuntimeSettings();
        $this->root = new ScriptedRoot;
    }

    protected function tearDown(): void
    {
        $this->root->close();
        Setting::clearRuntimeSettings();
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        parent::tearDown();
    }

    /** @return array{int, string} */
    private function run_(string $command, array $args = []): array
    {
        $code = Artisan::call($command, $args);

        return [$code, Artisan::output()];
    }

    private function webserverIs(string $slug): void
    {
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, $slug);
    }

    public function test_the_version_is_the_configured_one_or_unknown(): void
    {
        config(['system.version' => '9.8.7']);
        $this->assertSame([0, "9.8.7\n"], $this->run_('system:version'));

        config(['system.version' => '']);
        $this->assertSame([0, "unknown\n"], $this->run_('system:version'));
    }

    public function test_inspire_prints_a_quote(): void
    {
        [$code, $out] = $this->run_('inspire');

        $this->assertSame(0, $code);
        $this->assertNotSame('', trim($out));
    }

    public function test_the_database_test_succeeds_on_a_reachable_database(): void
    {
        $this->assertSame([0, "Test successful\n"], $this->run_('system:database:test'));
    }

    public function test_the_database_test_fails_once_and_a_negative_retry_count_is_no_retry(): void
    {
        config(['database.connections.broken' => ['driver' => 'sqlite', 'database' => sys_get_temp_dir().'/pa-no-such-dir/x.sqlite', 'prefix' => '']]);
        config(['database.default' => 'broken']);

        $start = microtime(true);
        [$code, $out] = $this->run_('system:database:test', ['--retries' => '-3']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('pa-no-such-dir', $out);
        $this->assertStringNotContainsString('Test successful', $out);
        $this->assertLessThan(4, microtime(true) - $start, 'no retry, so no five-second wait');
    }

    public function test_lscache_reports_its_state_and_flips_the_setting(): void
    {
        $this->webserverIs('apache');

        [$code, $out] = $this->run_('system:lscache', ['--disable' => true]);
        $this->assertSame(0, $code);
        $this->assertSame("LS cache is enabled\nLS cache disabled.\nLS cache config rebuilt.\n", $out);
        $this->assertSame('1', Setting::get('disable-lscache'));

        [$code, $out] = $this->run_('system:lscache', ['--enable' => true]);
        $this->assertSame(0, $code);
        $this->assertSame("LS cache is disabled\nLS cache enabled.\nLS cache config rebuilt.\n", $out);
        $this->assertSame('0', Setting::get('disable-lscache'));

        [$code, $out] = $this->run_('system:lscache', ['--rebuild-config' => true]);
        $this->assertSame([0, "LS cache is enabled\nLS cache config rebuilt.\n"], [$code, $out]);

        $this->assertSame([0, "LS cache is enabled\n"], $this->run_('system:lscache'));
    }

    public function test_lscache_refuses_two_actions_at_once(): void
    {
        $this->assertSame([1, "LS cache is enabled\nToo many arguments\n"], $this->run_('system:lscache', ['--enable' => true, '--disable' => true]));
        $this->assertSame([1, "LS cache is enabled\nToo many arguments\n"], $this->run_('system:lscache', ['--disable' => true, '--rebuild-config' => true]));
        $this->assertSame([], Setting::query()->where('name', 'disable-lscache')->pluck('value')->all());
    }

    public function test_a_webserver_entry_it_does_not_have_is_an_error(): void
    {
        $this->webserverIs('apache');

        $this->assertSame(
            [1, "[ERROR] Unsupported config entry for current webserver\n"],
            $this->run_('system:webserver:update', ['name' => 'serial_number', 'value' => 'ABCD'])
        );
    }

    public function test_a_litespeed_serial_number_is_validated_written_and_applied(): void
    {
        $this->webserverIs('litespeed');

        [$code, $out] = $this->run_('system:webserver:update', ['name' => 'serial_number', 'value' => 'ABCD-1234']);

        $this->assertSame([0, "`serial_number` set to `ABCD-1234`\n"], [$code, $out]);
        $this->assertContains('docker.checkLiteSpeedSerial', $this->root->actions());
    }

    public function test_domain_and_modsecurity_rebuilds_print_why_they_failed(): void
    {
        $this->webserverIs('none');

        $this->assertSame([0, "Invalid webserver: none\n"], $this->run_('system:domain:rebuild'));
        $this->assertSame([1, "Invalid webserver: none\n"], $this->run_('system:modsec:rebuild'));
    }

    public function test_the_modsecurity_config_is_written_and_the_webserver_restarted(): void
    {
        $this->webserverIs('apache');

        $this->assertSame([0, ''], $this->run_('system:modsec:rebuild'));

        $written = array_filter($this->root->requests(), static fn (array $argv): bool => $argv[0] === 'files.copy' && str_ends_with((string) end($argv), '/config/modsecurity/main.conf'));
        $this->assertCount(1, $written);
        $this->assertSame('compose.apacheRestart', array_slice($this->root->actions(), -1)[0], 'the webserver picks the new config up');
    }

    public function test_the_domain_cleanup_prints_why_it_could_not_list_the_vhosts(): void
    {
        $this->webserverIs('nginx-proxy');

        [$code, $out] = $this->run_('system:domain:cleanup', ['--force' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('webserver-config/nginx-proxy/vhosts', $out);
        $this->assertStringNotContainsString('Domains cleaned up.', $out);
    }

    public function test_the_registry_proxy_is_left_alone_when_it_is_not_running(): void
    {
        $this->root->on('docker.isRunning', "false\n");

        $this->assertSame([0, "registry-proxy is not running, nothing to clear.\n"], $this->run_('system:registry-proxy:trim'));
        $this->assertSame(['docker.isRunning'], $this->root->actions());
    }

    public function test_a_registry_proxy_that_cannot_be_asked_counts_as_not_running(): void
    {
        $this->root->on('docker.isRunning', '', 1, null, 'Cannot connect to the Docker daemon');

        $this->assertSame([0, "registry-proxy is not running, nothing to clear.\n"], $this->run_('system:registry-proxy:trim'));
    }

    public function test_a_dry_run_of_the_registry_proxy_trim_changes_nothing(): void
    {
        $this->root->on('docker.isRunning', "true\n");

        $this->assertSame(
            [0, "Would wipe panelalpha-registry-proxy's store and restart it.\n"],
            $this->run_('system:registry-proxy:trim', ['--dry-run' => true])
        );
        $this->assertSame(['docker.isRunning'], $this->root->actions());
    }

    public function test_the_registry_proxy_store_is_wiped_then_the_proxy_restarted(): void
    {
        $this->root->on('docker.isRunning', "true\n");

        $this->assertSame(
            [0, "Wiped and restarted panelalpha-registry-proxy; cache store is empty.\n"],
            $this->run_('system:registry-proxy:trim')
        );
        $this->assertSame(['docker.isRunning', 'docker.wipeRegistryStorage', 'docker.restart'], $this->root->actions());
    }

    public function test_ip_sync_prints_each_line_and_fails_on_one_that_did_not_apply(): void
    {
        $this->syncAnswers([['ok' => true, 'message' => 'Added 1'], ['ok' => false, 'message' => 'Could not add 2']]);

        [$code, $out] = $this->run_('system:ip:sync');
        $this->assertSame([1, "Added 1\nCould not add 2\n"], [$code, $out]);

        $this->syncAnswers([['ok' => false, 'message' => 'Could not add 2']]);
        $this->assertSame([0, "Could not add 2\n"], $this->run_('system:ip:sync', ['--skip-errors' => true]));

        $this->syncAnswers([['ok' => true, 'message' => 'Added 1']]);
        $this->assertSame([0, "Added 1\n"], $this->run_('system:ip:sync', ['--restart-networking' => true]));
    }

    public function test_ip_sync_reports_a_failure_unless_told_to_skip_errors(): void
    {
        $sync = Mockery::mock(HostIpSync::class);
        $sync->shouldReceive('sync')->andThrow(new \RuntimeException('no route'));
        $this->app->instance(HostIpSync::class, $sync);

        $this->assertSame([1, "no route\n"], $this->run_('system:ip:sync'));
        $this->assertSame([0, ''], $this->run_('system:ip:sync', ['--skip-errors' => true]));
    }

    public function test_acme_challenges_prune_nothing_when_there_is_no_challenge_directory(): void
    {
        $this->root->on('files.isDirectory', '', 1);

        [$code, $out] = $this->run_('acme:challenge:prune', ['--ttl-hours' => '2']);

        $this->assertSame(0, $code);
        $this->assertSame("Pruning HTTP ACME challenges older than 2h...\nPruned 0 domain(s) that no longer have challenges.\n", $out);
        $this->assertSame(['files.isDirectory'], $this->root->actions());
    }

    /** @param list<array{ok: bool, message: string}> $lines */
    private function syncAnswers(array $lines): void
    {
        $sync = Mockery::mock(HostIpSync::class);
        $sync->shouldReceive('sync')->andReturn($lines);
        $this->app->instance(HostIpSync::class, $sync);
    }
}
