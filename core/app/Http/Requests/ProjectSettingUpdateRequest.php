<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesVaultFields;
use App\Lib\Vault\ProjectSettingVaultTypes;
use Illuminate\Foundation\Http\FormRequest;

class ProjectSettingUpdateRequest extends FormRequest
{
    use ResolvesVaultFields;

    protected function prepareForValidation(): void
    {
        $type = ProjectSettingVaultTypes::typeFor((string) $this->route('key'));
        if ($type !== null && is_string($this->input('value'))) {
            $this->resolveVaultFields(['value'], ['value' => [$type]]);
        }
    }

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
            'value' => ['required', 'string'],
        ];
    }
}
