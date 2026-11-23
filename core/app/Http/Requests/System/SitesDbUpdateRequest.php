<?php

namespace App\Http\Requests\System;

use App\Http\Requests\Concerns\ChangesSettings;
use App\Lib\Host\HostMemoryProbe;
use App\Support\SitesDbCaches;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class SitesDbUpdateRequest extends FormRequest
{
    use ChangesSettings;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [];
        foreach (SitesDbCaches::CACHES as $key => $cache) {
            $rules[$cache['variable']] = ['bail', 'sometimes', 'nullable', 'string', 'max:16', $this->size($key)];
        }

        return $rules;
    }

    /**
     * The sizes the request sets, by `.env` key; null puts one back to the engine's default.
     *
     * @return array<string, ?string>
     */
    public function sizes(): array
    {
        $sizes = [];
        foreach (SitesDbCaches::CACHES as $key => $cache) {
            if (array_key_exists($cache['variable'], $this->changes())) {
                $value = $this->changes()[$cache['variable']];
                $sizes[$key] = $value === null ? null : strtoupper(trim((string) $value));
            }
        }

        return $sizes;
    }

    protected function fields(): array
    {
        return array_column(SitesDbCaches::CACHES, 'variable');
    }

    private function size(string $key): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($key): void {
            $why = SitesDbCaches::badSize($key, (string) $value, HostMemoryProbe::current()->totalMb);
            if ($why !== null) {
                $fail($why);
            }
        };
    }
}
