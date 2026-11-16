<?php

namespace App\System\Project;

use App\System as EngineSystem;
use App\System\Privileged;
use RuntimeException;

/**
 * The move-aside / move-in / rollback protocol of a push into a home and of a classic restore: the live home's entries
 * go aside, the incoming ones take their place, and a failed restore puts the aside back. Every step runs as root.
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
            Privileged::files()->makeDirectories($asideDir),
            'Failed to create '.basename($asideDir).' directory',
        );

        $this->moveHomeAside($asideDir, $skipEntries, $label);

        $incomingName = basename($incomingDir);
        foreach ($this->list($incomingDir, "Failed to list {$incomingName} directory before {$label}.") as $entry) {
            $this->run(
                Privileged::files()->move($incomingDir.'/'.$entry, $this->home.'/'),
                "Failed to move incoming '{$entry}' into target home",
            );
            $this->movedIn[] = $entry;
        }

        if ($this->isDirectory($incomingDir)) {
            $this->run(
                Privileged::files()->removeEmptyDirectory($incomingDir),
                "Failed to remove empty {$incomingName} directory after swap",
            );
        }
    }

    /**
     * @param  list<string>  $skipEntries
     */
    private function moveHomeAside(string $asideDir, array $skipEntries, string $label): void
    {
        foreach ($this->list($this->home, "Failed to list target home before {$label}.") as $entry) {
            if (in_array($entry, $skipEntries, true)) {
                continue;
            }
            $this->run(
                Privileged::files()->move($this->home.'/'.$entry, $asideDir.'/'),
                "Failed to move '{$entry}' aside before {$label}",
            );
            $this->movedAside[] = $entry;
        }
    }

    /** The same swap, what it moved kept, its later steps run on $timeout. */
    public function withTimeout(int $timeout): self
    {
        $copy = new self($this->system, $this->home, $timeout);
        $copy->started = $this->started;
        $copy->movedAside = $this->movedAside;
        $copy->movedIn = $this->movedIn;

        return $copy;
    }

    public function hasStarted(): bool
    {
        return $this->started;
    }

    /**
     * Undo what this instance's swap() moved, however far it got: the entries it moved in go to $incomingDir, those it
     * moved aside come back, and nothing else in the home is touched.
     */
    public function undo(string $incomingDir, string $asideDir, string $label): void
    {
        if ($this->movedIn !== []) {
            $this->run(Privileged::files()->makeDirectories($incomingDir), "Failed to recreate the incoming directory for {$label} undo");
        }

        foreach ($this->movedIn as $index => $entry) {
            $this->run(
                Privileged::files()->move($this->home.'/'.$entry, $incomingDir.'/'),
                "Failed to move incoming '{$entry}' out during {$label} undo",
            );
            unset($this->movedIn[$index]);
        }

        foreach ($this->movedAside as $index => $entry) {
            $this->run(
                Privileged::files()->move($asideDir.'/'.$entry, $this->home.'/'),
                "Failed to move '{$entry}' back during {$label} undo",
            );
            unset($this->movedAside[$index]);
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
        $process = $this->system->runProcess(Privileged::files()->listAllNames($dir), [], $this->timeout);
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
            ->runProcess(Privileged::files()->isDirectory($dir), [], $this->timeout)
            ->isSuccessful();
    }
}
