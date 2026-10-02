<?php

namespace App\Lib\Deploy\Source;

use InvalidArgumentException;

/**
 * An archive an account uploaded, once it has been shown to be one.
 *
 * Everything here is decided before a single byte is extracted: that the path
 * really resolves inside the account's own home, and which of the two
 * supported formats it is. The checks are the reason the type exists — a
 * relative path, a symlink pointing out of the home, or a `.php` renamed to
 * `.zip` are all refused here rather than by whichever `sudo tar` would
 * otherwise have been handed them.
 *
 * No Laravel or filesystem-mutating dependencies, so the rules are testable
 * without an account. {@see ArchiveSafety} covers the separate question of
 * what the archive is allowed to contain.
 */
final class UploadedArchive
{
    private function __construct(
        /** The archive's resolved absolute path, inside the account home. */
        public readonly string $path,
        public readonly bool $isZip
    ) {
    }

    /**
     * @param string $zipPath as the caller gave it: absolute, or relative to
     *        the account home
     * @param string $homeDir the account home, without a trailing slash
     * @throws InvalidArgumentException when the path escapes the home or the
     *         format is not one we extract
     */
    public static function inHome(string $zipPath, string $homeDir): self
    {
        $home = rtrim($homeDir, '/');
        if (!str_starts_with($zipPath, $home . '/') && $zipPath !== $home) {
            $zipPath = $home . '/' . ltrim($zipPath, '/');
        }
        if (!is_file($zipPath)) {
            throw new InvalidArgumentException("Archive not found: {$zipPath}");
        }

        // realpath() after the is_file() check, so a symlink pointing out of
        // the home is caught by where it lands rather than by how it is
        // spelled.
        $real = realpath($zipPath);
        if ($real === false || !str_starts_with($real, $home . '/')) {
            throw new InvalidArgumentException('Archive path is outside the user home directory.');
        }

        return new self($real, self::formatOf($real));
    }

    /** The name the staged root-owned copy is given. */
    public function stagedName(): string
    {
        return $this->isZip ? 'archive.zip' : 'archive.tar.gz';
    }

    /**
     * Members of the archive, one per line, for {@see ArchiveSafety}.
     *
     * @return list<string>
     */
    public function listNamesArgv(string $staged): array
    {
        return $this->isZip
            ? ['sudo', 'unzip', '-Z1', $staged]
            : ['sudo', 'tar', '-tzf', $staged];
    }

    /**
     * The same listing with modes, so a device node or a symlink is refused
     * before extraction rather than after.
     *
     * @return list<string>
     */
    public function listModesArgv(string $staged): array
    {
        return $this->isZip
            ? ['sudo', 'unzip', '-Z', $staged]
            : ['sudo', 'tar', '-tvzf', $staged];
    }

    /**
     * Extraction runs as the account user, and never restores an owner the
     * archive asked for: an upload does not get to choose who owns what it
     * unpacks to. Modes are evened out afterwards by {@see normaliseModesArgv()}.
     *
     * The account is named by uid and gid, not by username, because this
     * runs in the core container and the account's passwd entry exists only
     * on the host — `sudo -u <name>` and even `sudo -u '#<uid>'` fail there
     * with "unknown user". `setpriv` drops to the ids directly and needs no
     * passwd lookup; `--clear-groups` leaves nothing of root's group set.
     *
     * @return list<string>
     */
    public function extractArgv(int $uid, int $gid, string $staged, string $into): array
    {
        $asAccount = self::asAccount($uid, $gid);
        if ($this->isZip) {
            return [...$asAccount, 'unzip', '-UU', '-o', $staged, '-d', $into];
        }

        return [
            ...$asAccount, 'tar',
            '--no-same-owner', '--no-same-permissions', '-xzf', $staged, '-C', $into,
        ];
    }

    /**
     * Run after {@see extractArgv()}: files 0644, or 0755 when any execute bit
     * is set, directories 0755, as a git checkout would give them. unzip and
     * tar keep a member's own mode, and Python's zipfile.writestr() writes
     * 0600; detection runs as another user and could not read such files.
     *
     * @return list<string>
     */
    public function normaliseModesArgv(int $uid, int $gid, string $dir): array
    {
        return [...self::asAccount($uid, $gid), 'chmod', '-R', 'u+rwX,go=rX', '--', $dir];
    }

    /** @return list<string> */
    private static function asAccount(int $uid, int $gid): array
    {
        return ['sudo', 'setpriv', '--reuid', (string) $uid, '--regid', (string) $gid, '--clear-groups'];
    }

    private static function formatOf(string $path): bool
    {
        $filename = strtolower(basename($path));
        if (str_ends_with($filename, '.zip')) {
            return true;
        }
        if (str_ends_with($filename, '.tar.gz') || str_ends_with($filename, '.tgz')) {
            return false;
        }

        throw new InvalidArgumentException('Unsupported archive format, expected .zip or .tar.gz');
    }
}
