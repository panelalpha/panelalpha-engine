<?php

namespace App\System\Project;

use App\System as EngineSystem;
use RuntimeException;

/**
 * A classic restore that keeps the files already in the home: archive entries
 * overwrite theirs, every other file stays.
 *
 * The home's contents are the account's and stay writable while its PHP and
 * cron run, so a directory swapped for a symlink mid-restore would carry a root
 * write anywhere on the host. The account therefore unpacks the archive and
 * merges it into its own directories itself. Root acts only on the home's top
 * level, which is root-owned: it creates the scratch directory and moves in the
 * entries the account cannot place there, by rename, never through a link.
 */
final class HomeMerge
{
    private const string REAL_DIRECTORY = '[ -d "$1" ] && [ ! -L "$1" ]';

    private const string EXTRACT_FROM_STDIN = 'exec "${@:2}" < "$1"';

    private const string COPY_THROUGH_TAR = 'set -o pipefail; tar -C "$1" -cf - -- "${@:3}" | tar -C "$2" -xpf - --no-same-owner --no-overwrite-dir';

    public function __construct(
        private readonly EngineSystem $system,
        private readonly string $home,
        private readonly string $uid,
        private readonly string $gid,
        private readonly int $timeout,
    ) {
    }

    public function merge(string $archivePath, string $incomingDir): void
    {
        $this->createIncoming($incomingDir);
        $this->extractAsAccount($archivePath, $incomingDir);

        $merged = [];
        foreach ($this->list($incomingDir) as $entry) {
            if ($entry === basename($incomingDir)) {
                continue;
            }
            if ($this->bothDirectories($incomingDir . '/' . $entry, $this->home . '/' . $entry)) {
                $merged[] = $entry;
                continue;
            }
            $this->replace($incomingDir, $entry);
        }

        if ($merged !== []) {
            $this->mergeAsAccount($incomingDir, $merged);
        }
    }

    private function createIncoming(string $incomingDir): void
    {
        $this->run(['sudo', 'mkdir', '--', $incomingDir], 'Failed to create ' . basename($incomingDir) . ' directory');
        $this->run(
            ['sudo', 'chown', "{$this->uid}:{$this->gid}", '--', $incomingDir],
            'Failed to hand ' . basename($incomingDir) . ' to the account',
        );
    }

    /** The engine reads the archive and the account's tar writes it, so the account never needs to reach the staged file. */
    private function extractAsAccount(string $archivePath, string $incomingDir): void
    {
        $this->run([
            'bash', '-c', self::EXTRACT_FROM_STDIN, 'bash', $archivePath,
            ...$this->asAccount(), 'tar', '-xzpf', '-', '--no-same-owner', '-C', $incomingDir,
        ], 'Failed to extract the backup as the account');
    }

    /**
     * A top-level entry that is not a directory on both sides is renamed into place; a link or file
     * already there is removed first, so the rename replaces it rather than following it.
     */
    private function replace(string $incomingDir, string $entry): void
    {
        $target = $this->home . '/' . $entry;
        if (!$this->isDirectory($target)) {
            $this->run(['sudo', 'rm', '-f', '--', $target], "Failed to remove '{$entry}' before restoring it");
        }
        $this->run(
            ['sudo', 'mv', '-T', '--', $incomingDir . '/' . $entry, $target],
            "Failed to move restored '{$entry}' into the home",
        );
    }

    /**
     * Existing directories keep their own owner and mode, so one the account does not own is only written into.
     *
     * @param list<string> $entries
     */
    private function mergeAsAccount(string $incomingDir, array $entries): void
    {
        $this->run([
            ...$this->asAccount(),
            'bash', '-c', self::COPY_THROUGH_TAR, 'bash', $incomingDir, $this->home, ...$entries,
        ], 'Failed to merge the restored files into the home');
    }

    /** @return list<string> */
    private function asAccount(): array
    {
        return ['sudo', 'setpriv', '--reuid', $this->uid, '--regid', $this->gid, '--clear-groups'];
    }

    private function bothDirectories(string $incoming, string $target): bool
    {
        return $this->isDirectory($incoming) && $this->isDirectory($target);
    }

    /** A real directory: a symlink to one is not. */
    private function isDirectory(string $path): bool
    {
        return $this->system
            ->runProcess(['sudo', 'bash', '-c', self::REAL_DIRECTORY, 'bash', $path], [], $this->timeout)
            ->isSuccessful();
    }

    /**
     * @param list<string> $argv
     */
    private function run(array $argv, string $context): void
    {
        $process = $this->system->runProcess($argv, [], $this->timeout);
        if ($process->isSuccessful()) {
            return;
        }

        $output = trim($process->getErrorOutput() . ' ' . $process->getOutput());
        throw new RuntimeException($output !== '' ? "{$context}: {$output}" : $context);
    }

    /**
     * @return list<string>
     */
    private function list(string $dir): array
    {
        $process = $this->system->runProcess(['sudo', 'ls', '-A', $dir], [], $this->timeout);
        if (!$process->isSuccessful()) {
            throw new RuntimeException('Failed to list ' . basename($dir) . ' directory before merge.');
        }

        $entries = [];
        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry !== '') {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
