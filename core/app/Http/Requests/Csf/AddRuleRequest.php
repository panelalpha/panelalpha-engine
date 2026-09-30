<?php

namespace App\Http\Requests\Csf;

use App\System\Services\Csf;
use Illuminate\Foundation\Http\FormRequest;

class AddRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'protocol' => 'nullable|string|in:tcp,udp',
            'direction' => 'nullable|string|in:in,out',
            'port_prefix' => 'nullable|string|in:s=,d=',
            // One csf.allow/csf.deny line is built from these; a newline in
            // any of them would be a second, unvalidated rule.
            'port' => ['nullable', 'string', 'regex:/\A[0-9]+(?:[_:][0-9]+)?(?:,[0-9]+(?:[_:][0-9]+)?)*\z/'],
            'target_prefix' => 'nullable|string|in:s=,d=,u=',
            // An IP or a CIDR, or a uid for `u=`; csf ignores a hostname here.
            'target' => ['required', 'string', 'max:64', function (string $attribute, mixed $value, \Closure $fail): void {
                if (!is_string($value) || !(Csf::isAddress($value) || Csf::isUid($value))) {
                    $fail('The target must be an IPv4 or IPv6 address, a CIDR range, or a numeric uid.');
                }
            }],
            'comment' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
        ];
    }
}
