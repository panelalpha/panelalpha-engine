<?php

namespace App\System\Project;

use App\System as EngineSystem;
use App\System\Privileged;
use App\System\Privileged\AccountFiles;
use RuntimeException;

/**
 * A classic restore that keeps the home's other files, merged by the account itself, as a directory it swaps for a
 * symlink would carry a root write anywhere; root only renames entries into the root-owned top level, never via a link.
 */
final class HomeMerge
{
    private const string EXTRACT_FROM_STDIN = 'exec "${@:2}" < "$1"';

    public function __construct(
        private readonly EngineSystem $system,
        private readonly string $home,
        private readonly string $uid,
        private readonly string $gid,
        private readonly int $timeout,
    ) {}

    public function merge(string $archivePath, string $incomingDir): void
    {
        $this->createIncoming($incomingDir);
        $this->extractAsAccount($archivePath, $incomingDir);

        $merged = [];
        foreach ($this->list($incomingDir) as $entry) {
            if ($entry === basename($incomingDir)) {
                continue;
            }
            if ($this->bothDirectories($incomingDir.'/'.$entry, $this->home.'/'.$entry)) {
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
        $this->run(Privileged::files()->makeDirectory($incomingDir), 'Failed to create '.basename($incomingDir).' directory');
        $this->run(
            Privileged::files()->changeOwner("{$this->uid}:{$this->gid}", $incomingDir),
            'Failed to hand '.basename($incomingDir).' to the account',
        );
    }

    /** The engine reads the archive and the account's tar writes it, so the account never needs to reach the staged file. */
    private function extractAsAccount(string $archivePath, string $incomingDir): void
    {
        $this->run([
            'bash', '-c', self::EXTRACT_FROM_STDIN, 'bash', $archivePath,
            ...Privileged::account()->extractTarFromStdin((int) $this->uid, (int) $this->gid, $incomingDir),
        ], 'Failed to extract the backup as the account');
    }

    /**
     * A top-level entry that is not a directory on both sides is renamed into place; a link or file
     * already there is removed first, so the rename replaces it rather than following it.
     */
    private function replace(string $incomingDir, string $entry): void
    {
        $target = $this->home.'/'.$entry;
        if (! $this->isDirectory($target)) {
            $this->run(Privileged::files()->removeIfExists($target), "Failed to remove '{$entry}' before restoring it");
        }
        $this->run(
            Privileged::files()->rename($incomingDir.'/'.$entry, $target),
            "Failed to move restored '{$entry}' into the home",
        );
    }

    /**
     * Existing directories keep their own owner and mode, so one the account does not own is only written into.
     *
     * @param  list<string>  $entries
     */
    private function mergeAsAccount(string $incomingDir, array $entries): void
    {
        $this->run(
            Privileged::account()->mergeTree((int) $this->uid, (int) $this->gid, $incomingDir, $this->home, $entries),
            'Failed to merge the restored files into the home',
        );
    }

    /** As the account, which owns the restore dir and can read the 0755 home. */
    private function accountFiles(): AccountFiles
    {
        return Privileged::accountFiles((int) $this->uid, (int) $this->gid);
    }

    private function bothDirectories(string $incoming, string $target): bool
    {
        return $this->isDirectory($incoming) && $this->isDirectory($target);
    }

    /** A real directory: a symlink to one is not. */
    private function isDirectory(string $path): bool
    {
        return $this->system
            ->runProcess($this->accountFiles()->isRealDirectory($path), [], $this->timeout)
            ->isSuccessful();
    }

    /**
     * @param  list<string>  $argv
     */
    private function run(array $argv, string $context): void
    {
        $process = $this->system->runProcess($argv, [], $this->timeout);
        if ($process->isSuccessful()) {
            return;
        }

        $output = trim($process->getErrorOutput().' '.$process->getOutput());
        throw new RuntimeException($output !== '' ? "{$context}: {$output}" : $context);
    }

    /**
     * @return list<string>
     */
    private function list(string $dir): array
    {
        $process = $this->system->runProcess($this->accountFiles()->listAllNames($dir), [], $this->timeout);
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Failed to list '.basename($dir).' directory before merge.');
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
