<?php

namespace App\System\Privileged;

/** A local backup store's directories as root. The broker takes only paths below {@see ROOTS}. */
final class RootBackupStore
{
    /** NormalizesBackupContainerCredentials::PERSISTENT_LOCATION_ROOTS */
    public const ROOTS = ['/home', '/opt/panelalpha', '/var/www/html/storage'];

    private const AREA = 'backup-store';

    /** @return list<string> */
    public function removeTree(string $dir): array
    {
        return $this->root(__FUNCTION__, [self::belowRoot($dir)]);
    }

    /**
     * Every empty directory in the store, the store itself last.
     *
     * @return list<string>
     */
    public function removeEmptyDirectories(string $store): array
    {
        return $this->root(__FUNCTION__, [self::belowRoot($store)]);
    }

    /** @return list<string> */
    public function removeEmptyParents(string ...$dirs): array
    {
        return $this->root(__FUNCTION__, array_map(self::belowRoot(...), array_values($dirs)));
    }

    /** @return list<string> */
    public function makeDirectories(string $dir): array
    {
        return $this->root(__FUNCTION__, [self::path($dir)]);
    }

    /** @return list<string> */
    public function giveToWebUser(string $dir): array
    {
        return $this->root(__FUNCTION__, [self::belowRoot($dir)]);
    }

    /** @return list<string> */
    public function openToWebUser(string $dir): array
    {
        return $this->root(__FUNCTION__, [self::belowRoot($dir)]);
    }

    private static function path(string $path): string
    {
        $path = Arguments::path($path);
        if (rtrim($path, '/') === '' || rtrim($path, '/') === '/home') {
            throw InvalidArgument::of('backup store path', $path);
        }

        return $path;
    }

    private static function belowRoot(string $path): string
    {
        if (in_array(rtrim(self::path($path), '/'), self::ROOTS, true)) {
            throw InvalidArgument::of('backup store path', $path);
        }

        return $path;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function root(string $action, array $values): array
    {
        return Root::action(self::AREA, $action, $values);
    }
}
