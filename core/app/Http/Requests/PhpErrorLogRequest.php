<?php

namespace App\Http\Requests;

use App\Lib\Project\LogTime;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

class PhpErrorLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lines' => 'sometimes|integer|min:1|max:5000',
            'since' => ['sometimes', 'string', self::sinceRule()],
            'domain' => 'sometimes|string',
        ];
    }

    /** A date, as before, or a duration back from now, as the container logs take. */
    private static function sinceRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && (LogTime::isDuration($value) || Validator::make(['since' => $value], ['since' => 'date'])->passes())) {
                return;
            }
            $fail('The since must be ' . LogTime::EXPECTED . '.');
        };
    }
}
