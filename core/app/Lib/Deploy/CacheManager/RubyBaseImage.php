<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Engine\ImageStore;

/**
 * A PanelAlpha-owned `ruby:*-slim-bookworm` with native gems' apt packages, built once on the host
 * ({@see ImageStore::hostBuildCommand()}) since a tenant build must never export layers. Tagged per package set.
 */
final class RubyBaseImage extends RuntimeBaseImage
{
    protected const RUNTIME = 'ruby';

    public const MAX_PACKAGES = 8;
}
