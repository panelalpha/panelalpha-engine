<?php

namespace App\System\Project\Dind\Generation;

use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\ProjectBindMounts;
use Illuminate\Support\Facades\Log;

/**
 * The checkout a running app was deployed from, run files included, moved
 * aside while a redeploy prepares the new one in its place. A container that
 * bind-mounts ~/project keeps the directory, not the path, so it serves on.
 * Afterwards it is removed, or put back if the deploy failed and its
 * containers still run: a failed redeploy leaves the project as it was.
 */
final class CheckoutAside
{
    public const DIR = '.project-prev';

    private const FAILED_DIR = '.project-failed';

    private const COPY_TIMEOUT_SECONDS = 1800;

    private const INSPECT_TIMEOUT_SECONDS = 30;

    public function __construct(private readonly DindProject $project)
    {
    }

    public function asidePath(): string
    {
        return rtrim($this->project->homeDirPath(), '/') . '/' . self::DIR;
    }

    /**
     * $copyBack when the deploy builds on the files already there; $binds when
     * a container reads the checkout through a bind mount. False when it
     * could not, and the caller goes on as before.
     *
     * @param list<string> $containers the project's running containers
     */
    public function moveAside(array $containers, bool $copyBack, bool $binds = true): bool
    {
        $this->settle();
        $state = new GenerationState($this->project->username());
        if ($state->get(GenerationState::CHECKOUT) !== null) {
            return false;
        }

        $system = $this->project->system();
        $projectDir = $this->project->userAppDirPath();
        $aside = $this->asidePath();
        try {
            $system->exec(['sudo', 'rm', '-rf', $aside], [], 300);
            $system->exec(['sudo', 'mv', '-T', $projectDir, $aside], [], 120);
        } catch (\Throwable $e) {
            Log::warning("Could not move the checkout of {$this->project->username()} aside: " . $e->getMessage());

            return false;
        }
        $state->put(GenerationState::CHECKOUT, [
            'path' => $aside,
            'containers' => array_values($containers),
            'binds' => $binds,
            'owner' => GenerationState::owner(),
        ]);

        if (!$copyBack) {
            return true;
        }
        try {
            $system->exec(['sudo', 'cp', '-a', '-T', $aside, $projectDir], [], self::COPY_TIMEOUT_SECONDS);
        } catch (\Throwable $e) {
            Log::warning("Could not copy the checkout of {$this->project->username()} back: " . $e->getMessage());
            $this->putBack();
            $state->forget(GenerationState::CHECKOUT);

            return false;
        }

        return true;
    }

    /** Moved aside earlier in this same process: before a pull it then redeploys. */
    public function keptByThisProcess(): bool
    {
        $entry = (new GenerationState($this->project->username()))->get(GenerationState::CHECKOUT);

        return ($entry['owner']['pid'] ?? null) === (int) getmypid();
    }

    /**
     * Before the checkout changes under a running app (a pull, a revert, a
     * push): the tree it was deployed from stays aside, the change lands in a
     * copy. What the app mounts (maybe nothing), or null when nothing was moved.
     *
     * @return ?list<string>
     */
    public function keepForRunningApp(): ?array
    {
        if ($this->keptByThisProcess() || $this->project->app() === null) {
            return null;
        }
        $read = (new ProjectBindMounts($this->project))->read();
        if ($read === null || $read['running'] === []) {
            return null;
        }

        return $this->moveAside($read['running'], copyBack: true, binds: $read['mounts'] !== []) ? $read['mounts'] : null;
    }

    /** A checkout moved aside that running containers bind-mount: they are recreated onto the new one. */
    public static function bindsMovedAside(string $username): bool
    {
        $entry = (new GenerationState($username))->get(GenerationState::CHECKOUT);

        return $entry !== null && ($entry['binds'] ?? true) === true;
    }

    /**
     * Removed after a deploy that succeeded; after one that failed or died,
     * put back while every container it was deployed for still runs.
     */
    public static function outcome(?bool $succeeded, bool $allStillRunning): string
    {
        return $succeeded !== true && $allStillRunning ? 'restored' : 'removed';
    }

    /**
     * 'restored' or 'removed'; null when nothing was aside or it is left for
     * the sweep. $succeeded is the deploy's verdict, null when it is not known.
     */
    public function settle(?bool $succeeded = null): ?string
    {
        $state = new GenerationState($this->project->username());
        $aside = $state->get(GenerationState::CHECKOUT);
        if ($aside === null) {
            return null;
        }

        $running = $succeeded === true
            ? false
            : self::allRunning($this->project, array_values(array_filter((array) ($aside['containers'] ?? []), 'is_string')));
        if ($running === null) {
            return null;
        }

        $outcome = self::outcome($succeeded, $running);
        try {
            if ($outcome === 'restored') {
                $this->putBack();
            } else {
                $this->project->system()->exec(['sudo', 'rm', '-rf', $this->asidePath()], [], 600);
            }
        } catch (\Throwable $e) {
            Log::warning("Could not settle the checkout of {$this->project->username()}: " . $e->getMessage());

            return null;
        }
        $state->forget(GenerationState::CHECKOUT);

        return $outcome;
    }

    /** The tree a running app was deployed from is still aside, to start the previous version from. */
    public function restorable(): bool
    {
        return (new GenerationState($this->project->username()))->get(GenerationState::CHECKOUT) !== null
            && $this->project->system()->filesystem()->directoryExists($this->asidePath());
    }

    /** Back in place whatever runs now: the previous version starts from it again. */
    public function bringBack(): bool
    {
        try {
            $this->putBack();
        } catch (\Throwable $e) {
            Log::warning("Could not put the previous checkout of {$this->project->username()} back: " . $e->getMessage());

            return false;
        }
        (new GenerationState($this->project->username()))->forget(GenerationState::CHECKOUT);

        return true;
    }

    /** The old tree back where its containers' configuration says it is. */
    private function putBack(): void
    {
        $system = $this->project->system();
        $projectDir = $this->project->userAppDirPath();
        $failed = rtrim($this->project->homeDirPath(), '/') . '/' . self::FAILED_DIR;
        $system->exec(['sudo', 'rm', '-rf', $failed], [], 600);
        if ($system->filesystem()->directoryExists($projectDir)) {
            $system->exec(['sudo', 'mv', '-T', $projectDir, $failed], [], 120);
        }
        $system->exec(['sudo', 'mv', '-T', $this->asidePath(), $projectDir], [], 120);
        $system->exec(['sudo', 'rm', '-rf', $failed], [], 600);
    }

    /**
     * @param list<string> $ids
     */
    public static function allRunning(DindProject $project, array $ids): ?bool
    {
        if ($ids === []) {
            return false;
        }
        $script = 'for id in "$@"; do docker inspect --format "{{.State.Running}}" "$id" 2>/dev/null || echo gone; done';
        try {
            $out = $project->shell()->execQuiet(['bash', '-c', $script, 'running', ...$ids], [], self::INSPECT_TIMEOUT_SECONDS);
        } catch (\Throwable) {
            return null;
        }

        return self::allTrue($out, count($ids));
    }

    public static function allTrue(string $inspect, int $expected): bool
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', trim($inspect)) ?: []), static fn (string $l): bool => $l !== ''));

        return count($lines) === $expected && array_unique($lines) === ['true'];
    }
}
