<?php

namespace App\System\Project\PhpHosting\WordPress;

/** No single WordPress below a document root can be pointed at its own database, so a copy or push of it is refused. */
final class UnclearInstall extends Exception
{
    /** @param  list<Install>  $installs */
    public static function several(string $documentRoot, array $installs): self
    {
        $dirs = implode(', ', array_map(static fn (Install $install): string => $install->dir, $installs));

        return new self(
            "There is more than one WordPress below {$documentRoot} ({$dirs}), so a copy or push cannot tell which one "
            .'to point at its own database and is refused.'
        );
    }

    public static function unloadedConfig(string $configPath): self
    {
        return new self(
            "{$configPath} is loaded by no WordPress the engine can find, so a copy or push could not point it at its "
            .'own database and is refused.'
        );
    }
}
