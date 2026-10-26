<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\DeployLog\DeployLock;
use App\System\Project\Dind as DindProject;
use Illuminate\Support\Facades\Log;

/**
 * Whether sysbox still maps the account container's image: restarted while the account ran,
 * sysbox-mgr forgets the container and its next start leaves every file owned by nobody until it is recreated.
 */
final class AccountMapping
{
    public const RECREATED = 'Recreated the account container: sysbox no longer mapped its image (sysbox was restarted while it ran)';

    private const PROBE_TIMEOUT_SECONDS = 30;

    private const EXEC_REFUSED = 'unsafe procfs detected';

    /** The sysbox daemon that registers containers; restarting it is what leaves running ones unmapped. */
    private const SYSBOX_MANAGER_UNIT = 'sysbox-mgr';

    /** Starting a container sysbox forgot fails on its own image, e.g. "open etc/docker/daemon.json: permission denied". */
    private const START_REFUSED = '/OCI runtime create failed:.*\b(?:open|lstat|stat|mkdir) \S+: permission denied/s';

    public function __construct(
        private DindProject $project,
    ) {}

    /**
     * True when /root is not root's, or when exec is refused as by an account still running across a sysbox
     * restart (its next start comes up unmapped); null when the account could not be asked, e.g. down or booting.
     */
    public function isUnmapped(): ?bool
    {
        try {
            $owner = trim($this->project->shell()->execQuiet(['stat', '-c', '%u', '/root'], [], self::PROBE_TIMEOUT_SECONDS));
        } catch (\Throwable $e) {
            return $this->refusedAcrossSysboxRestart($e->getMessage()) ? true : null;
        }

        return ctype_digit($owner) ? $owner !== '0' : null;
    }

    /**
     * Only a container started before sysbox last started can have been forgotten by it. One started since
     * that refuses exec for another reason, which recreating it again would not fix. Unknown times count as before.
     */
    private function refusedAcrossSysboxRestart(string $error): bool
    {
        if (! str_contains($error, self::EXEC_REFUSED)) {
            return false;
        }
        $containerStarted = $this->containerStartedAt();
        $sysboxStarted = $this->sysboxStartedAt();

        return $containerStarted === null || $sysboxStarted === null || $containerStarted < $sysboxStarted;
    }

    private function containerStartedAt(): ?int
    {
        $inspect = $this->project->system()->runProcess(
            ['sudo', 'docker', 'inspect', '--format', '{{.State.StartedAt}}', $this->project->username()],
            [],
            self::PROBE_TIMEOUT_SECONDS
        );

        return $inspect->isSuccessful() ? self::timestamp(preg_replace('/\.\d+/', '', $inspect->getOutput()) ?? '') : null;
    }

    private function sysboxStartedAt(): ?int
    {
        $show = $this->project->system()->runProcessOnHost(
            ['systemctl', 'show', self::SYSBOX_MANAGER_UNIT, '--property=ActiveEnterTimestamp', '--value'],
            [],
            self::PROBE_TIMEOUT_SECONDS
        );

        return $show->isSuccessful() ? self::timestamp($show->getOutput()) : null;
    }

    private static function timestamp(string $value): ?int
    {
        $time = trim($value) === '' ? false : strtotime(trim($value));

        return $time === false ? null : $time;
    }

    /** The error of a start that failed because sysbox no longer maps the container's image. */
    public static function isStartRefusal(string $error): bool
    {
        return preg_match(self::START_REFUSED, $error) === 1;
    }

    /**
     * Recreate the account container when its image is unmapped; true when it was.
     * The repair runs under the account's deploy lock, so a project action and the
     * mapping sweep cannot recreate the same container at once.
     */
    public function ensureMapped(): bool
    {
        if (! AccountRuntime::isSysbox() || $this->isUnmapped() !== true) {
            return false;
        }
        if (DeployLock::heldByThisProcess($this->project->username())) {
            $this->recreate();

            return true;
        }

        return $this->recreateUnderLock();
    }

    /** The image is probed again under the lock: another caller may have repaired it meanwhile. */
    private function recreateUnderLock(): bool
    {
        try {
            $lock = DeployLock::acquireFor($this->project->username());
        } catch (\Throwable) {
            return false;
        }
        try {
            if ($this->isUnmapped() !== true) {
                return false;
            }
            $this->recreate();

            return true;
        } finally {
            $lock->release();
        }
    }

    /** The home and ~/docker are bind mounts, so the account keeps its data. */
    public function recreate(): void
    {
        (new OuterLifecycle($this->project))->recreate();
        $this->project->awaitReady();
        if ($this->isUnmapped() !== false) {
            throw new \RuntimeException(
                "The account container of '{$this->project->username()}' still has its image unmapped after it was recreated; "
                .'check that sysbox runs on the host (systemctl status sysbox).'
            );
        }
        Log::warning($this->project->username().': '.self::RECREATED);
        $this->project->shell()->logger()?->warn(self::RECREATED);
    }
}
