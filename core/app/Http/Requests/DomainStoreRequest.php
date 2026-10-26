<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LowercasesInput;
use App\Http\Requests\Concerns\ReportsProblems;
use App\Rules\DomainName;
use Illuminate\Foundation\Http\FormRequest;

class DomainStoreRequest extends FormRequest
{
    use LowercasesInput;
    use ReportsProblems;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->lowercaseInput('domain', 'parent_domain', 'aliases');
        $this->storeSubdomainTypeAsSub();
    }

    /**
     * The documented enum value is `subdomain`; `sub` is the stored value the
     * rest of the codebase (DomainController, domains:create) switches on.
     */
    private function storeSubdomainTypeAsSub(): void
    {
        if ($this->input('type') === 'subdomain') {
            $this->merge(['type' => 'sub']);
        }
    }

    public function rules(): array
    {
        return [
            'domain' => 'string|required|regex:'.DomainName::REGEX,
            'parent_domain' => 'string|nullable|regex:'.DomainName::REGEX,
            'type' => 'string|required|in:addon,sub',
            'no_ssl' => 'boolean|nullable',
            'aliases' => 'array|nullable',
            'aliases.*' => 'string|nullable',
        ];
    }

    protected function expectations(): array
    {
        $hostname = ['expected' => 'a hostname, lowercase, without scheme or path', 'examples' => ['shop.example.com']];

        return [
            'domain' => $hostname,
            'parent_domain' => $hostname,
            'type' => ['expected' => 'one of: addon, subdomain', 'examples' => ['addon', 'subdomain']],
        ];
    }
}
