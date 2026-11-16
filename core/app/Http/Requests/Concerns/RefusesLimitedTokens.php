<?php

namespace App\Http\Requests\Concerns;

use App\Auth\TokenAbilities;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A request that mints a token or widens one: refused to a caller whose own token is limited to part of the API, so
 * no token can hand out more than it was given.
 */
trait RefusesLimitedTokens
{
    public function authorize(): bool
    {
        return TokenAbilities::forCurrentRequest()->routes() === null;
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('This token is limited to part of the API; only an unlimited token may mint or widen a token.');
    }
}
