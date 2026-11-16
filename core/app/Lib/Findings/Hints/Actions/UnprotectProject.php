<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Http\Controllers\User\ProjectSettingController;
use App\Http\Middleware\EnsureProjectNotProtected;
use App\Lib\Findings\Hints\Hint;

/** The project's protected setting cleared, which only an operator on the host can do. */
final class UnprotectProject extends Hint
{
    public function resolves(): array
    {
        return [EnsureProjectNotProtected::CODE, ProjectSettingController::READ_ONLY];
    }

    public function cli(): string
    {
        return 'project:settings:unset protected --project={username}';
    }
}
