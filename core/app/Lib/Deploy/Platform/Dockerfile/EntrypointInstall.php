<?php

namespace App\Lib\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Platform\StageScript;
use App\Lib\Deploy\Template\Template;

/**
 * Installs the staged entrypoint, which branches on the deploy phase so migrations do not run on
 * every restart, and `exec`s the server as PID 1 so Docker's stop signal reaches it.
 */
final class EntrypointInstall
{
    public static function lines(): string
    {
        return rtrim(
            Template::named('dockerfile/entrypoint-install')->render(['entrypoint' => StageScript::FILENAME]),
            "\n"
        );
    }
}
