<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\System;
use RuntimeException;

/**
 * Copy and remove files under a project's home directory as root.
 * Paths outside that home are rejected. Used when the queue worker (www-data)
 * cannot write into /home/<user> and when WP-CLI in the account container
 * needs a zip that lives on a bind-mounted path, not core's /tmp.
 */
final class AccountFileCopy
{
    public function __construct(
        private ?System $system = null,
    ) {
        $this->system ??= new System;
    }

    /**
     * Copy $source to $destination under $homePrefix, then chown the file
     * (and its parent dir) to $chown and set mode 0644 / 0755.
     */
    public function place(string $source, string $destination, string $homePrefix, string $chown): void
    {
        $this->assertUnderHome($destination, $homePrefix);
        if (! is_file($source)) {
            throw new RuntimeException("Source file not found: {$source}");
        }
        $this->assertChown($chown);

        $dir = dirname($destination);
        $this->system->exec(['sudo', 'mkdir', '-p', '--', $dir]);
        $this->system->exec(['sudo', 'cp', '--no-dereference', '--', $source, $destination]);
        $this->system->exec(['sudo', 'chown', $chown, '--', $dir]);
        $this->system->exec(['sudo', 'chmod', '0755', '--', $dir]);
        $this->system->exec(['sudo', 'chown', $chown, '--', $destination]);
        $this->system->exec(['sudo', 'chmod', '0644', '--', $destination]);
    }

    public function remove(string $path, string $homePrefix): void
    {
        $this->assertUnderHome($path, $homePrefix);
        $this->system->exec(['sudo', 'rm', '-f', '--', $path]);
    }

    /**
     * Create $path (and missing parents) under $homePrefix and give it to $chown.
     * The queue worker cannot mkdir inside /home/<user>.
     */
    public function ensureDirectory(string $path, string $homePrefix, string $chown, string $mode = '0755'): void
    {
        $this->assertUnderHome($path, $homePrefix);
        $this->assertChown($chown);
        if (preg_match('/^0[0-7]{3}$/', $mode) !== 1) {
            throw new RuntimeException('mode must be octal, e.g. 0755');
        }
        $this->system->exec(['sudo', 'mkdir', '-p', '--', $path]);
        $this->system->exec(['sudo', 'chown', $chown, '--', $path]);
        $this->system->exec(['sudo', 'chmod', $mode, '--', $path]);
    }

    private function assertChown(string $chown): void
    {
        if ($chown === '' || preg_match('/^\d+:\d+$/', $chown) !== 1) {
            throw new RuntimeException('chown must be uid:gid');
        }
    }

    private function assertUnderHome(string $path, string $homePrefix): void
    {
        $home = rtrim($homePrefix, '/');
        if ($home === '' || $home === '/') {
            throw new RuntimeException('Home prefix is invalid.');
        }

        $real = realpath($path);
        if ($real === false) {
            // Destination may not exist yet (place); check the path string.
            $normalized = $this->normalizeAbsolute($path);
            if (! str_starts_with($normalized, $home.'/') && $normalized !== $home) {
                throw new RuntimeException(
                    "Path '{$path}' is outside the project home '{$home}'."
                );
            }

            return;
        }

        if (! str_starts_with($real, $home.'/') && $real !== $home) {
            throw new RuntimeException(
                "Path '{$path}' is outside the project home '{$home}'."
            );
        }
    }

    private function normalizeAbsolute(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            throw new RuntimeException("Path must be absolute: {$path}");
        }

        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($parts === []) {
                    throw new RuntimeException("Path escapes root: {$path}");
                }
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        return '/'.implode('/', $parts);
    }
}
