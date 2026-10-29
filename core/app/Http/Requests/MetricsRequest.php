<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MetricsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => [
                'nullable',
                'string',
                'regex:/^(load_average_minute|load_average_five|load_average_fifteen|ram_usage|swap_usage|disk_in|disk_out|net_in|net_out|uptime)(,(load_average_minute|load_average_five|load_average_fifteen|ram_usage|swap_usage|disk_in|disk_out|net_in|net_out|uptime))*$/',
            ],
            'start_date' => 'nullable|date|before_or_equal:end_date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ];
    }

    public function messages(): array
    {
        return [
            'type.regex' => 'The type field must contain valid metric types separated by commas.',
            'start_date.date' => 'The start date must be a valid date.',
            'end_date.date' => 'The end date must be a valid date.',
            'start_date.before_or_equal' => 'The start date must be before or equal to the end date.',
            'end_date.after_or_equal' => 'The end date must be after or equal to the start date.',
        ];
    }
}
