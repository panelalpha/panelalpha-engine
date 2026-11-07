<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LowercasesInput;
use App\Lib\Domains\HttpsPortsChange;
use App\Rules\RedirectUrl;
use Illuminate\Foundation\Http\FormRequest;

class DomainUpdateRequest extends FormRequest
{
    use LowercasesInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->lowercaseInput('aliases');
    }

    public function rules(): array
    {
        return [
            'document_root' => self::documentRootRules(),
            'redirect_enabled' => 'sometimes|boolean',
            'redirect_url' => self::redirectUrlRules(),
            'force_https_redirect' => 'sometimes|boolean',
            'aliases' => 'array|nullable',
            'aliases.*' => 'string|nullable',
            'main' => 'sometimes|accepted',
            'https_ports' => 'sometimes|array|list|min:1|max:'.HttpsPortsChange::MAX_PORTS,
            'https_ports.*' => 'integer|between:1,65535|distinct',
            'https_port' => 'sometimes|integer|between:1,65535',
        ];
    }

    /**
     * Concatenated unquoted into the vhost's DocumentRoot under /home/<user>: only a
     * plain path under the account, no URL, no `..` segment, nothing that ends the directive.
     *
     * @return list<string>
     */
    private static function documentRootRules(): array
    {
        return [
            'sometimes',
            'string',
            'max:4096',
            'regex:/^\\/(?:[A-Za-z0-9._~@+,=-]+\\/?)*$/D',
            'not_regex:/(?:^|\\/)\\.\\.(?:\\/|$)/',
        ];
    }

    /**
     * Written verbatim into nginx's `return 301 "<url>"`, where `$` is a variable
     * and a quote or space ends the string.
     *
     * @return list<mixed>
     */
    private static function redirectUrlRules(): array
    {
        return ['url', 'nullable', 'max:2048', new RedirectUrl];
    }
}
