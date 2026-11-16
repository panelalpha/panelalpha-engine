<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\PythonRuntime;

/**
 * A Django project with a `manage.py` wherever in the checkout it lives, since a subdirectory
 * is a standard layout (NetBox keeps `netbox/manage.py`).
 */
final class DjangoProbe implements PlatformProbe
{
    public function id(): string
    {
        return 'django';
    }

    public function evaluate(ProjectContext $context): bool
    {
        return PythonRuntime::djangoManageDir($context->projectDir) !== null;
    }
}
