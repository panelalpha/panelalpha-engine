<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\DeployLog\DeployLock;
use App\System;
use App\System\Privileged;
use App\System\Project\Dind as DindProject;
use Illuminate\Support\Facades\Log;

/**
 * Whether sysbox still maps the account container: restarted under a running account, sysbox leaves its files owned
 * by nobody on the next start and fails its mounts with "function not implemented" until it is recreated.
 */
final class AccountMapping
{
    private const RECREATED = 'Recreated the account container';

    private const SYSBOX_RESTARTED = 'sysbox was restarted while it ran';

    private const MANAGER = 'sysbox-mgr';

    private const FILESYSTEM = 'sysbox-fs';

    private const RUNNING = 'active';

    private const PROBE_TIMEOUT_SECONDS = 30;

    private const EXEC_REFUSED = 'unsafe procfs detected';

    /** Starting a container sysbox forgot fails on its own image, e.g. "open etc/docker/daemon.json: permission denied". */
    private const START_REFUSED = '/OCI runtime create failed:.*\b(?:open|lstat|stat|mkdir) \S+: permission denied/s';

    /** What the last probe saw that made the image count as unmapped. */
    private ?string $seen = null;

    /**
     * A sweep hands every account the daemons' start times it read once ({@see self::probeSysbox()}); without
     * them each check probes the host.
     *
     * @param  array{'sysbox-mgr': ?int, 'sysbox-fs': ?int}|null  $sysboxStarts
     */
    public function __construct(
        private DindProject $project,
        private ?array $sysboxStarts = null,
    ) {}

    /** The account is recreated because its container's start was refused, not because a probe found it unmapped. */
    public function afterRefusedStart(): self
    {
        $this->seen = 'its start was refused with permission denied on its own image';

        return $this;
    }

    /**
     * True when /root is not root's, exec is refused across a sysbox restart, or the container started before
     * sysbox-fs last did; null when the account could not be asked, e.g. down or booting.
     *
     * @param  ?string  $startedAt  when the container started, as docker inspect prints it, if the caller listed it
     *                              just before this check; a later check inspects it, as the container may be new
     */
    public function isUnmapped(?string $startedAt = null): ?bool
    {
        try {
            $owner = trim($this->project->shell()->execQuiet(['stat', '-c', '%u', '/root'], [], self::PROBE_TIMEOUT_SECONDS));
        } catch (\Throwable $e) {
            return $this->refusedAcrossSysboxRestart($e->getMessage(), $startedAt) ? $this->saw('exec into it was refused (unsafe procfs detected)') : null;
        }
        if (! ctype_digit($owner)) {
            return null;
        }
        if ($owner !== '0') {
            return $this->saw("/root inside it was owned by uid {$owner} instead of root");
        }

        return $this->startedBeforeSysboxFs($startedAt) ? $this->saw('sysbox-fs no longer serves its mounts') : false;
    }

    private function saw(string $what): bool
    {
        $this->seen = $what;

        return true;
    }

    /** What the last check saw that made the account count as unmapped. */
    public function reason(): string
    {
        return $this->seen ?? 'its image was unmapped';
    }

    /**
     * Only a container started before sysbox last started can have been forgotten by it. One started since
     * that refuses exec for another reason, which recreating it again would not fix. Unknown times of the
     * container or sysbox-mgr count as before.
     */
    private function refusedAcrossSysboxRestart(string $error, ?string $startedAt): bool
    {
        if (! str_contains($error, self::EXEC_REFUSED)) {
            return false;
        }
        $started = $this->startTimes($startedAt);

        return $started['container'] === null || $started[self::MANAGER] === null || self::startedBefore($started, self::MANAGER, self::FILESYSTEM);
    }

    private function startedBeforeSysbox(): bool
    {
        return self::startedBefore($this->startTimes(), self::MANAGER, self::FILESYSTEM);
    }

    /** Recreates a container that may be serving, so only on clear evidence: both times known, the container's strictly earlier. */
    private function startedBeforeSysboxFs(?string $startedAt): bool
    {
        return self::startedBefore($this->startTimes($startedAt), self::FILESYSTEM);
    }

    /** @param  array{container: ?int, 'sysbox-mgr': ?int, 'sysbox-fs': ?int}  $started */
    private static function startedBefore(array $started, string ...$daemons): bool
    {
        foreach ($daemons as $daemon) {
            if ($started['container'] !== null && $started[$daemon] !== null && $started['container'] < $started[$daemon]) {
                return true;
            }
        }

        return false;
    }

    /**
     * The container's start and both daemons', from one look at each; the container's is inspected unless the
     * caller listed it.
     *
     * @return array{container: ?int, 'sysbox-mgr': ?int, 'sysbox-fs': ?int}
     */
    private function startTimes(?string $startedAt = null): array
    {
        return [
            'container' => $startedAt === null ? $this->containerStartedAt() : self::dockerTime($startedAt),
            ...$this->sysboxStartTimes(),
        ];
    }

    private function containerStartedAt(): ?int
    {
        $inspect = $this->project->system()->runProcess(
            Privileged::docker()->startedAt($this->project->username()),
            [],
            self::PROBE_TIMEOUT_SECONDS
        );

        return $inspect->isSuccessful() ? self::dockerTime($inspect->getOutput()) : null;
    }

    /** Docker's start time without its fraction of a second. */
    private static function dockerTime(string $value): ?int
    {
        return self::timestamp(preg_replace('/\.\d+/', '', $value) ?? '');
    }

    /** @return array{'sysbox-mgr': ?int, 'sysbox-fs': ?int} */
    private function sysboxStartTimes(): array
    {
        return $this->sysboxStarts ?? self::probeSysbox($this->project->system());
    }

    /**
     * When sysbox-mgr and sysbox-fs last started, from one host probe; both null when the host did not answer.
     * sysbox-fs's start counts only while both run, as a stopped one keeps the time it last started.
     *
     * @return array{'sysbox-mgr': ?int, 'sysbox-fs': ?int}
     */
    public static function probeSysbox(System $system): array
    {
        $daemons = (new SysboxDaemons($system))->loaded();
        $serving = ($daemons[self::MANAGER]['state'] ?? '') === self::RUNNING
            && ($daemons[self::FILESYSTEM]['state'] ?? '') === self::RUNNING;

        return [
            self::MANAGER => self::timestamp($daemons[self::MANAGER]['since'] ?? ''),
            self::FILESYSTEM => $serving ? self::timestamp($daemons[self::FILESYSTEM]['since'] ?? '') : null,
        ];
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
     * Recreate the account container when its image is unmapped; true when it was. Under the account's deploy
     * lock, so a project action and the mapping sweep cannot recreate the same container at once.
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
        $recreated = self::RECREATED.': '.$this->cause();
        (new OuterLifecycle($this->project))->recreate();
        $this->project->awaitReady();
        if ($this->isUnmapped() !== false) {
            throw new \RuntimeException(
                "The account container of '{$this->project->username()}' still has its image unmapped after it was recreated; "
                .'check that sysbox runs on the host (systemctl status sysbox).'
            );
        }
        Log::warning($this->project->username().': '.$recreated);
        $this->project->shell()->logger()?->warn($recreated);
    }

    /**
     * What was seen, and a sysbox restart only when the container provably started before sysbox did:
     * read before the recreate, which gives the container a new start time.
     */
    private function cause(): string
    {
        $seen = $this->reason();

        return $this->startedBeforeSysbox() ? "{$seen}; ".self::SYSBOX_RESTARTED : $seen;
    }
}
