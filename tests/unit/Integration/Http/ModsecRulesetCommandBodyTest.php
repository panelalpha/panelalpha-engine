<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Models\Setting;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Enabling or disabling a ruleset answers the documented SuccessResponse; `custom`,
 * known without the engine directory and already in that state, touches no host.
 */
class ModsecRulesetCommandBodyTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
    }

    public function test_enabling_an_enabled_ruleset_answers_success(): void
    {
        Setting::setModsecConfig('enabled_rulesets', ['custom']);

        $this->putJson('/api/modsec/rulesets/custom/enable')->assertOk()->assertExactJson(['success' => true]);
    }

    public function test_disabling_a_disabled_ruleset_answers_success(): void
    {
        Setting::setModsecConfig('enabled_rulesets', []);

        $this->putJson('/api/modsec/rulesets/custom/disable')->assertOk()->assertExactJson(['success' => true]);
    }
}
