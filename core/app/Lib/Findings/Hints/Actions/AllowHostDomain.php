<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\Hints\Hint;
use App\Lib\Project\PublicRouting\HostDomain;

/** The operator lets the project hold the server's own name, which only an operator on the host can do. */
final class AllowHostDomain extends Hint
{
    public function resolves(): array
    {
        return [HostDomain::CLAIM_REFUSED];
    }

    public function cli(): string
    {
        return 'system:public-routing:allow {username} --host-domain';
    }
}
