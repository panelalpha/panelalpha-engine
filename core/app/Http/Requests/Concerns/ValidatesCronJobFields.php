<?php

namespace App\Http\Requests\Concerns;

/**
 * The six fields of a cron job, shared by create and update.
 */
trait ValidatesCronJobFields
{
    /**
     * TrimStrings does this for REST; an MCP tool dispatches to the router
     * without it, so the ends are trimmed here to make both callers equal.
     */
    protected function prepareForValidation(): void
    {
        $trimmed = [];
        foreach (['command', 'minute', 'hour', 'day_of_month', 'month', 'day_of_week'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $trimmed[$field] = trim($value);
            }
        }
        $this->merge($trimmed);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules()
    {
        // A crontab entry is one line: a line break would split it in two.
        $oneLine = 'not_regex:/[\r\n\x00]/';

        return [
            'command' => ['string', 'required', $oneLine],
            'minute' => ['string', 'required', $oneLine],
            'hour' => ['string', 'required', $oneLine],
            'day_of_month' => ['string', 'required', $oneLine],
            'month' => ['string', 'required', $oneLine],
            'day_of_week' => ['string', 'required', $oneLine],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages()
    {
        return [
            'not_regex' => 'The :attribute field must be a single line: no line breaks or NUL bytes.',
        ];
    }
}
