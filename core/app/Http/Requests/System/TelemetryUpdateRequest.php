<?php

namespace App\Http\Requests\System;

use App\Http\Requests\Concerns\ChangesSettings;
use App\Lib\Deploy\Telemetry\SourceBundlePolicy;
use App\Lib\Deploy\Telemetry\TelemetrySettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TelemetryUpdateRequest extends FormRequest
{
    use ChangesSettings;

    private const FLAGS = ['sending', 'bug_reports'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sending' => ['sometimes', 'boolean'],
            'tier' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(TelemetrySettings::TIERS))],
            'source_bundle' => ['sometimes', 'nullable', 'string', Rule::in(SourceBundlePolicy::MODES)],
            'bug_reports' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /** @return array{sending?: bool, tier?: ?string, source_bundle?: ?string, bug_reports?: ?bool} */
    public function changes(): array
    {
        $changes = array_intersect_key($this->validated(), array_flip($this->fields()));
        foreach (array_intersect(self::FLAGS, array_keys($changes)) as $flag) {
            $changes[$flag] = $changes[$flag] === null ? null : filter_var($changes[$flag], FILTER_VALIDATE_BOOLEAN);
        }

        return $changes;
    }

    protected function fields(): array
    {
        return ['sending', 'tier', 'source_bundle', 'bug_reports'];
    }
}
