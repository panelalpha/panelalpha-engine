<?php

namespace App\System\Project\Backup;

use App\System\Project as UserProject;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Runs a capture or a restore with the account's stack stopped and a staging directory in place. */
final class StoppedStack
{
    public function __construct(
        private UserProject $project,
        private AppStack $stack,
        private StagingDirectory $staging,
    ) {}

    /**
     * Stop compose, create scratch dir, run $work, always clean scratch.
     * On failure: best-effort start when stopped and $shouldRestartOnFailure allows it, then rethrow.
     *
     * @param  callable(string): void  $work
     * @param  (callable(): bool)|null  $shouldRestartOnFailure  null = always restart if stopped
     */
    public function run(
        int $backupId,
        callable $work,
        ?callable $shouldRestartOnFailure = null,
    ): void {
        $stopRan = false;
        $stagingDir = null;
        $username = $this->project->username();

        try {
            $this->stack->stop();
            $stopRan = true;

            $stagingDir = $this->staging->path($username, $backupId);
            $this->staging->ensure($stagingDir);

            $work($stagingDir);
        } catch (Throwable $e) {
            $restart = $shouldRestartOnFailure === null || $shouldRestartOnFailure();
            if ($stopRan && $restart) {
                $this->tryStart($backupId);
            }

            throw $e;
        } finally {
            if ($stagingDir !== null) {
                $this->staging->clean($stagingDir);
            }
        }
    }

    public function tryStart(int $backupId): void
    {
        try {
            $this->stack->start();
        } catch (Throwable $e) {
            Log::warning('Backup composeStart failed', [
                'username' => $this->project->username(),
                'backup_id' => $backupId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
