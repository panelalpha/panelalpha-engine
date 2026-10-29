<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Places the engine panelalpha_sso must-use plugin on a WordPress install.
 */
interface EnsuresMustUsePlugin
{
    public function ensure(string $installRoot): void;
}
