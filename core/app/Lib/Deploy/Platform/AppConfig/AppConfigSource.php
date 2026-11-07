<?php

namespace App\Lib\Deploy\Platform\AppConfig;

/**
 * Reads descriptor files from the root-owned project directory or the local pages directory,
 * injected so the locator need not care; tests hand in {@see LocalAppConfigSource}.
 */
interface AppConfigSource
{
    public function exists(string $path): bool;

    /** Contents, or null when the path is unreadable. */
    public function read(string $path): ?string;

    public function isDirectory(string $path): bool;

    /**
     * Paths of every file under $dir, relative to it, recursively.
     *
     * @return list<string>
     */
    public function listFiles(string $dir): array;
}
