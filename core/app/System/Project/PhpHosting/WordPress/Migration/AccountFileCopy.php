<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\System;
use App\System\Privileged;
use RuntimeException;

/**
 * Write and remove files only under a project's home, as the account: www-data cannot write there, nor WP-CLI read core's /tmp.
 * Each runs in a directory entered one component at a time, never via a symlink, so a planted link carries no write or chown.
 */
final class AccountFileCopy
{
    /** The engine opens the source; the account's command reads it on stdin. */
    private const string FROM_FILE = 'exec "${@:2}" < "$1"';

    public function __construct(
        private ?System $system = null,
    ) {
        $this->system ??= new System;
    }

    /**
     * Copy $source to $destination under $homePrefix as the account $chown,
     * mode 0644, creating missing parents; the parent directory gets 0755.
     */
    public function place(string $source, string $destination, string $homePrefix, string $chown): void
    {
        $home = $this->home($homePrefix);
        $relative = $this->relativeToHome($destination, $home);
        if (! is_file($source)) {
            throw new RuntimeException("Source file not found: {$source}");
        }
        $this->assertChown($chown);

        [$uid, $gid] = $this->ids($chown);

        $this->system->exec([
            'bash', '-c', self::FROM_FILE, 'bash', $source,
            ...Privileged::account()->placeFile($uid, $gid, $home, dirname($relative), '0755', basename($relative)),
        ]);
    }

    public function remove(string $path, string $homePrefix, string $chown): void
    {
        $home = $this->home($homePrefix);
        $relative = $this->relativeToHome($path, $home);
        $this->assertChown($chown);

        [$uid, $gid] = $this->ids($chown);

        $this->system->exec(Privileged::account()->removeFileBelow($uid, $gid, $home, dirname($relative), basename($relative)));
    }

    /**
     * Create $path (and parents) under $homePrefix as the account $chown, with $mode: the queue worker cannot mkdir in
     * /home/<user>, and the account cannot add an entry to its root-owned home, so only that first entry is made by root.
     */
    public function ensureDirectory(string $path, string $homePrefix, string $chown, string $mode = '0755'): void
    {
        $home = $this->home($homePrefix);
        $relative = $this->relativeToHome($path, $home);
        $this->assertChown($chown);
        if (preg_match('/^0[0-7]{3}$/', $mode) !== 1) {
            throw new RuntimeException('mode must be octal, e.g. 0755');
        }

        $entry = $home.'/'.explode('/', $relative)[0];
        $this->system->exec(Privileged::scripts()->makeHomeEntry($entry, $mode, $chown));
        [$uid, $gid] = $this->ids($chown);
        $this->system->exec(Privileged::account()->ensureDirectoryBelow($uid, $gid, $home, $relative, $mode));
    }

    /** @return array{0: int, 1: int} */
    private function ids(string $chown): array
    {
        $this->assertChown($chown);
        [$uid, $gid] = explode(':', $chown);

        return [(int) $uid, (int) $gid];
    }

    private function assertChown(string $chown): void
    {
        if ($chown === '' || preg_match('/^\d+:\d+$/', $chown) !== 1) {
            throw new RuntimeException('chown must be uid:gid');
        }
    }

    private function home(string $homePrefix): string
    {
        $home = rtrim($homePrefix, '/');
        if ($home === '' || $home === '/') {
            throw new RuntimeException('Home prefix is invalid.');
        }

        return $this->normalizeAbsolute($home);
    }

    /** $path below $home, as a relative path with `.`, `..` and `//` resolved by name. */
    private function relativeToHome(string $path, string $home): string
    {
        $normalized = $this->normalizeAbsolute($path);
        if (! str_starts_with($normalized, $home.'/')) {
            throw new RuntimeException(
                "Path '{$path}' is outside the project home '{$home}'."
            );
        }

        return substr($normalized, strlen($home) + 1);
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
