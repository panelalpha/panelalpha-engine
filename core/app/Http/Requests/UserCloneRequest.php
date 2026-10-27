<?php

namespace App\Http\Requests;

use App\Exceptions\ProblemException;
use App\Http\Requests\Concerns\LowercasesInput;
use App\Models\User;
use App\Rules\DomainName;
use App\System;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $source = $validator->errors()->isEmpty() ? $this->sourceProject() : null;
            if ($source === null) {
                return;
            }

            $problems = app(System::class)->projects()->copyDatabasesProblems($source, $this->boolean('copy_databases', true));
            if ($problems !== []) {
                throw ProblemException::of($problems);
            }
        });
    }

    private function sourceProject(): ?User
    {
        $username = $this->route('username');

        return is_string($username) && $username !== '' ? User::findByUsername($username) : null;
    }
}
