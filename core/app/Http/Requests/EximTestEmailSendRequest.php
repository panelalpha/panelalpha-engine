<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesEximConfig;
use App\Lib\Mail\EximConfigSecrets;
use Illuminate\Foundation\Http\FormRequest;

class EximTestEmailSendRequest extends FormRequest
{
    use ValidatesEximConfig;

    /**
     * The address goes into the message's To: header, where a line break adds headers. It is checked
     * as Exim::sendTestEmail() checks it, so a refusal is a 422, not that method's exception.
     */
    private const TEST_EMAIL_RULE = 'required|string|email:rfc,filter|max:254';

    protected function prepareForValidation(): void
    {
        $this->withoutTrailingDots('config');
        if (is_array($this->input('config'))) {
            $this->merge(['config' => self::resolveEximSecrets(EximConfigSecrets::withoutEmpty($this->input('config')))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => self::TEST_EMAIL_RULE,
            ...self::eximConfigRules('config.'),
        ];
    }
}
