<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method array{
 *   command: string,
 *   cwd?: ?string,
 *   timeout?: ?int,
 * } validated($key = null, $default = null)
 */
class SshCommandRunRequest extends FormRequest
{
    /** Longest a command may run, in seconds. */
    public const MAX_TIMEOUT = 900;

    public const DEFAULT_TIMEOUT = 300;

    public function rules(): array
    {
        return [
            // A whole command line, not argv: it reaches bash intact so pipes
            // and redirection work, which is the point of shell access.
            'command' => 'required|string|max:65535',
            'cwd' => 'nullable|string|max:4096',
            // Bounded so a caller cannot pin a worker on a command that never
            // returns; the request is synchronous.
            'timeout' => 'nullable|integer|min:1|max:'.self::MAX_TIMEOUT,
        ];
    }
}
