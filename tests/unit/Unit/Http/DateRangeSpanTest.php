<?php

namespace Tests\Unit\Http;

use App\Http\Requests\BandwidthSeriesRequest;
use App\Http\Requests\VisitorsBreakdownRequest;
use App\Http\Requests\VisitorsRangeRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** A bandwidth or visitors range longer than 10 years is refused (engine#849). */
class DateRangeSpanTest extends TestCase
{
    /** @param array<string, mixed> $input */
    private function fails(array $input, FormRequest $request): bool
    {
        return Validator::make($input, $request->rules())->fails();
    }

    /** @return array<int, array{0: array<string, mixed>, 1: FormRequest}> */
    private function requests(): array
    {
        return [
            [['group_by' => 'day'], new BandwidthSeriesRequest()],
            [[], new VisitorsRangeRequest()],
            [['dimension' => 'pages'], new VisitorsBreakdownRequest()],
        ];
    }

    public function test_a_span_over_ten_years_is_refused_by_every_range_request(): void
    {
        foreach ($this->requests() as [$extra, $request]) {
            $this->assertTrue(
                $this->fails($extra + ['start' => '0001-01-01', 'end' => '9999-12-31'], $request),
                $request::class . ' accepted a 120000-month range'
            );
            $this->assertTrue(
                $this->fails($extra + ['start' => '2015-01-01', 'end' => '2026-01-01'], $request),
                $request::class . ' accepted an 11-year range'
            );
        }
    }

    public function test_ten_years_and_under_still_pass(): void
    {
        foreach ($this->requests() as [$extra, $request]) {
            $this->assertFalse(
                $this->fails($extra + ['start' => '2015-01-01', 'end' => '2025-01-01'], $request),
                $request::class . ' refused a 10-year range'
            );
            $this->assertFalse(
                $this->fails($extra + ['start' => '2026-09-01', 'end' => '2026-09-30'], $request),
                $request::class . ' refused a one-month range'
            );
        }
    }

    public function test_a_malformed_or_reversed_range_is_left_to_the_other_rules(): void
    {
        $request = new BandwidthSeriesRequest();

        // date_format owns a malformed value; this rule must not double-report it.
        $this->assertTrue($this->fails(['group_by' => 'day', 'start' => 'not-a-date', 'end' => '2026-01-01'], $request));
        // after_or_equal owns a reversed range.
        $this->assertTrue($this->fails(['group_by' => 'day', 'start' => '2026-09-30', 'end' => '2026-09-01'], $request));
    }
}
