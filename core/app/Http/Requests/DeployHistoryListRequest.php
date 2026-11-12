<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /projects/{username}/deploys: paged only when the client names a page or its size. */
class DeployHistoryListRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 10;

    public const MAX_PER_PAGE = 200;

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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function isPaged(): bool
    {
        return $this->query->has('page') || $this->query->has('per_page');
    }

    public function page(): int
    {
        return (int) $this->query('page', '1');
    }

    public function perPage(): int
    {
        return (int) $this->query('per_page', (string) self::DEFAULT_PER_PAGE);
    }
}
