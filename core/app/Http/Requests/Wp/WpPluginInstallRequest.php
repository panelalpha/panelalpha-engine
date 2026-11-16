<?php

namespace App\Http\Requests\Wp;

use App\Exceptions\ProblemException;
use App\Rules\WpCliArgument;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @method array{slug: string, force?: bool, path?: string|null} validated($key = null, $default = null)
 */
class WpPluginInstallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** A field the body does not take is refused, so an older client's `url` cannot install something else. */
    protected function prepareForValidation(): void
    {
        $unknown = array_values(array_diff(array_keys($this->all()), array_keys($this->rules())));
        if ($unknown === []) {
            return;
        }

        throw ProblemException::of(array_map(
            static fn (string $key): array => [
                'field' => $key,
                'code' => 'unknown_field',
                'message' => "`{$key}` is not a field of this body.",
            ],
            $unknown
        ));
    }

    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', new WpCliArgument(WpCliArgument::SLUG)],
            'force' => 'sometimes|boolean',
            'path' => 'sometimes|nullable|string',
            'service' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'skip_plugins' => 'sometimes|boolean',
        ];
    }
}
