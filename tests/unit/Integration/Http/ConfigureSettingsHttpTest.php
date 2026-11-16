<?php

namespace Tests\Integration\Http;

use App\Lib\Deploy\Telemetry\NotificationPreferences;
use App\Lib\Domains\DomainPlan;
use App\Models\Setting;
use App\System;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * What `pae configure` changes, over the API: every area readable, and every one core reads at use writable, stored
 * in the settings table over `.env-core`. The engine's address and the site database's sizes are read by root or a
 * host container, so they answer here and change only from the wizard.
 */
class ConfigureSettingsHttpTest extends TestCase
{
    use InMemoryDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware();
        Queue::fake();

        $this->dir = sys_get_temp_dir() . '/configure-http-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/.env', "APP_KEY=base64:x\nAPP_URL=https://engine.example.test:2011\nTELEMETRY_TIER=1\n# MCP_PERMISSION_MODE=\n");
        file_put_contents($this->dir . '/.env-compose', "COMPOSE_PROFILES=full\nSITES_DB_KEY_BUFFER_SIZE=16M\n");
        $this->app->useEnvironmentPath($this->dir);
        $this->app->loadEnvironmentFrom('.env');
        config([
            'app.url' => 'https://engine.example.test:2011',
            'telemetry.tier' => 1,
            'monitoring.url' => '',
            'deploy.prewarm_images' => '',
            'deploy.prewarm_budget' => '',
            'deploy.prewarm_reserve' => '',
        ]);
        $this->app->instance(System::class, $this->system());
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        Setting::flushCache();
        array_map('unlink', array_filter(glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function system(): System
    {
        return new class ($this->dir) extends System {
            public function __construct(private string $dir)
            {
            }

            public function engineDirPath(): string
            {
                return $this->dir . '/compose';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                if (is_array($cmd) && LocalRoot::action(array_values($cmd)) === 'compose.sitesDbCacheSizes') {
                    return "33554432\t16777216\t8388608";
                }

                throw new RuntimeException('nothing else runs here');
            }
        };
    }

    public function test_telemetry_reads_each_value_and_where_it_came_from(): void
    {
        $response = $this->getJson('/api/system/telemetry');

        $response->assertOk();
        $this->assertSame(['value' => 'repo', 'source' => 'env'], $response->json('data.tier'));
        $this->assertSame(['value' => 'off', 'source' => 'default'], $response->json('data.source_bundle'));
        $this->assertSame('default', $response->json('data.sending.source'));
        $this->assertNull($response->json('data.endpoint'));
    }

    public function test_telemetry_sending_is_switched_in_the_settings_table(): void
    {
        Setting::set(NotificationPreferences::SETTING_TELEMETRY_ENABLED, '1');

        $response = $this->putJson('/api/system/telemetry', ['sending' => false]);

        $response->assertOk();
        $this->assertSame('0', Setting::get(NotificationPreferences::SETTING_TELEMETRY_ENABLED));
        $this->assertSame(['value' => false, 'source' => 'setting'], $response->json('data.sending'));
        $this->assertSame(['ok' => false, 'message' => 'No monitoring endpoint configured'], $response->json('monitoring'));
    }

    public function test_turning_sending_on_tells_monitoring(): void
    {
        config(['monitoring.url' => 'https://monitoring.example.test']);
        Http::fake(['*' => Http::response(['ok' => true])]);
        Setting::set(NotificationPreferences::SETTING_TELEMETRY_ENABLED, '0');

        $response = $this->putJson('/api/system/telemetry', ['sending' => true]);

        $response->assertOk();
        $this->assertTrue($response->json('monitoring.ok'));
        Http::assertSent(fn ($request): bool => $request['events'][0]['payload']['enabled'] === true);
    }

    public function test_an_unchanged_sending_tells_monitoring_nothing(): void
    {
        Http::fake();
        Setting::set(NotificationPreferences::SETTING_TELEMETRY_ENABLED, '1');

        $response = $this->putJson('/api/system/telemetry', ['sending' => true]);

        $response->assertOk();
        $this->assertNull($response->json('monitoring'));
        Http::assertNothingSent();
    }

    public function test_telemetry_put_refuses_an_empty_body_and_a_field_that_is_not_a_flag(): void
    {
        $this->putJson('/api/system/telemetry', [])->assertStatus(422)->assertJsonValidationErrors('sending');
        $this->putJson('/api/system/telemetry', ['sending' => 'perhaps'])->assertStatus(422)->assertJsonValidationErrors('sending');
    }

    public function test_telemetry_values_are_stored_over_env_core_and_null_hands_them_back(): void
    {
        $response = $this->putJson('/api/system/telemetry', ['tier' => 'metadata', 'source_bundle' => 'failed', 'bug_reports' => false]);

        $response->assertOk();
        $this->assertSame(['value' => 'metadata', 'source' => 'setting'], $response->json('data.tier'));
        $this->assertSame(['value' => 'failed', 'source' => 'setting'], $response->json('data.source_bundle'));
        $this->assertSame(['value' => false, 'source' => 'setting'], $response->json('data.bug_reports'));
        $this->assertStringContainsString('TELEMETRY_TIER=1', (string) file_get_contents($this->dir . '/.env'));

        $response = $this->putJson('/api/system/telemetry', ['tier' => null, 'source_bundle' => null]);

        $this->assertSame(['value' => 'repo', 'source' => 'env'], $response->json('data.tier'));
        $this->assertSame(['value' => 'off', 'source' => 'default'], $response->json('data.source_bundle'));
        $this->assertNull(Setting::get('telemetry_tier'));
    }

    public function test_telemetry_refuses_a_tier_or_mode_it_does_not_know(): void
    {
        $this->putJson('/api/system/telemetry', ['tier' => '2'])->assertStatus(422)->assertJsonValidationErrors('tier');
        $this->putJson('/api/system/telemetry', ['source_bundle' => 'always'])->assertStatus(422)->assertJsonValidationErrors('source_bundle');
        $this->putJson('/api/system/telemetry', ['bug_reports' => 'sometimes'])->assertStatus(422)->assertJsonValidationErrors('bug_reports');

        $this->assertNull(Setting::get('telemetry_tier'));
    }

    public function test_engine_address_reads_the_url_and_the_stored_names(): void
    {
        Setting::set('cert_domain', 'panel.example.test');

        $response = $this->getJson('/api/system/engine-address');

        $response->assertOk();
        $this->assertSame(['value' => 'https://engine.example.test:2011', 'source' => 'env'], $response->json('data.url'));
        $this->assertSame(['value' => 'panel.example.test', 'source' => 'setting'], $response->json('data.cert_domain'));
        $this->assertSame(['value' => null, 'source' => 'default'], $response->json('data.cert_email'));
        $this->assertSame(['value' => 'panelalpha', 'source' => 'default'], $response->json('data.default_tunnel'));
        $this->assertArrayHasKey('served_certificate', $response->json('data'));
    }

    public function test_engine_address_sets_and_clears_the_stored_names(): void
    {
        $this->putJson('/api/system/engine-address', [
            'cert_domain' => 'Panel.Example.TEST',
            'cert_email' => 'ops@example.test',
            'default_tunnel' => 'none',
        ])->assertOk();

        $this->assertSame('panel.example.test', Setting::get('cert_domain'));
        $this->assertSame('ops@example.test', Setting::get('cert_email'));
        $this->assertSame(DomainPlan::TUNNEL_NONE, Setting::get(DomainPlan::SETTING_DEFAULT_TUNNEL));

        $response = $this->putJson('/api/system/engine-address', ['cert_domain' => null]);

        $response->assertOk();
        $this->assertNull(Setting::get('cert_domain'));
        $this->assertSame(['value' => null, 'source' => 'default'], $response->json('data.cert_domain'));
    }

    public function test_engine_address_refuses_a_name_that_is_not_a_hostname_and_an_unknown_tunnel(): void
    {
        $this->putJson('/api/system/engine-address', ['cert_domain' => 'localhost'])->assertStatus(422)->assertJsonValidationErrors('cert_domain');
        $this->putJson('/api/system/engine-address', ['cert_domain' => 'a..b'])->assertStatus(422)->assertJsonValidationErrors('cert_domain');
        $this->putJson('/api/system/engine-address', ['cert_email' => 'nobody'])->assertStatus(422)->assertJsonValidationErrors('cert_email');
        $this->putJson('/api/system/engine-address', ['default_tunnel' => 'cloudflare'])->assertStatus(422)->assertJsonValidationErrors('default_tunnel');
        $this->putJson('/api/system/engine-address', [])->assertStatus(422);

        $this->assertNull(Setting::get('cert_domain'));
    }

    public function test_engine_address_never_writes_the_url(): void
    {
        $this->putJson('/api/system/engine-address', ['url' => 'https://other.example.test:2011', 'default_tunnel' => 'none'])->assertOk();

        $this->assertSame('https://engine.example.test:2011', config('app.url'));
        $this->assertStringContainsString('APP_URL=https://engine.example.test:2011', (string) file_get_contents($this->dir . '/.env'));
    }

    public function test_prewarm_reads_the_defaults_and_what_could_be_selected(): void
    {
        $response = $this->getJson('/api/system/prewarm');

        $response->assertOk();
        $this->assertSame([], $response->json('data.images.value'));
        $this->assertSame('default', $response->json('data.images.source'));
        $this->assertSame('default', $response->json('data.budget.source'));
        $this->assertSame($response->json('data.budget.default'), $response->json('data.budget.value'));
        $this->assertIsArray($response->json('data.available'));
    }

    public function test_prewarm_stores_the_selection_and_the_sizes(): void
    {
        $first = $this->getJson('/api/system/prewarm')->json('data.available.0.id');

        $response = $this->putJson('/api/system/prewarm', ['images' => [$first], 'budget' => '4G', 'reserve' => '12G']);

        $response->assertOk();
        $this->assertSame([$first], $response->json('data.images.value'));
        $this->assertSame(['value' => '4G', 'source' => 'setting', 'default' => $response->json('data.budget.default')], $response->json('data.budget'));
        $this->assertSame('12G', Setting::get('deploy_prewarm_reserve'));

        $response = $this->putJson('/api/system/prewarm', ['budget' => null, 'images' => []]);

        $this->assertSame('default', $response->json('data.budget.source'));
        $this->assertSame([], $response->json('data.images.value'));
        $this->assertSame('setting', $response->json('data.images.source'), 'an empty selection is stored: none, whatever .env-core says');
        $this->assertSame('12G', $response->json('data.reserve.value'), 'a field left out stays');
    }

    public function test_prewarm_refuses_an_image_the_catalogue_cannot_warm_and_a_bad_size(): void
    {
        $this->putJson('/api/system/prewarm', ['images' => ['nothing:1']])->assertStatus(422)->assertJsonValidationErrors('images.0');
        $this->putJson('/api/system/prewarm', ['budget' => 'lots'])->assertStatus(422)->assertJsonValidationErrors('budget');
        $this->putJson('/api/system/prewarm', ['reserve' => 'none'])->assertStatus(422)->assertJsonValidationErrors('reserve');
        $this->putJson('/api/system/prewarm', [])->assertStatus(422);

        $this->assertNull(Setting::get('deploy_prewarm_images'));
    }

    public function test_mcp_scope_stores_the_ceiling_and_the_selection(): void
    {
        $response = $this->putJson('/api/system/mcp-scope', ['permission_mode' => 'readonly', 'commands' => ['project_list', 'project_get']]);

        $response->assertOk();
        $this->assertSame(['value' => 'readonly', 'source' => 'setting'], $response->json('data.permission_mode'));
        $this->assertSame(['project_get', 'project_list'], $response->json('data.commands'));
        $this->assertSame(['project_get', 'project_list'], $response->json('data.offered'));
        $this->assertSame('readonly', Setting::get('mcp_permission_mode'));

        $response = $this->putJson('/api/system/mcp-scope', ['commands' => null]);

        $this->assertSame('default', $response->json('data.commands_source'));
        $this->assertSame('readonly', $response->json('data.permission_mode.value'), 'a field left out stays');
    }

    public function test_mcp_scope_refuses_a_command_it_does_not_have_and_an_unknown_ceiling(): void
    {
        $this->putJson('/api/system/mcp-scope', ['commands' => ['no_such_command']])->assertStatus(422)->assertJsonValidationErrors('commands.0');
        $this->putJson('/api/system/mcp-scope', ['permission_mode' => 'root'])->assertStatus(422)->assertJsonValidationErrors('permission_mode');

        $this->assertNull(Setting::get('mcp_tools'));
    }

    public function test_sites_db_reads_the_set_sizes_the_defaults_and_the_running_ones(): void
    {
        mkdir($this->dir . '/compose');
        rename($this->dir . '/.env-compose', $this->dir . '/compose/.env');

        $response = $this->getJson('/api/system/sites-db');

        $response->assertOk();
        $this->assertSame(['value' => '32M', 'source' => 'default', 'default' => '32M', 'running_mb' => 32], $response->json('data.innodb_buffer_pool_size'));
        $this->assertSame(['value' => '16M', 'source' => 'env', 'default' => '8M', 'running_mb' => 16], $response->json('data.key_buffer_size'));
    }

    public function test_mcp_scope_reads_the_ceiling_and_the_commands(): void
    {
        config(['mcp-tools.permission_mode' => 'readonly']);

        $response = $this->getJson('/api/system/mcp-scope');

        $response->assertOk();
        $this->assertSame('readonly', $response->json('data.permission_mode.value'));
        $this->assertSame('default', $response->json('data.permission_mode.source'), 'the line in .env-core is commented out');
        $this->assertGreaterThan(0, $response->json('data.total'));
        $this->assertContains('project_list', $response->json('data.commands'));
        $this->assertArrayHasKey('MCP_PERMISSION_MODE', $response->json('data.env'));
    }

    public function test_the_site_database_sizes_have_no_write_route(): void
    {
        $this->putJson('/api/system/sites-db', ['key_buffer_size' => '16M'])->assertStatus(405);
    }
}
