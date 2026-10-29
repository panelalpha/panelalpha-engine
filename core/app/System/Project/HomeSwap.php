<?php

namespace App\System\Project;

use App\System as EngineSystem;
use RuntimeException;

/**
 * The move-aside / move-in / rollback protocol shared by a push into a home and
 * a classic restore: the live home's entries go aside, the incoming directory's
 * entries take their place, and a failed restore puts the aside back.
 *
 * Every step runs as root through the Engine's process runner.
 */
final class HomeSwap
{
    private bool $started = false;

    /** @var list<string> */
    private array $movedAside = [];

    /** @var list<string> */
    private array $movedIn = [];

    public function __construct(
        private readonly EngineSystem $system,
        private readonly string $home,
        private readonly int $timeout,
    ) {}

    /**
     * @param  list<string>  $skipEntries  home entries never moved aside
     */
    public function swap(string $incomingDir, string $asideDir, array $skipEntries, string $label): void
    {
        $this->started = true;
        $this->run(
            ['sudo', 'mkdir', '-p', $asideDir],
            'Failed to create '.basename($asideDir).' directory',
        );

        foreach ($this->list($this->home, "Failed to list target home before {$label}.") as $entry) {
            if (in_array($entry, $skipEntries, true)) {
                continue;
            }
            $this->run(
                ['sudo', 'mv', $this->home.'/'.$entry, $asideDir.'/'],
                "Failed to move '{$entry}' aside before {$label}",
            );
            $this->movedAside[] = $entry;
        }

        $incomingName = basename($incomingDir);
        foreach ($this->list($incomingDir, "Failed to list {$incomingName} directory before {$label}.") as $entry) {
            $this->run(
                ['sudo', 'mv', $incomingDir.'/'.$entry, $this->home.'/'],
                "Failed to move incoming '{$entry}' into target home",
            );
            $this->movedIn[] = $entry;
        }

        if ($this->isDirectory($incomingDir)) {
            $this->run(
                ['sudo', 'rmdir', $incomingDir],
                "Failed to remove empty {$incomingName} directory after swap",
            );
        }
    }

    public function hasStarted(): bool
    {
        return $this->started;
    }

    /**
     * Undo what this instance's swap() moved, however far it got: the entries
     * it moved in go to $incomingDir, the entries it moved aside come back.
     * Nothing else in the home is touched.
     */
    public function undo(string $incomingDir, string $asideDir, string $label): void
    {
        if ($this->movedIn !== []) {
            $this->run(['sudo', 'mkdir', '-p', $incomingDir], "Failed to recreate the incoming directory for {$label} undo");
        }

        foreach ($this->movedIn as $index => $entry) {
            $this->run(
                ['sudo', 'mv', $this->home.'/'.$entry, $incomingDir.'/'],
                "Failed to move incoming '{$entry}' out during {$label} undo",
            );
            unset($this->movedIn[$index]);
        }

        foreach ($this->movedAside as $index => $entry) {
            $this->run(
                ['sudo', 'mv', $asideDir.'/'.$entry, $this->home.'/'],
                "Failed to move '{$entry}' back during {$label} undo",
            );
            unset($this->movedAside[$index]);
        }
    }

    /**
     * Clear whatever the incoming move left in the home and put the aside back.
     *
     * @param  list<string>  $skipEntries  home entries never touched
     */
    public function rollback(string $incomingDir, string $asideDir, array $skipEntries, string $label): void
    {
        foreach ($this->list($this->home, "Failed to list target home before {$label} rollback.") as $entry) {
            if (in_array($entry, $skipEntries, true)) {
                continue;
            }
            $this->run(
                ['sudo', 'mv', $this->home.'/'.$entry, $incomingDir.'/'],
                "Failed to move live '{$entry}' aside during {$label} rollback",
            );
        }

        foreach ($this->list($asideDir, "Failed to list the aside during {$label} rollback.") as $entry) {
            $this->run(
                ['sudo', 'mv', $asideDir.'/'.$entry, $this->home.'/'],
                "Failed to move the aside '{$entry}' back during {$label} rollback",
            );
        }
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
    private function list(string $dir, string $context): array
    {
        $process = $this->system->runProcess(['sudo', 'ls', '-A', $dir], [], $this->timeout);
        if (! $process->isSuccessful()) {
            throw new RuntimeException($context);
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

    private function isDirectory(string $dir): bool
    {
        return $this->system
            ->runProcess(['sudo', 'test', '-d', $dir], [], $this->timeout)
            ->isSuccessful();
    }
}
