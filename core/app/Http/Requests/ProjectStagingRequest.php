<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LowercasesInput;
use App\Models\User;
use App\Rules\DomainName;
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
            'domain' => 'sometimes|nullable|string|regex:' . DomainName::REGEX,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $source = $this->sourceProject();
            if ($source === null) {
                return;
            }

            foreach (self::createProblems($source) as $problem) {
                $validator->errors()->add($problem['field'], $problem['message']);
            }
        });
    }

    private function sourceProject(): ?User
    {
        $username = $this->route('username');

        return is_string($username) && $username !== '' ? User::findByUsername($username) : null;
    }

    public static function assertCanCreate(User $source): void
    {
        Projects::assertCanCreate($source);
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    public static function createProblems(User $source): array
    {
        return Projects::createProblems($source);
    }
}
