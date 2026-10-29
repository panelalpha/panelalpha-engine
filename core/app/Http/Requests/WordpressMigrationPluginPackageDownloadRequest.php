<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Crypt;

/**
 * The signed plugin-package link: its `package` must decrypt to a migration
 * token. Anything else answers 404, as a link this engine never minted would.
 */
class WordpressMigrationPluginPackageDownloadRequest extends FormRequest
{
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
            'token' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }

    public function token(): string
    {
        return (string) $this->validated('token');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['token' => $this->decryptedPackage()]);
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(new JsonResponse(['message' => 'Not found'], 404));
    }

    private function decryptedPackage(): ?string
    {
        try {
            return Crypt::decryptString((string) $this->query('package'));
        } catch (DecryptException) {
            return null;
        }
    }
}
