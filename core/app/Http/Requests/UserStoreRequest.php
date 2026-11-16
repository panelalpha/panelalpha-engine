<?php

namespace App\Http\Requests;

use App\Exceptions\ProblemException;
use App\Http\Requests\Concerns\BuildsNewProjectInput;
use App\Http\Requests\Concerns\ReportsProblems;
use App\Lib\Project\NewProjectRules;
use Illuminate\Foundation\Http\FormRequest;

class UserStoreRequest extends FormRequest
{
    use BuildsNewProjectInput;
    use ReportsProblems;

    /** What the caller called the project name: problems are reported under it. */
    private string $nameField = 'username';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->refuseUnknownFields();

        // `name` is accepted for `username`; see NewProjectRules::normalised().
        [$changes, $this->nameField] = NewProjectRules::normalised($this->all());
        if ($changes !== []) {
            $this->merge($changes);
        }
    }

    /** The flat body takes no key it does not have, as the typed body already says. */
    private function refuseUnknownFields(): void
    {
        $unknown = array_values(array_diff(array_keys($this->all()), NewProjectRules::flatKeys()));
        if ($unknown === []) {
            return;
        }

        throw ProblemException::of(array_map(
            static fn (string $key): array => [
                'field' => $key,
                'code' => 'unknown_field',
                'message' => "`{$key}` is not a field of this body.",
            ],
            $unknown
        ));
    }

    public function rules(): array
    {
        return NewProjectRules::rules($this->nameField, $this->input('domain'), NewProjectRules::createsContainer($this->all()));
    }

    /** `name` or `username`, whichever the caller sent. */
    public function nameField(): string
    {
        return $this->nameField;
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
        return NewProjectRules::expectations();
    }
}
