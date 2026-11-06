<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Http\Controllers\User\ProjectSettingController;
use App\Lib\Findings\Hints\Hint;

/** The project's protected setting set, which only an operator on the host can do. */
final class ProtectProject extends Hint
{
    public function resolves(): array
    {
        return [ProjectSettingController::READ_ONLY];
    }

    public function cli(): string
    {
        return 'project:settings:set protected on --project={username}';
    }
}
