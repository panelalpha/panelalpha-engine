<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Domains\HttpsPortsChange;
use App\Lib\Findings\Hints\Hint;

/** The operator lets the project use ports other than 443, which only an operator on the host can do. */
final class AllowHttpsPorts extends Hint
{
    public function resolves(): array
    {
        return [HttpsPortsChange::NOT_ALLOWED];
    }

    public function cli(): string
    {
        return 'system:public-routing:allow {username} --ports';
    }
}
