<?php

namespace App\Http\Requests\Firewall;

use App\System\Firewall\FirewallRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** One rule for POST /firewall/rules; on PUT every field is optional and keeps its value. */
class FirewallRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A port is one port, a range (30000:30009) or a comma list of either. `confirm`
     * is checked by the controller: a deny that would shut the caller out goes in only with it.
     */
    public function rules(): array
    {
        $required = $this->isMethod('PUT') ? 'sometimes' : 'required';

        return [
            'action' => [$required, 'string', 'in:allow,deny'],
            'scope' => ['nullable', 'string', 'in:host,published,both'],
            'direction' => ['nullable', 'string', 'in:in,out,both'],
            'protocol' => ['nullable', 'string', 'in:tcp,udp'],
            'port' => ['nullable', 'string', 'regex:/\A[0-9]{1,5}(?::[0-9]{1,5})?(?:,[0-9]{1,5}(?::[0-9]{1,5})?)*\z/'],
            'source' => ['nullable', 'string', 'max:64', $this->checkAddress(...)],
            'destination' => ['nullable', 'string', 'max:64', $this->checkAddress(...)],
            'confirm' => ['nullable', 'boolean'],
            'comment' => ['nullable', 'string', 'max:255', $this->checkComment(...)],
        ];
    }

    /** On PUT the merged rule is checked by the controller, which has the current one. */
    public function after(): array
    {
        return [$this->checkWholeRule(...)];
    }

    private function checkAddress(string $attribute, mixed $value, \Closure $fail): void
    {
        if (is_string($value) && ! FirewallRule::isAddress($value)) {
            $fail("The {$attribute} must be an IPv4 or IPv6 address or a CIDR range.");
        }
    }

    private function checkComment(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }
        if (preg_match(FirewallRule::COMMENT_REFUSED_CHARACTERS, $value) === 1) {
            $fail('The comment cannot contain \' (an apostrophe), a line break or a NUL character.');
        } elseif (str_starts_with(trim($value), FirewallRule::MANAGED_PREFIX)) {
            $fail('The comment prefix "'.FirewallRule::MANAGED_PREFIX.'" marks the rules the engine opens.');
        }
    }

    private function checkWholeRule(Validator $validator): void
    {
        if ($this->isMethod('PUT') || $validator->errors()->isNotEmpty()) {
            return;
        }
        /** @var array{action: string} $data */
        $data = $validator->getData();
        foreach (FirewallRule::fromArray($data)->problems() as $field => $message) {
            $validator->errors()->add($field, $message);
        }
    }

    /** @return array{action?: string, direction?: ?string, protocol?: ?string, port?: ?string, source?: ?string, destination?: ?string, comment?: ?string, scope?: ?string} */
    public function rule(): array
    {
        /** @var array{action?: string, direction?: ?string, protocol?: ?string, port?: ?string, source?: ?string, destination?: ?string, comment?: ?string, scope?: ?string} */
        return array_diff_key($this->validated(), ['confirm' => true]);
    }
}
