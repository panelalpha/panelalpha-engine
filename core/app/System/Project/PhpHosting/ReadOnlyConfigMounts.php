<?php

namespace App\System\Project\PhpHosting;

use App\System\Project\PhpHosting;

/**
 * Makes the mounts of config the engine writes from outside (vhosts, certificates, PHP, msmtp, root-run init scripts)
 * read-only in an existing project's compose file. Adds none; a mount takes effect when its container is recreated.
 */
final class ReadOnlyConfigMounts
{
    private const ENGINE_CONFIG = '/^(\s+- \.\/(?:apache-sites|apache-conf\/|ssl-certs|msmtp\/|entrypoint-init\.d|php\/)\S*?)(?<!:ro)$/m';

    /** Whether the compose file changed. */
    public function apply(PhpHosting $project): bool
    {
        $path = $project->composeFilePath();
        if (! is_file($path)) {
            return false;
        }

        $compose = (string) file_get_contents($path);
        $restricted = self::restrict($compose);
        if ($restricted === $compose) {
            return false;
        }
        file_put_contents($path, $restricted);

        return true;
    }

    public static function restrict(string $compose): string
    {
        return (string) preg_replace(self::ENGINE_CONFIG, '$1:ro', $compose);
    }
}
