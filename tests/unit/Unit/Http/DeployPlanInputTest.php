<?php

namespace Tests\Unit\Http;

use App\Http\Requests\DeployPlanInput;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\PlatformStage;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The `stages` field on the way in.
 *
 * A malformed plan is a 422 with the parser's own sentence in it — not a 500,
 * and not a silently ignored field. Ignoring it would be the worst of the
 * three: the deploy would run the platform's defaults while the caller
 * believed it had replaced them.
 */
class DeployPlanInputTest extends TestCase
{
    public function test_a_request_without_the_field_arms_nothing(): void
    {
        $plan = DeployPlanInput::arm(Request::create('/api/projects', 'POST', []));

        $this->assertNull($plan);
        $this->assertNull(app(DeployPlanContext::class)->get());
    }

    public function test_a_plan_is_armed_for_the_pipeline_to_find(): void
    {
        $request = Request::create('/api/projects', 'POST', [
            'stages' => ['upgrade' => [['id' => 'migrate', 'run' => 'app migrate']]],
        ]);

        $plan = DeployPlanInput::arm($request);

        $this->assertNotNull($plan);
        $this->assertSame($plan, app(DeployPlanContext::class)->get());
        $this->assertTrue($plan->definesStage(PlatformStage::UPGRADE));
    }

    public function test_a_malformed_plan_is_a_422_naming_the_field(): void
    {
        try {
            DeployPlanInput::parse(['upgrade' => [['id' => 'migrate']]]);
            $this->fail('a command with nothing to run should have been rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey(DeployPlanInput::FIELD, $e->errors());
            $this->assertStringContainsString('upgrade', $e->errors()['stages'][0]);
        }
    }

    public function test_an_unknown_stage_is_a_422_rather_than_a_shrug(): void
    {
        $this->expectException(ValidationException::class);

        DeployPlanInput::parse(['deploy' => []]);
    }
}
