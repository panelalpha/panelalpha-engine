<?php

namespace App\Http\Requests;

use App\Rules\DateRangeSpan;
use Illuminate\Foundation\Http\FormRequest;

class BandwidthSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'start' => ['required', 'date_format:Y-m-d', new DateRangeSpan('end')],
            'end' => 'required|date_format:Y-m-d|after_or_equal:start',
            'group_by' => 'required|in:day,month',
        ];
    }
}
