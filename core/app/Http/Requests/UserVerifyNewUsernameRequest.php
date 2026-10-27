<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReportsProblems;
use App\Lib\Project\NewProjectRules;
use App\Rules\ProjectName;
use Illuminate\Foundation\Http\FormRequest;

class UserVerifyNewUsernameRequest extends FormRequest
{
    use ReportsProblems;

    /** What the caller called the project name: problems are reported under it. */
    private string $nameField = 'username';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        [$changes, $this->nameField] = NewProjectRules::normalisedName($this->all());
        if ($changes !== []) {
            $this->merge($changes);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', ...NewProjectRules::nameRules($this->nameField)],
        ];
    }

    /**
     * Said without a field name: the MCP tool takes this as `name`, and a
     * tool call reads field names only from `errors` and `problems`.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.required' => 'A project name is required.',
            'username.string' => 'A project name must be a string.',
        ];
    }

    public function attributes(): array
    {
        return ['username' => $this->nameField];
    }

    protected function reportedField(string $field): string
    {
        return $field === 'username' ? $this->nameField : $field;
    }

    protected function expectations(): array
    {
        return [
            'username' => ['expected' => ProjectName::EXPECTED, 'examples' => ProjectName::EXAMPLES],
        ];
    }
}
