<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\PythonRuntime;

/**
 * A Django project with a `manage.py` wherever in the checkout it lives, since a subdirectory
 * is a standard layout (NetBox keeps `netbox/manage.py`, zane-ops its whole backend in `backend/`).
 */
final class DjangoProbe implements PlatformProbe
{
    public function id(): string
    {
        return 'django';
    }

    /**
     * True for a project whose Python manifest is at the root (NetBox); a `manage.py` below a root that holds none
     * names the directory with the nearest manifest as `app_root`, and with no such directory there is nothing to build.
     *
     * @return bool|array{app_root: string}
     */
    public function evaluate(ProjectContext $context): bool|array
    {
        $manageDir = PythonRuntime::djangoManageDir($context->projectDir);
        if ($manageDir === null) {
            return false;
        }
        if ($manageDir === '' || (new PythonRuntime)->resolve($context) !== null) {
            return true;
        }
        $appRoot = self::isSample($manageDir) ? null : self::applicationRoot($context->projectDir, $manageDir);

        return $appRoot === null ? false : ['app_root' => $appRoot];
    }

    /** Directories a repository keeps examples and tests in, never the application it deploys. */
    private const SAMPLE_DIRS = ['e2e', 'test', 'tests', 'example', 'examples', 'sample', 'samples', 'demo', 'demos', 'fixtures', 'docs'];

    private static function isSample(string $manageDir): bool
    {
        return array_intersect(array_map('strtolower', explode('/', $manageDir)), self::SAMPLE_DIRS) !== [];
    }

    /** The nearest directory from `manage.py`'s up to, not including, the root that holds a Python manifest. */
    private static function applicationRoot(string $projectDir, string $manageDir): ?string
    {
        for ($dir = $manageDir; $dir !== '' && $dir !== '.'; $dir = dirname($dir)) {
            if (PythonRuntime::hasPythonManifest(ProjectContext::at(rtrim($projectDir, '/').'/'.$dir))) {
                return $dir;
            }
        }

        return null;
    }
}
