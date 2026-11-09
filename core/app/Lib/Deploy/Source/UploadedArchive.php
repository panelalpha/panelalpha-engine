<?php

namespace App\Lib\Deploy\Source;

use App\System\Privileged;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * An uploaded archive proven inside the account's home and of a supported format before any tar or unzip sees it.
 * Pure; contents are {@see ArchiveSafety}'s, and root never parses the account's bytes.
 */
final class UploadedArchive
{
    private const BROKER_DESCRIPTOR = '~/proc/self/fd/\d+~';

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
     * Every {@see ArchiveSafety} check on the staged copy, each listing run through $run. The mode listing
     * serves four checks: where a zip's backslashes separate folders, member types, symlink targets and the claimed size.
     *
     * @param  callable(list<string>): string  $run
     * @return list<string> the members
     */
    public function assertSafeContents(string $staged, callable $run): array
    {
        $listed = ArchiveSafety::names($this->listing($staged, $this->listNamesArgv($staged), $run));
        $members = $this->isZip ? $listed : ArchiveSafety::unescapeTarNames($listed);
        ArchiveSafety::assertSafeNames($members, backslashSeparates: $this->isZip);
        $modes = $this->listing($staged, $this->listModesArgv($staged), $run);
        if ($this->isZip) {
            ArchiveSafety::assertUnzipKeepsNames($members);
            ArchiveSafety::assertBackslashesSeparateFolders($modes, $members);
        }
        ArchiveSafety::assertRegularMembersOnly($modes);
        ArchiveSafety::assertLinksStayInside($this->linkTargets($staged, $modes, $listed, $run), $members);
        ArchiveSafety::assertUncompressedSizeWithin($modes);

        return $members;
    }

    /**
     * A listing that fails, as a corrupt zip's does, names the archive rather than its staged copy.
     *
     * @param  list<string>  $argv
     * @param  callable(list<string>): string  $run
     *
     * @throws RuntimeException
     */
    private function listing(string $staged, array $argv, callable $run): string
    {
        try {
            return $run($argv);
        } catch (\Throwable $e) {
            throw new RuntimeException('Could not read '.$this->name().': '.implode(' ', $this->said($e->getMessage(), $staged)), 0, $e);
        }
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

    public function stagedName(): string
    {
        return $this->isZip ? 'archive.zip' : 'archive.tar.gz';
    }

    /**
     * Members of the archive, one per line, for {@see ArchiveSafety}: tar is told its quoting style, so a
     * newline in a name stays escaped as {@see ArchiveSafety::unescapeTarNames()} reads it.
     *
     * @return list<string>
     */
    public function listNamesArgv(string $staged): array
    {
        return $this->isZip
            ? self::unzipCommand('-Z1', self::stagedPath($staged))
            : self::tarCommand('-tzf', $staged);
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
            ? self::unzipCommand('-Z', self::stagedPath($staged))
            : self::tarCommand('-tvzf', $staged);
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

        return self::unzipCommand('-p', self::stagedPath($staged), self::memberName($name));
    }

    /**
     * Extracts as the account by uid/gid through `setpriv`, its passwd entry being host-only, restoring no
     * owner; unzip writes the names `unzip -Z1` lists, a Unicode Path field included, so the checks see them.
     *
     * @return list<string>
     */
    public function extractArgv(int $uid, int $gid, string $staged, string $into): array
    {
        return $this->isZip
            ? Privileged::account()->extractZip($uid, $gid, $staged, $into)
            : Privileged::account()->extractTar($uid, $gid, $staged, $into);
    }

    /**
     * unzip's warning exit is a finished extraction, which the broker answers as 0; any other is not.
     *
     * @param  string  $staged  the copy the extractor read, which it names by this path unless the broker opened it as /proc/self/fd/N
     *
     * @throws RuntimeException what the extractor said, about the archive by its name
     */
    public function assertExtracted(Process $extraction, string $staged): void
    {
        if ($extraction->isSuccessful()) {
            return;
        }
        $said = $this->said($extraction->getErrorOutput(), $staged) ?: $this->said($extraction->getOutput(), $staged);

        throw new RuntimeException('Could not unpack '.$this->name().': '.implode(' ', $said));
    }

    /**
     * What unzip warned about while it extracted, such as backslashes it read as folder separators, a line each.
     * tar's are left out: it warns once per member about each macOS extended header it ignores.
     *
     * @param  string  $staged  the copy unzip extracted, which it names by this path unless the broker opened it as /proc/self/fd/N
     * @return list<string>
     */
    public function extractionWarnings(Process $extraction, string $staged): array
    {
        return $this->isZip ? $this->said($extraction->getErrorOutput(), $staged) : [];
    }

    /**
     * An extractor's lines with the archive named where they name the staged copy, by its path or as the /proc/self/fd/N
     * the broker hands it; the extractor runs inside the extract directory, so the members it names are relative.
     *
     * @return list<string>
     */
    private function said(string $output, string $staged): array
    {
        $named = (string) preg_replace(self::BROKER_DESCRIPTOR, $this->name(), str_replace($staged, $this->name(), $output));
        $lines = array_map(
            static fn (string $line): string => trim((string) preg_replace('/\s+/', ' ', $line)),
            preg_split('/\R/', $named) ?: []
        );

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    private function name(): string
    {
        return basename($this->path);
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
        return Privileged::account()->normaliseModes($uid, $gid, $dir);
    }

    /** @return list<string> */
    private static function tarCommand(string $listing, string $staged): array
    {
        return ['tar', '--quoting-style=escape', $listing, self::stagedPath($staged)];
    }

    /** Absolute, so neither tool can read it as an option. */
    private static function stagedPath(string $staged): string
    {
        if (! str_starts_with($staged, '/') || preg_match('~[\x00\n\r]~', $staged) === 1) {
            throw new InvalidArgumentException('Refusing staged archive path '.json_encode($staged, JSON_UNESCAPED_SLASHES));
        }

        return $staged;
    }

    /** unzip reads `-d` or `-x` after the archive as options too. */
    private static function memberName(string $name): string
    {
        if (str_starts_with($name, '-') || preg_match('~[\x00\n\r]~', $name) === 1) {
            throw new InvalidArgumentException('Refusing archive member name '.json_encode($name, JSON_UNESCAPED_SLASHES));
        }

        return $name;
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
