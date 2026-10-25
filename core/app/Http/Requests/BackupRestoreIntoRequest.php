<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LowercasesInput;
use App\Lib\Project\NewProjectInput;
use App\Rules\DomainName;
use App\Rules\ProjectName;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A backup restored into a project that does not exist yet: the new project's
 * name, and optionally its main domain and its email.
 */
class BackupRestoreIntoRequest extends FormRequest
{
    use LowercasesInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->lowercaseInput('new_username', 'domain');
    }

    public function rules(): array
    {
        return [
            'new_username' => ['required', 'string', new ProjectName('new_username')],
            'domain' => ['sometimes', 'nullable', 'string', 'regex:'.DomainName::REGEX],
            'email' => ['sometimes', 'nullable', 'email'],
        ];
    }

    /** The create the new project goes through, with the name it was sent under. */
    public function newProjectInput(): NewProjectInput
    {
        $params = array_filter(
            $this->validated(),
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );
        $params['username'] = $params['new_username'];
        unset($params['new_username']);
        $params['template'] = 'dind';

        return new NewProjectInput($params, 'new_username');
    }
}
