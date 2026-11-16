<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Domains\PublicUrl;
use App\Lib\Findings\Hints\Hint;

/** A domain the caller controls, pointed at this host and added to the project. */
final class AddDomain extends Hint
{
    public function resolves(): array
    {
        return [PublicUrl::UNRESOLVABLE];
    }

    public function rest(): string
    {
        return 'POST /projects/{username}/domains';
    }

    public function params(): array
    {
        return ['domain' => null];
    }

    public function cli(): string
    {
        return 'domain:create {domain} --project={username}';
    }
}
