<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Models\Setting;
use App\System\Services\Webserver;
use Tests\Support\InMemoryDatabase;
use Tests\Support\ScriptedBroker;
use Tests\TestCase;

/** The /modsec endpoints: the mode and rulesets are settings the webserver config is rebuilt from, custom rules a file checked first. */
class ModsecRulesHttpTest extends TestCase
{
    use InMemoryDatabase;

    private const CUSTOM_RULES = '/opt/panelalpha/shared-hosting/config/modsecurity/rulesets/custom/rules/custom.conf';

    private ScriptedBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::clearRuntimeSettings();
        $this->withoutMiddleware(Authenticate::class);
        $this->broker = new ScriptedBroker();
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        $this->broker->answer('compose.serviceContainers', json_encode(['Labels' => 'com.panelalpha.webserver=nginx-proxy']));
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        Setting::clearRuntimeSettings();
        $this->broker->uninstall();
        parent::tearDown();
    }

    public function test_the_mode_is_saved_and_the_config_rebuilt(): void
    {
        $this->getJson('/api/modsec/mode')->assertOk()->assertJsonPath('data.mode', 'off');

        $this->putJson('/api/modsec/mode', ['mode' => 'detection_only'])
            ->assertOk()
            ->assertJsonPath('data.mode', 'detection_only');

        $this->assertSame('detection_only', Setting::getModsecConfig()['mode']);
        $this->assertStringContainsString('modsecurity/main.conf', implode(' ', array_merge(...$this->broker->valuesOf('files.copy'))));
        $this->assertContains('compose.restart', $this->broker->actions());
    }

    public function test_an_unknown_mode_is_refused(): void
    {
        $this->putJson('/api/modsec/mode', ['mode' => 'block'])->assertUnprocessable()->assertJsonValidationErrors('mode');

        $this->assertSame([], $this->broker->requests());
    }

    public function test_the_custom_ruleset_is_listed_and_switched(): void
    {
        $this->getJson('/api/modsec/rulesets')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'custom')
            ->assertJsonPath('data.0.enabled', false);

        $this->putJson('/api/modsec/rulesets/custom/enable')->assertOk()->assertExactJson(['success' => true]);
        $this->assertSame(['custom'], Setting::getModsecConfig()['enabled_rulesets']);

        $this->putJson('/api/modsec/rulesets/custom/disable')->assertOk()->assertExactJson(['success' => true]);
        $this->assertSame([], Setting::getModsecConfig()['enabled_rulesets']);
    }

    public function test_config_files_of_a_ruleset_without_a_rules_directory_change_nothing(): void
    {
        $this->putJson('/api/modsec/rulesets/custom/config-files', ['enable' => ['REQUEST-900.conf'], 'disable' => ['RESPONSE-950.conf']])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'custom');
    }

    public function test_a_config_file_name_with_a_folder_is_refused(): void
    {
        $this->putJson('/api/modsec/rulesets/custom/config-files', ['enable' => ['../evil.conf']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enable.0');
    }

    public function test_an_unknown_ruleset_is_not_found(): void
    {
        foreach (['enable', 'disable', 'config-files'] as $action) {
            $this->putJson("/api/modsec/rulesets/owasp-crs-missing/{$action}")
                ->assertNotFound()
                ->assertExactJson(['message' => 'Ruleset not found']);
        }
    }

    public function test_the_custom_rules_are_read_back_with_their_id_range(): void
    {
        $rule = 'SecRule REQUEST_URI "@beginsWith /xyz" "id:1100001,phase:1,deny,status:403,log"';
        $this->broker->hostFile(self::CUSTOM_RULES, $rule . "\n");

        $this->getJson('/api/modsec/custom-rules')
            ->assertOk()
            ->assertJsonPath('data.rules', $rule . "\n")
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.id_range', [1100000, 1199999]);
    }

    public function test_custom_rules_the_check_cannot_run_are_refused(): void
    {
        $this->putJson('/api/modsec/custom-rules', ['rules' => 'SecRule REQUEST_URI "@beginsWith /xyz" "id:1100001,phase:1,deny,status:403,log"'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.rules.0', 'The webserver refused the rules: the check of what the rules do could not run, so they are not applied');

        $this->assertContains('compose.modsecurityNginxCheck', $this->broker->actions());
    }

    public function test_custom_rules_the_check_passes_are_saved(): void
    {
        $rule = 'SecRule REQUEST_URI "@beginsWith /xyz" "id:1100001,phase:1,deny,status:403,log"';
        $this->checkAnswers(['first' => '418', 'phase1' => '418', 'phase2' => '418', 'phase3' => '418', 'body' => '418', 'plain' => '204']);

        $this->putJson('/api/modsec/custom-rules', ['rules' => $rule])
            ->assertOk()
            ->assertJsonPath('data.id_range', [1100000, 1199999]);

        $this->assertStringContainsString('custom.conf', implode(' ', array_merge(...$this->broker->valuesOf('files.rename'))));
    }

    public function test_custom_rules_that_let_a_later_rule_be_skipped_are_refused(): void
    {
        $this->checkAnswers(['first' => '418', 'phase1' => '204', 'phase2' => '418', 'phase3' => '418', 'body' => '418', 'plain' => '204']);

        $this->putJson('/api/modsec/custom-rules', ['rules' => 'SecRule REQUEST_URI "@unconditionalMatch" "id:1100002,phase:1,allow"'])
            ->assertUnprocessable()
            ->assertJsonPath('problems.0.code', 'rules_invalid');
    }

    public function test_an_audit_log_that_is_not_there_is_not_found(): void
    {
        $this->getJson('/api/modsec/audit-log/files')->assertOk()->assertExactJson(['data' => []]);
        $this->getJson('/api/modsec/audit-log/files/audit.log')->assertNotFound()->assertExactJson(['message' => 'File not found']);
        $this->getJson('/api/modsec/audit-log/files/audit.log/tail')->assertNotFound()->assertExactJson(['message' => 'File not found']);
    }

    /** @param array<string, string> $answers */
    private function checkAnswers(array $answers): void
    {
        $lines = array_map(static fn (string $request, string $status): string => "modsecurity-check {$request} {$status}", array_keys($answers), $answers);
        $this->broker->answer('compose.modsecurityNginxCheck', implode("\n", $lines) . "\n");
    }
}
