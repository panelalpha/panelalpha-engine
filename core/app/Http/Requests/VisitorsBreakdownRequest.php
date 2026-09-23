<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VisitorsBreakdownRequest extends FormRequest
{
    public const DIMENSIONS = [
        'pages',
        'countries',
        'continents',
        'regions',
        'referrers',
        'os',
        'browsers',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'dimension' => $this->route('dimension'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'start' => 'required|date_format:Y-m-d',
            'end' => 'required|date_format:Y-m-d|after_or_equal:start',
            'dimension' => 'required|in:' . implode(',', self::DIMENSIONS),
        ];
    }
}
