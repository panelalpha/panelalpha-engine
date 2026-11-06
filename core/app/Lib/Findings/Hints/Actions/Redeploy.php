<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\FindingCodes;
use App\Lib\Findings\Hints\Hint;

/** The project deployed again, once whatever failed is fixed. */
final class Redeploy extends Hint
{
    public function resolves(): array
    {
        return FindingCodes::failures();
    }

    public function rest(): string
    {
        return 'POST /projects/{username}/rebuild';
    }

    public function cli(): string
    {
        return 'project:rebuild --project={username}';
    }
}
