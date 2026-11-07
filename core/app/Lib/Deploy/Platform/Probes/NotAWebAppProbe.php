<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Detect\EditorExtension;
use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * A Node package that is an editor extension (`engines.vscode` / `contributes`), not a server:
 * it builds cleanly and then crashes on `require('vscode')`.
 */
final class NotAWebAppProbe implements PlatformProbe
{
    public function id(): string
    {
        return 'not-a-web-app';
    }

    public function evaluate(ProjectContext $context): bool|array
    {
        return EditorExtension::describes($context->package() ?? []);
    }
}
