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
 * without an account: the file itself is looked up by the caller, as the
 * account, since the engine's PHP cannot enter the account's private
 * directories (~/.panelalpha is 0700). {@see ArchiveSafety} covers the
 * separate question of what the archive is allowed to contain.
 */
final class UploadedArchive
{
    private function __construct(
        /** The archive's resolved absolute path, inside the account home. */
        public readonly string $path,
        public readonly bool $isZip
    ) {}

    /**
     * @param  string  $zipPath  as the caller gave it: absolute, or relative to
     *                           the account home
     * @param  string  $homeDir  the account home, without a trailing slash
     * @param  callable(string): ?string  $locate  where the regular file at a path
     *                                             lands, every link followed, as the
     *                                             account sees it; null when there is none
     *
     * @throws InvalidArgumentException when the path escapes the home or the
     *                                  format is not one we extract
     */
    public static function inHome(string $zipPath, string $homeDir, callable $locate): self
    {
        $home = rtrim($homeDir, '/');
        $zipPath = self::anchoredInHome($zipPath, $home);
        $real = $locate($zipPath);
        if ($real === null) {
            throw new InvalidArgumentException("Archive not found: {$zipPath}");
        }
        self::assertLandsInHome($real, $home);

        return new self($real, self::formatOf($real));
    }

    private static function anchoredInHome(string $zipPath, string $home): string
    {
        if (! str_starts_with($zipPath, $home.'/') && $zipPath !== $home) {
            return $home.'/'.ltrim($zipPath, '/');
        }

        return $zipPath;
    }

    /** Judged by where the path lands, so a symlink out of the home is caught rather than by how it is spelled. */
    private static function assertLandsInHome(string $real, string $home): void
    {
        if (! str_starts_with($real, $home.'/')) {
            throw new InvalidArgumentException('Archive path is outside the user home directory.');
        }
    }

    /** A path the caller has already confined to the home, as the account. */
    public static function confined(string $path, bool $isZip): self
    {
        return new self($path, $isZip);
    }

    /**
     * Every {@see ArchiveSafety} check on the staged copy, each listing run through $run.
     * The mode listing serves three checks: member types, symlink targets and the claimed size.
     *
     * @param  callable(list<string>): string  $run
     * @return list<string> the members
     */
    public function assertSafeContents(string $staged, callable $run): array
    {
        $listed = ArchiveSafety::names($run($this->listNamesArgv($staged)));
        $members = $this->isZip ? $listed : ArchiveSafety::unescapeTarNames($listed);
        ArchiveSafety::assertSafeNames($members, backslashSeparates: $this->isZip);
        if ($this->isZip) {
            ArchiveSafety::assertUnzipKeepsNames($members);
        }
        $modes = $run($this->listModesArgv($staged));
        ArchiveSafety::assertRegularMembersOnly($modes);
        ArchiveSafety::assertLinksStayInside($this->linkTargets($staged, $modes, $listed, $run), $members);
        ArchiveSafety::assertUncompressedSizeWithin($modes);

        return $members;
    }

    /**
     * Each symlink member's target: tar prints it in the listing, zip stores
     * it as the member's contents.
     *
     * @param  list<string>  $names  as listed, so a tar name is still escaped
     * @param  callable(list<string>): string  $run
     * @return array<string, string>
     */
    private function linkTargets(string $staged, string $modes, array $names, callable $run): array
    {
        if (! $this->isZip) {
            return ArchiveSafety::tarLinks($modes, $names);
        }
        $links = [];
        foreach (ArchiveSafety::zipLinkNames($modes, $names) as $name) {
            $links[$name] = $run($this->readMemberArgv($staged, $name));
        }

        return $links;
    }

    /** The name the staged root-owned copy is given. */
    public function stagedName(): string
    {
        return $this->isZip ? 'archive.zip' : 'archive.tar.gz';
    }

    /**
     * Members of the archive, one per line, for {@see ArchiveSafety}. tar is told its
     * quoting style rather than trusted with a default: a name is one line only while
     * a newline in it is escaped, and {@see ArchiveSafety::unescapeTarNames()} reads that style.
     *
     * @return list<string>
     */
    public function listNamesArgv(string $staged): array
    {
        return $this->isZip
            ? ['sudo', ...self::unzipCommand('-Z1', $staged)]
            : ['sudo', 'tar', '--quoting-style=escape', '-tzf', $staged];
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
            ? ['sudo', ...self::unzipCommand('-Z', $staged)]
            : ['sudo', 'tar', '--quoting-style=escape', '-tvzf', $staged];
    }

    /**
     * One zip member's contents on stdout: a symlink's target. unzip reads
     * the name as a pattern, so a name with a wildcard in it is refused.
     *
     * @return list<string>
     */
    public function readMemberArgv(string $staged, string $name): array
    {
        if (strpbrk($name, '*?[]\\') !== false) {
            throw new InvalidArgumentException('Archive contains a symbolic link, which could redirect extraction outside the project directory.');
        }

        return ['sudo', ...self::unzipCommand('-p', $staged, $name)];
    }

    /**
     * Extracts as the account, never restoring an owner the archive asked for. By uid/gid via
     * `setpriv`: the account's passwd entry exists only on the host, so `sudo -u` fails in core.
     * unzip writes the names `unzip -Z1` lists, a Unicode Path field included, so the checks see them.
     *
     * @return list<string>
     */
    public function extractArgv(int $uid, int $gid, string $staged, string $into): array
    {
        $asAccount = self::asAccount($uid, $gid);
        if ($this->isZip) {
            return [...$asAccount, ...self::unzipCommand('-o', $staged, '-d', $into)];
        }

        return [
            ...$asAccount, 'tar',
            '--no-same-owner', '--no-same-permissions', '-xzf', $staged, '-C', $into,
        ];
    }

    /**
     * unzip in a UTF-8 locale, whatever the caller's: in the C locale PHP-FPM runs in, it lists and writes
     * every non-ASCII name of a `zip -r` archive as `#Uxxxx`. Every listing and the extraction share it.
     *
     * @return list<string>
     */
    public static function unzipCommand(string ...$arguments): array
    {
        return ['env', 'LC_ALL=C.UTF-8', 'unzip', ...$arguments];
    }

    /**
     * Run after {@see extractArgv()}: modes as a git checkout gives them (0644/0755), since a
     * member's own mode (zipfile.writestr() writes 0600) would hide it from detection.
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
