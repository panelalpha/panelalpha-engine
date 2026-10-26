<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesVaultFields;
use Illuminate\Foundation\Http\FormRequest;

class DomainInstallSslCertRequest extends FormRequest
{
    use ResolvesVaultFields;

    protected function prepareForValidation(): void
    {
        $this->resolveVaultFields(['key']);
    }

    /**
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function rules()
    {
        return [
            'cert' => 'string|required',
            'key' => 'string|required',
            'ca' => 'string|nullable',
        ];
    }
}
