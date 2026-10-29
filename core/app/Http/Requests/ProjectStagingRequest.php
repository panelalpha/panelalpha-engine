<?php

namespace App\Http\Requests;

use App\Exceptions\ProblemException;
use App\Http\Requests\Concerns\LowercasesInput;
use App\Models\User;
use App\Rules\DomainName;
use App\System;
use App\System\Projects;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProjectStagingRequest extends FormRequest
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
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $source = $this->sourceProject();
            if ($source === null) {
                return;
            }

            $problems = [
                ...self::createProblems($source, $this->requestedMysqlPrefix()),
                ...app(System::class)->projects()->copyDatabasesProblems($source, $this->boolean('copy_databases', true)),
            ];
            if ($problems !== []) {
                throw ProblemException::of($problems);
            }
        });
    }

    /** The prefix the requested (never the generated) destination would give its MySQL objects. */
    private function requestedMysqlPrefix(): ?string
    {
        $newUsername = $this->input('new_username');

        return is_string($newUsername) && $newUsername !== '' ? $newUsername.'_' : null;
    }

    private function sourceProject(): ?User
    {
        $username = $this->route('username');

        return is_string($username) && $username !== '' ? User::findByUsername($username) : null;
    }

    public static function assertCanCreate(User $source, ?string $destPrefix = null): void
    {
        Projects::assertCanCreate($source, $destPrefix);
    }

    /**
     * @return list<array{field: string, code: string, message: string}>
     */
    public static function createProblems(User $source, ?string $destPrefix = null): array
    {
        return Projects::createProblems($source, $destPrefix);
    }
}
