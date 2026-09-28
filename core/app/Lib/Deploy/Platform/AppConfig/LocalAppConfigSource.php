<?php

namespace App\Lib\Deploy\Platform\AppConfig;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Plain filesystem reads, for callers that need no privilege escalation —
 * tests, and anything inspecting a directory the process already owns.
 */
final class LocalAppConfigSource implements AppConfigSource
{
    public function exists(string $path): bool
    {
        return is_file($path);
    }

    public function read(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $contents = @file_get_contents($path);

        return is_string($contents) ? $contents : null;
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    /** @return list<string> */
    public function listFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $found = [];
        foreach (ProjectContext::entries($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                foreach ($this->listFiles($path) as $nested) {
                    $found[] = $entry . '/' . $nested;
                }
                continue;
            }
            if (is_file($path)) {
                $found[] = $entry;
            }
        }
        sort($found);

        return $found;
    }
}
