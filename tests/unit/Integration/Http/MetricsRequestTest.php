<?php

namespace Tests\Integration\Http;

use App\Http\Requests\MetricsRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** The metrics query grammar: known metric names joined by commas, and a date range that runs forward. */
class MetricsRequestTest extends TestCase
{
    public function test_known_metric_names_and_a_forward_range_pass(): void
    {
        foreach ([[], ['type' => 'ram_usage'], ['type' => 'load_average_minute,net_in,uptime'], ['start_date' => '2026-01-01', 'end_date' => '2026-01-02']] as $input) {
            $this->assertTrue($this->validator($input)->passes(), json_encode($input));
        }
    }

    public function test_an_unknown_metric_or_a_loose_list_is_refused_with_its_message(): void
    {
        foreach (['cpu', 'ram_usage,', 'ram_usage, net_in', 'RAM_USAGE'] as $type) {
            $errors = $this->validator(['type' => $type])->errors();
            $this->assertSame(['The type field must contain valid metric types separated by commas.'], $errors->get('type'), $type);
        }
    }

    public function test_a_backward_or_unreadable_range_is_refused_with_its_messages(): void
    {
        $errors = $this->validator(['start_date' => '2026-02-01', 'end_date' => '2026-01-01'])->errors();
        $this->assertSame(['The start date must be before or equal to the end date.'], $errors->get('start_date'));
        $this->assertSame(['The end date must be after or equal to the start date.'], $errors->get('end_date'));

        $this->assertContains('The start date must be a valid date.', $this->validator(['start_date' => 'yesterday-ish'])->errors()->get('start_date'));
        $this->assertContains('The end date must be a valid date.', $this->validator(['end_date' => 'soon'])->errors()->get('end_date'));
    }

    /** @param array<string, string> $input */
    private function validator(array $input): \Illuminate\Validation\Validator
    {
        $request = new MetricsRequest();
        $this->assertTrue($request->authorize());

        return Validator::make($input, $request->rules(), $request->messages());
    }
}
