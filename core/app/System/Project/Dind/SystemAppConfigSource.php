<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Platform\AppConfig\AppConfigSource;
use App\System;

/**
 * Reads app config descriptors through {@see System}, which is what can reach an
 * account's root-owned project directory.
 */
final class SystemAppConfigSource implements AppConfigSource
{
    public function __construct(private readonly System $system) {}

    public function exists(string $path): bool
    {
        return $this->system->filesystem()->fileExists($path);
    }

    public function read(string $path): ?string
    {
        if (! $this->system->filesystem()->fileExists($path)) {
            return null;
        }
        $contents = $this->system->filesystem()->fileGetContents($path);

        return $contents !== '' ? $contents : null;
    }

    public function isDirectory(string $path): bool
    {
        return $this->system->filesystem()->directoryExists($path);
    }

    /** @return list<string> */
    public function listFiles(string $dir): array
    {
        if (! $this->isDirectory($dir)) {
            return [];
        }

        try {
            $output = $this->system->exec(['sudo', 'find', $dir, '-type', 'f', '-printf', '%P\n']);
        } catch (\Throwable) {
            return [];
        }

        $paths = array_values(array_filter(array_map('trim', explode("\n", $output))));
        sort($paths);

        return $paths;
    }
}
