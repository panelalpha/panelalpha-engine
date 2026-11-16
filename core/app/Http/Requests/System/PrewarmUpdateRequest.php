<?php

namespace App\Http\Requests\System;

use App\Http\Requests\Concerns\ChangesSettings;
use App\Lib\Deploy\CacheManager\PrewarmSettings;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrewarmUpdateRequest extends FormRequest
{
    use ChangesSettings;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'images' => ['sometimes', 'nullable', 'array', 'max:200'],
            'images.*' => ['string', 'distinct', Rule::in(array_column(app(PrewarmSettings::class)->catalog(), 'id'))],
            'budget' => ['sometimes', 'nullable', 'string', 'max:32', $this->size('budget')],
            'reserve' => ['sometimes', 'nullable', 'string', 'max:32', $this->size('reserve')],
        ];
    }

    public function messages(): array
    {
        return ['images.*.in' => 'The image :input is not one config/core/images.yaml can prewarm; the prewarm settings list the ones it can.'];
    }

    /** @return array{images?: ?list<string>, budget?: ?string, reserve?: ?string} */
    public function changes(): array
    {
        return array_intersect_key($this->validated(), array_flip($this->fields()));
    }

    protected function fields(): array
    {
        return ['images', 'budget', 'reserve'];
    }

    private function size(string $which): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($which): void {
            $why = PrewarmSettings::badSize($which, (string) $value);
            if ($why !== null) {
                $fail($why);
            }
        };
    }
}
