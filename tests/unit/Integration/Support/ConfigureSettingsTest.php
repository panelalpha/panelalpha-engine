<?php

namespace Tests\Integration\Support;

use App\Auth\OperatorTokens;
use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\PrewarmSettings;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Lib\Deploy\Telemetry\TelemetrySettings;
use App\Lib\Ssl\EngineAddressSettings;
use App\Mcp\McpScopeSettings;
use App\Mcp\ToolExposure;
use App\Mcp\ToolPolicy;
use App\Mcp\Tools\Api\Projects\ProjectGetTool;
use App\Mcp\Tools\Api\Projects\ProjectListTool;
use App\Models\Setting;
use App\Support\EngineEnvFile;
use App\Support\EngineSetting;
use App\Support\EnvFile;
use App\Support\SitesDbCaches;
use App\System;
use Illuminate\Support\Carbon;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * The services `pae configure` and the API share: what each writes, what puts a value back to its default, and
 * where each says a value came from.
 */
class ConfigureSettingsTest extends TestCase
{
    use InMemoryDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->dir = sys_get_temp_dir() . '/configure-settings-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/.env', "APP_KEY=base64:x\n# APP_URL=\nTELEMETRY_TIER=2\n# MCP_TOOLSETS=\n");
        config([
            'telemetry.tier' => 2,
            'telemetry.source_bundle.mode' => 'off',
            'telemetry.bug_reports.enabled' => true,
            'deploy.prewarm_images' => '',
            'deploy.prewarm_budget' => '',
            'deploy.prewarm_reserve' => '',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Setting::flushCache();
        array_map('unlink', array_filter(glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function env(): EngineEnvFile
    {
        return new EngineEnvFile(new EnvFile($this->dir . '/.env'), EngineEnvFile::CORE);
    }

    private function envFile(): EnvFile
    {
        return new EnvFile($this->dir . '/.env');
    }

    private function written(): string
    {
        return (string) file_get_contents($this->dir . '/.env');
    }

    public function test_a_stored_setting_wins_over_env_and_null_hands_it_back(): void
    {
        $setting = new EngineSetting('TELEMETRY_TIER', 'telemetry.tier');

        $this->assertSame(2, $setting->value());
        $this->assertSame(EngineSetting::ENV, $setting->source($this->envFile()));

        $this->assertTrue($setting->set('0'));
        $this->assertFalse($setting->set('0'), 'the same value again changes nothing');
        $this->assertSame('0', $setting->value());
        $this->assertSame(EngineSetting::SETTING, $setting->source($this->envFile()));

        $this->assertTrue($setting->set(null));
        $this->assertSame(2, $setting->value());
        $this->assertNull(Setting::get('telemetry_tier'));
    }

    public function test_a_key_neither_stored_nor_in_the_env_file_is_the_default(): void
    {
        $this->assertSame(EngineSetting::DEFAULT, (new EngineSetting('TELEMETRY_SOURCE_BUNDLE', 'telemetry.source_bundle.mode'))->source($this->envFile()));
        $this->assertSame(EngineSetting::DEFAULT, (new EngineSetting('MCP_TOOLSETS', 'mcp-tools.toolsets'))->source($this->envFile()), 'a commented line sets nothing');
    }

    public function test_telemetry_stores_each_value_and_the_engine_reads_it(): void
    {
        $settings = new TelemetrySettings($this->envFile());

        $settings->set(['tier' => 'metadata', 'source_bundle' => 'unexplained', 'bug_reports' => false]);

        $this->assertSame('0', Setting::get('telemetry_tier'));
        $this->assertSame('unexplained', Setting::get('telemetry_source_bundle'));
        $this->assertSame('false', Setting::get('telemetry_bug_reports'));
        $this->assertSame(0, Telemetry::tier());
        $this->assertSame('unexplained', Telemetry::sourceBundleMode());
        $this->assertFalse($settings->bugReports());
        $this->assertStringContainsString("TELEMETRY_TIER=2\n", $this->written(), '.env-core is left as it was');
    }

    public function test_telemetry_null_hands_a_value_back_to_env_core(): void
    {
        $settings = new TelemetrySettings($this->envFile());
        $settings->set(['tier' => 'repo', 'bug_reports' => false]);

        $settings->set(['tier' => null, 'bug_reports' => null]);

        $this->assertSame(['value' => 'log', 'source' => 'env'], $settings->toArray()['tier']);
        $this->assertSame(['value' => true, 'source' => 'default'], $settings->toArray()['bug_reports']);
    }

    public function test_telemetry_names_the_tier_and_where_each_value_came_from(): void
    {
        (new TelemetrySettings($this->envFile()))->setSourceBundle('failed');

        $data = (new TelemetrySettings($this->envFile()))->toArray();

        $this->assertSame(['value' => 'log', 'source' => 'env'], $data['tier']);
        $this->assertSame(['value' => 'failed', 'source' => 'setting'], $data['source_bundle']);
        $this->assertSame(['value' => true, 'source' => 'default'], $data['bug_reports']);
    }

    public function test_prewarm_sets_the_given_fields_and_keeps_the_rest(): void
    {
        $settings = new PrewarmSettings($this->envFile());

        $settings->set(['images' => ['php:8.3', 'composer'], 'budget' => '6G']);
        $settings->set(['reserve' => '12G']);

        $this->assertSame(['php:8.3', 'composer'], $settings->current()['images']);
        $this->assertSame(['php:8.3', 'composer'], HostPrewarmPlan::selected());
        $this->assertSame(6 * 1073741824, HostPrewarmPlan::budgetBytes());
        $this->assertSame('12G', Setting::get('deploy_prewarm_reserve'));
        $this->assertSame('setting', $settings->toArray()['budget']['source']);
    }

    public function test_prewarm_null_hands_a_value_back_and_an_empty_list_stores_none(): void
    {
        config(['deploy.prewarm_images' => 'composer']);
        $settings = new PrewarmSettings($this->envFile());

        $settings->set(['images' => [], 'budget' => '6G']);
        $this->assertSame([], HostPrewarmPlan::selected(), 'an empty selection wins over .env-core');

        $settings->set(['images' => null, 'budget' => null]);
        $this->assertSame(['composer'], HostPrewarmPlan::selected());
        $this->assertSame('', $settings->current()['budget']);
        $this->assertSame($settings->shipped('budget'), $settings->toArray()['budget']['value']);
        $this->assertSame('default', $settings->toArray()['budget']['source']);
    }

    public function test_the_wizard_save_stores_every_field(): void
    {
        $changed = (new PrewarmSettings($this->envFile()))->save(['images' => ['composer'], 'budget' => '', 'reserve' => '9G']);

        $this->assertSame(['DEPLOY_PREWARM_IMAGES', 'DEPLOY_PREWARM_BUDGET', 'DEPLOY_PREWARM_RESERVE'], $changed);
        $this->assertSame('', Setting::get('deploy_prewarm_budget'));
    }

    public function test_prewarm_sizes(): void
    {
        $this->assertNull(PrewarmSettings::badSize('budget', 'none'));
        $this->assertNull(PrewarmSettings::badSize('budget', '6G'));
        $this->assertNull(PrewarmSettings::badSize('reserve', ''));
        $this->assertNotNull(PrewarmSettings::badSize('reserve', 'none'));
        $this->assertNotNull(PrewarmSettings::badSize('budget', 'lots'));
    }

    public function test_engine_address_writes_the_url_over_its_placeholder_and_adopts_it(): void
    {
        $settings = new EngineAddressSettings($this->env());

        $settings->setUrl('https://panel.example.test:2011');

        $this->assertStringContainsString("APP_URL=https://panel.example.test:2011\n", $this->written());
        $this->assertStringNotContainsString('# APP_URL=', $this->written());
        $this->assertSame('https://panel.example.test:2011', $settings->url());
    }

    public function test_engine_address_checks(): void
    {
        $this->assertNull(EngineAddressSettings::badName(''));
        $this->assertNull(EngineAddressSettings::badName('panel.example.com'));
        $this->assertNotNull(EngineAddressSettings::badName('localhost'));
        $this->assertNotNull(EngineAddressSettings::badName('-bad.example.com'));
        $this->assertNull(EngineAddressSettings::badUrl('https://panel.example.com:2011'));
        $this->assertNotNull(EngineAddressSettings::badUrl('panel.example.com'));
        $this->assertNotNull(EngineAddressSettings::unreachableUrl('https://'.TestNetwork::LOOPBACK.':2011'));
        $this->assertNull(EngineAddressSettings::unreachableUrl('https://panel.example.com:2011'));
    }

    public function test_mcp_scope_stores_the_selection_and_the_engine_serves_it(): void
    {
        $settings = new McpScopeSettings($this->envFile());

        $changed = $settings->set(['permission_mode' => 'readonly', 'commands' => ['project_list']]);

        $this->assertContains('MCP_PERMISSION_MODE', $changed);
        $this->assertSame('readonly', Setting::get('mcp_permission_mode'));
        $this->assertSame('project_list', Setting::get('mcp_tools'));
        $this->assertSame(ToolExposure::NO_TOOLSETS, Setting::get('mcp_toolsets'));
        $this->assertSame([ProjectListTool::class], (new ToolPolicy())->filter([ProjectListTool::class, ProjectGetTool::class]));
        $this->assertSame(['project_list'], $settings->current()->selectedNames());
        $this->assertSame(['value' => 'readonly', 'source' => 'setting'], $settings->toArray()['permission_mode']);
        $this->assertSame('setting', $settings->toArray()['commands_source']);
    }

    public function test_mcp_scope_null_hands_the_selection_back_to_env_core(): void
    {
        $settings = new McpScopeSettings($this->envFile());
        $settings->set(['permission_mode' => 'readonly', 'commands' => ['project_list']]);

        $settings->set(['permission_mode' => null, 'commands' => null]);

        $this->assertNull(Setting::get('mcp_tools'));
        $this->assertNull(Setting::get('mcp_permission_mode'));
        $this->assertSame('default', $settings->toArray()['commands_source']);
    }

    public function test_mcp_scope_keeps_what_a_request_leaves_out(): void
    {
        config(['mcp-tools.permission_mode' => 'modify', 'mcp-tools.denied_regex' => '^ssh_']);

        $next = (new McpScopeSettings($this->envFile()))->with(['commands' => ['project_list']]);

        $this->assertSame('modify', $next->get('permission_mode'));
        $this->assertSame('^ssh_', $next->get('denied_regex'));
        $this->assertSame(ToolExposure::NO_TOOLSETS, $next->get('toolsets'));
    }

    public function test_sites_db_null_writes_an_empty_size_compose_reads_as_its_default(): void
    {
        file_put_contents($this->dir . '/.env', "SITES_DB_KEY_BUFFER_SIZE=16M\n");
        $caches = new SitesDbCaches($this->system());

        $result = $caches->set(['SITES_DB_KEY_BUFFER_SIZE' => null]);

        $this->assertSame(['SITES_DB_KEY_BUFFER_SIZE'], $result['changed']);
        $this->assertFalse($result['recreated'], 'sites-db is not running here');
        $this->assertSame("SITES_DB_KEY_BUFFER_SIZE=\n", $this->written());
        $this->assertSame('8M', $caches->configured()['SITES_DB_KEY_BUFFER_SIZE']);
        $this->assertSame('default', $caches->toArray()['key_buffer_size']['source']);
        $this->assertNull($caches->toArray()['key_buffer_size']['running_mb']);
    }

    public function test_token_lifetimes(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');

        $this->assertNull(OperatorTokens::badLifetime(''));
        $this->assertNull(OperatorTokens::badLifetime('90d'));
        $this->assertNotNull(OperatorTokens::badLifetime('90'));
        $this->assertNotNull(OperatorTokens::badLifetime('3 days'));
        $this->assertSame('2026-11-30 12:00:00', OperatorTokens::expiry('2w')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-16 12:30:00', OperatorTokens::expiry('30m')->format('Y-m-d H:i:s'));
    }

    private function system(): System
    {
        return new class ($this->dir) extends System {
            public function __construct(private string $dir)
            {
            }

            public function engineDirPath(): string
            {
                return $this->dir;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                throw new \RuntimeException('service "sites-db" is not running');
            }
        };
    }
}
