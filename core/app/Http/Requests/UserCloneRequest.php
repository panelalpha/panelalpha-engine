<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LowercasesInput;
use App\Rules\DomainName;
use Illuminate\Foundation\Http\FormRequest;

class UserCloneRequest extends FormRequest
{
    use LowercasesInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->lowercaseInput('domain', 'new_username');
    }

    public function rules(): array
    {
        return [
            'new_username' => 'sometimes|nullable|string|alpha_num:ascii|regex:/^[a-z]{1}/|lowercase|between:3,15',
            'domain' => 'sometimes|nullable|string|regex:'.DomainName::REGEX,
            'copy_databases' => 'sometimes|boolean',
        ];
    }
}
