<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind as DindProject;
use Illuminate\Support\Facades\Log;

/**
 * Whether sysbox still maps the account container's image. sysbox shifts it
 * on every start and back at stop, but only for containers sysbox-mgr still
 * knows: restarted while the account ran, it forgets it, and the next start
 * of that same container mounts the image unshifted, every file owned by
 * nobody. Only recreating the container maps it again.
 */
final class AccountMapping
{
    public const RECREATED = 'Recreated the account container: sysbox no longer mapped its image (sysbox was restarted while it ran)';

    private const PROBE_TIMEOUT_SECONDS = 30;

    private const EXEC_REFUSED = 'unsafe procfs detected';

    public function __construct(
        private DindProject $project,
    ) {
    }

    /** True when /root is not root's; null when the account could not be asked, e.g. down or booting. */
    public function isUnmapped(): ?bool
    {
        try {
            $owner = trim($this->project->shell()->execQuiet(['stat', '-c', '%u', '/root'], [], self::PROBE_TIMEOUT_SECONDS));
        } catch (\Throwable $e) {
            // An account still running across the sysbox restart refuses every exec this way; its next start comes up unmapped.
            return str_contains($e->getMessage(), self::EXEC_REFUSED) ? true : null;
        }

        return ctype_digit($owner) ? $owner !== '0' : null;
    }

    /** Recreate the account container when its image is unmapped; true when it was. */
    public function ensureMapped(): bool
    {
        if (!AccountRuntime::isSysbox() || $this->isUnmapped() !== true) {
            return false;
        }
        $this->recreate();

        return true;
    }

    /** The home and ~/docker are bind mounts, so the account keeps its data. */
    public function recreate(): void
    {
        (new OuterLifecycle($this->project))->recreate();
        $this->project->awaitReady();
        if ($this->isUnmapped() === true) {
            throw new \RuntimeException(
                "The account container of '{$this->project->username()}' still has its image unmapped after it was recreated; "
                . 'check that sysbox runs on the host (systemctl status sysbox).'
            );
        }
        Log::warning($this->project->username() . ': ' . self::RECREATED);
        $this->project->shell()->logger()?->warn(self::RECREATED);
    }
}
