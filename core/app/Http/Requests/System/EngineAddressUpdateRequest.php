<?php

namespace App\Http\Requests\System;

use App\Http\Requests\Concerns\ChangesSettings;
use App\Lib\Domains\DomainPlan;
use App\Lib\Ssl\EngineAddressSettings;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EngineAddressUpdateRequest extends FormRequest
{
    use ChangesSettings;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['cert_domain', 'cert_email'] as $field) {
            if ($this->has($field) && $this->input($field) === null) {
                $this->merge([$field => '']);
            }
        }
    }

    public function rules(): array
    {
        return [
            'url' => ['prohibited'],
            'cert_domain' => ['sometimes', 'present', 'string', 'max:253', $this->check(static fn (string $v): ?string => EngineAddressSettings::badName(strtolower(trim($v))))],
            'cert_email' => ['sometimes', 'present', 'string', 'max:255', $this->check(static fn (string $v): ?string => trim($v) === '' || filter_var(trim($v), FILTER_VALIDATE_EMAIL) ? null : 'That is not an email address.')],
            'default_tunnel' => ['sometimes', 'string', Rule::in(DomainPlan::TUNNELS)],
        ];
    }

    public function messages(): array
    {
        return ['url.prohibited' => 'The engine is bound to its address; it is not a setting the API changes.'];
    }

    /** @return array{cert_domain?: string, cert_email?: string, default_tunnel?: string} */
    public function changes(): array
    {
        return array_map(static fn (mixed $value): string => trim((string) $value), array_intersect_key($this->validated(), array_flip($this->fields())));
    }

    protected function fields(): array
    {
        return ['cert_domain', 'cert_email', 'default_tunnel'];
    }

    private function check(callable $why): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($why): void {
            $reason = $why(trim((string) $value));
            if ($reason !== null) {
                $fail($reason);
            }
        };
    }
}
