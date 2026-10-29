<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\System;
use RuntimeException;

/**
 * Write and remove files under a project's home directory as the account.
 * Paths outside that home are rejected. Used when the queue worker (www-data)
 * cannot write into /home/<user> and when WP-CLI in the account container
 * needs a zip that lives on a bind-mounted path, not core's /tmp.
 *
 * Every write runs as the account's uid and gid, from a working directory
 * entered one component at a time from the home, never through a symlink:
 * a link the tenant plants cannot carry a write, chown or chmod anywhere.
 */
final class AccountFileCopy
{
    /** The engine opens the source; the account's command reads it on stdin. */
    private const string FROM_FILE = 'exec "${@:2}" < "$1"';

    private const string ENTER_DIRECTORY = <<<'SH'
        set -euf
        cd -P -- "$1"
        home=$(pwd -P)
        IFS=/
        for part in $2; do
            if [ -L "$part" ]; then
                echo "Refusing to use '$1/$2': '$part' is a symlink." >&2
                exit 3
            fi
            [ -d "$part" ] || mkdir -m "$3" -- "$part"
            cd -P -- "$part"
        done
        case "$(pwd -P)/" in
            "$home"/*) ;;
            *) echo "Refusing to use '$1/$2': it leads out of the account's home." >&2; exit 3 ;;
        esac
        chmod "$3" .
        SH;

    private const string WRITE_FILE = self::ENTER_DIRECTORY."\n".<<<'SH'
        tmp=$(mktemp .panelalpha-XXXXXXXXXX)
        trap 'rm -f -- "$tmp"' EXIT
        cat > "$tmp"
        chmod 0644 -- "$tmp"
        mv -fT -- "$tmp" "$4"
        SH;

    private const string REMOVE_FILE = <<<'SH'
        set -euf
        cd -P -- "$1"
        IFS=/
        for part in $2; do
            if [ -L "$part" ]; then
                echo "Refusing to use '$1/$2': '$part' is a symlink." >&2
                exit 3
            fi
            [ -d "$part" ] || exit 0
            cd -P -- "$part"
        done
        rm -f -- "$3"
        SH;

    /**
     * Run as root, only for an entry directly in the home: the home is
     * root's, so the tenant cannot swap that entry, and `chown -h` would
     * only ever change a link itself.
     */
    private const string MAKE_HOME_ENTRY = <<<'SH'
        set -eu
        if [ -L "$1" ]; then
            echo "Refusing to use '$1': it is a symlink." >&2
            exit 3
        fi
        [ -d "$1" ] || { mkdir -m "$2" -- "$1" && chown -h "$3" -- "$1"; }
        SH;

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

        $this->system->exec([
            'bash', '-c', self::FROM_FILE, 'bash', $source,
            ...$this->asAccount($chown),
            'sh', '-c', self::WRITE_FILE, 'sh', $home, dirname($relative), '0755', basename($relative),
        ]);
    }

    public function remove(string $path, string $homePrefix, string $chown): void
    {
        $home = $this->home($homePrefix);
        $relative = $this->relativeToHome($path, $home);
        $this->assertChown($chown);

        $this->system->exec([
            ...$this->asAccount($chown),
            'sh', '-c', self::REMOVE_FILE, 'sh', $home, dirname($relative), basename($relative),
        ]);
    }

    /**
     * Create $path (and missing parents) under $homePrefix as the account
     * $chown and set $mode on it. The queue worker cannot mkdir inside
     * /home/<user>, and the account cannot create an entry in its root-owned
     * home, so only that first entry is made by root.
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
        $this->system->exec(['sudo', 'sh', '-c', self::MAKE_HOME_ENTRY, 'sh', $entry, $mode, $chown]);
        $this->system->exec([
            ...$this->asAccount($chown),
            'sh', '-c', self::ENTER_DIRECTORY, 'sh', $home, $relative, $mode,
        ]);
    }

    /** @return list<string> */
    private function asAccount(string $chown): array
    {
        [$uid, $gid] = explode(':', $chown);

        return ['sudo', 'setpriv', '--reuid', $uid, '--regid', $gid, '--clear-groups'];
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
