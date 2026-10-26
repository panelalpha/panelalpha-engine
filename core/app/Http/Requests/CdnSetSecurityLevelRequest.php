<?php

namespace App\Http\Requests;

use App\Lib\Apis\Cloudflare;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CdnSetSecurityLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'level' => ['required', 'string', Rule::in(Cloudflare::SECURITY_LEVELS)],
        ];
    }
}
