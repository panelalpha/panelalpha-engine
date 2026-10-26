<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesEximConfig;
use App\Lib\Mail\EximConfigSecrets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EximConfigUpdateRequest extends FormRequest
{
    use ValidatesEximConfig;

    /** @var array<string, mixed> */
    private array $sentCredentials = [];

    protected function prepareForValidation(): void
    {
        $this->withoutTrailingDots();
        $this->replace(EximConfigSecrets::withoutEmpty($this->all()));
        $this->sentCredentials = $this->only(self::EXIM_SECRET_FIELDS);
        $this->merge(self::resolveEximSecrets($this->sentCredentials));
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
            ...self::eximConfigRules(),
            'clear_secrets' => 'array|nullable',
            'clear_secrets.*' => ['string', Rule::in(EximConfigSecrets::HIDDEN_FIELDS)],
        ];
    }

    /**
     * The fields to merge into the stored config, with each secret named in `clear_secrets` set to null.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $params = $this->validated();
        foreach ((array) ($params['clear_secrets'] ?? []) as $field) {
            $params[$field] = null;
        }
        unset($params['clear_secrets']);

        return $params;
    }

    /**
     * The credentials as the caller sent them, before a `vault:<key>` was read.
     *
     * @return array<string, mixed>
     */
    public function sentCredentials(): array
    {
        return $this->sentCredentials;
    }
}
