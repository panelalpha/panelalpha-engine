<?php

namespace App\Lib\Deploy\CacheManager;

/**
 * A PanelAlpha-owned `python:*-slim` derivative carrying the headers a source
 * build needs — apt packages baked once on the host instead of failed on in
 * every account.
 *
 * A deploy waits for this image (`runnable: true` in the catalogue) where
 * Ruby's defers: Ruby's per-project Dockerfile installs the same packages
 * itself, while Python has no per-project Dockerfile and this is the only
 * place the headers exist.
 */
final class PythonBaseImage extends RuntimeBaseImage
{
    protected const RUNTIME = 'python';

    public const MAX_PACKAGES = 12;
}
