<?php

namespace App\Lib\Deploy\CacheManager;

/**
 * A PanelAlpha-owned `python:*-slim` derivative with the headers a source build needs, baked once on the host.
 * A deploy waits for it, unlike Ruby's: Python has no per-project Dockerfile to install them in.
 */
final class PythonBaseImage extends RuntimeBaseImage
{
    protected const RUNTIME = 'python';

    public const MAX_PACKAGES = 12;
}
