<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReportsProblems;
use App\Lib\Domains\DomainPlan;
use App\Lib\Project\NewProjectRules;
use App\Rules\ProjectName;
use Illuminate\Foundation\Http\FormRequest;

class UserStoreRequest extends FormRequest
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
        // `name` is accepted for `username`; see NewProjectRules::normalised().
        [$changes, $this->nameField] = NewProjectRules::normalised($this->all());
        if ($changes !== []) {
            $this->merge($changes);
        }
    }

    public function rules(): array
    {
        return NewProjectRules::rules($this->nameField, $this->input('domain'));
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
        $count = static fn (string $what, string $example): array => [
            'expected' => $what,
            'examples' => [$example],
        ];

        return [
            'username' => ['expected' => ProjectName::EXPECTED, 'examples' => ProjectName::EXAMPLES],
            'domain' => ['expected' => 'a hostname, lowercase, without scheme or path', 'examples' => ['shop.example.com']],
            'domain_redirect_url' => ['expected' => 'an absolute URL', 'examples' => ['https://example.com/']],
            'email' => ['expected' => 'an email address', 'examples' => ['ops@example.com']],
            'disk_space_limit' => $count('an integer number of MB, -1 for unlimited', '10240'),
            'memory_limit' => $count('an integer number of MB, 0 or more; omit or null for no limit', '512'),
            'cpu_limit' => $count('a number of CPU cores, 0 or more, fractions allowed; omit or null for no limit', '1.5'),
            'bandwidth_limit' => $count('an integer, 0 or more; omit or null for no limit', '100000'),
            'inodes_limit' => $count('an integer; omit or null for no limit', '500000'),
            'tunnel' => ['expected' => 'one of: ' . implode(', ', DomainPlan::TUNNELS), 'examples' => DomainPlan::TUNNELS],
            'git_branch' => ['expected' => 'the name of a branch or tag in the repository', 'examples' => ['main', 'v1.2.0']],
            'env_vars' => ['expected' => 'an object of KEY: "value" strings, at most 200', 'examples' => ['{"APP_ENV": "production"}']],
            'password' => ['expected' => 'a string of 1-255 characters'],
            'recipe' => ['expected' => 'a recipe name, at most 64 characters'],
            'template' => ['expected' => 'a template name; dind for a git_repo', 'examples' => ['dind']],
        ];
    }
}
