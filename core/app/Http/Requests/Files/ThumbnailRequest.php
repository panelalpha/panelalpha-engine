<?php

namespace App\Http\Requests\Files;

use Illuminate\Foundation\Http\FormRequest;

class ThumbnailRequest extends FormRequest
{
    public const DEFAULT_WIDTH = 176;

    public const MIN_SIZE = 16;

    public const MAX_SIZE = 1024;

    public const FITS = ['cover', 'contain'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'width' => $this->input('width') ?? self::DEFAULT_WIDTH,
            'height' => $this->input('height') ?? $this->input('width') ?? self::DEFAULT_WIDTH,
            'fit' => $this->input('fit') ?? self::FITS[0],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'path' => 'required|string',
            'width' => 'sometimes|integer|min:' . self::MIN_SIZE . '|max:' . self::MAX_SIZE,
            'height' => 'sometimes|integer|min:' . self::MIN_SIZE . '|max:' . self::MAX_SIZE,
            'fit' => 'sometimes|in:' . implode(',', self::FITS),
        ];
    }
}
