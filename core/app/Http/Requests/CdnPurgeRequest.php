<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CdnPurgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'everything' => ['sometimes', 'boolean'],
            'files' => ['sometimes', 'array', 'max:30'],
            'files.*' => ['string', 'url', 'max:2048'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('everything') || self::files($this->input('files')) !== []) {
                return;
            }

            $validator->errors()->add('files', 'Send everything=true, or a non-empty list of files.');
        });
    }

    /**
     * @return list<string>
     */
    private static function files(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $file): bool => is_string($file) && $file !== ''));
    }
}
