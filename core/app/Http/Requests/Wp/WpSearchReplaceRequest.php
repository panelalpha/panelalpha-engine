<?php

namespace App\Http\Requests\Wp;

use App\Http\Requests\Concerns\ReportsProblems;
use App\Rules\SearchReplaceArgument;
use App\Rules\WpCliArgument;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @method array{search: string, replacement: string, args?: list<string>, path?: string|null} validated($key = null, $default = null)
 */
class WpSearchReplaceRequest extends FormRequest
{
    use ReportsProblems;

    public function authorize(): bool
    {
        return true;
    }

    /** The replacement is untrimmed and may be empty (see AppServiceProvider): '' removes the search string. */
    public function rules(): array
    {
        return [
            'search' => ['required', 'string', new WpCliArgument],
            'replacement' => ['present', 'string', new WpCliArgument],
            'args' => 'sometimes|array',
            'args.*' => ['string', new SearchReplaceArgument],
            'path' => 'sometimes|nullable|string',
            'service' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'skip_plugins' => 'sometimes|boolean',
        ];
    }
}
