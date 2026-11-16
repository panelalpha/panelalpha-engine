<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * A WordPress on PHP hosting: the directory it runs from, which WP-CLI is pointed at, and the wp-config.php it loads,
 * which is only ever rewritten. WordPress also loads one from the directory above its own.
 */
final class Install
{
    public function __construct(
        public readonly string $dir,
        public readonly string $configPath,
    ) {}
}
