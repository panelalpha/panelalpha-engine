<?php

namespace App\Http\Requests;

use App\Lib\Host\MetricBuckets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The `range` of a metrics history: one of {@see MetricBuckets::RANGES}. */
class MetricsRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'range' => ['sometimes', 'string', Rule::in(array_keys(MetricBuckets::RANGES))],
        ];
    }

    public function range(): string
    {
        return $this->validated('range') ?? MetricBuckets::DEFAULT_RANGE;
    }
}
