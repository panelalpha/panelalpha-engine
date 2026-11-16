<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Http\Middleware\EnsureTokenMayUseApi;
use App\Lib\Findings\Hints\Hint;

/** A token minted for the API; tokens are minted on the host. */
final class MintApiToken extends Hint
{
    public function resolves(): array
    {
        return [EnsureTokenMayUseApi::NOT_FOR_API];
    }

    public function params(): array
    {
        return ['name' => null];
    }

    public function cli(): string
    {
        return 'api:token:create {name}';
    }
}
