<?php

namespace App\Lib\Deploy\CacheManager;

use RuntimeException;

/** A repository that cannot have a shared build cache, with the reason in words. */
final class BuildCacheRefused extends RuntimeException {}
