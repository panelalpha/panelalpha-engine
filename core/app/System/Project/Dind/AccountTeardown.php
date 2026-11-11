<?php

namespace App\System\Project\Dind;

use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Dind\DindAccountCleanup;
use App\System\Privileged;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\Generation\CancelledGeneration;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\RoutingSnapshot;
use Illuminate\Support\Facades\Log;

/**
 * Inner application teardown and full account inner Docker cleanup.
 *
 * Every step is best-effort and logged rather than thrown — same contract as Lib.
 */
final class AccountTeardown
{
    public function __construct(
        private DindProject $project,
    ) {}

    public function delete(): void
    {
        $username = $this->project->username();
        $sidecarRefs = $this->hostSidecarRefsToRemove($username);

        $this->abortRunningDeploy(false, removeVolumes: true);
        $this->tryDaemonStep('system prune', static fn (AccountDaemon $daemon) => $daemon->pruneSystem(true, 300));
        $this->wipeInnerDockerStore($username);

        $this->purgeHostDockerCache($sidecarRefs);
    }

    /**
     * Host sidecar images only this account uses; none when the other accounts could not be scanned.
     *
     * @return list<string>
     */
    private function hostSidecarRefsToRemove(string $username): array
    {
        $neighbourRefs = $this->otherAccountImageRefs($username);

        return $neighbourRefs === null ? [] : DindAccountCleanup::hostSidecarRefsToRemove(
            $this->accountImageRefsForHostCleanup(),
            array_merge($this->hostContainerImageRefs(), $neighbourRefs)
        );
    }

    /**
     * Stops dockerd through the account's init, then removes its store. A stopped account
     * has no dockerd to reach, and its store goes with the home.
     */
    private function wipeInnerDockerStore(string $username): void
    {
        try {
            if ($this->project->isRunning()) {
                $this->project->innerDocker()->wipeDataRoot();
            }
        } catch (\Exception $e) {
            Log::warning(
                "Could not wipe inner Docker data-root for {$username}: ".$e->getMessage(),
            );
        }
    }

    /**
     * A cancelled deploy. A first deploy has its half-started stack taken down; a redeploy loses only the
     * containers it created, and what it moved is put back by its settle or the sweep. The account's dockerd stays.
     */
    public function cancelRunningDeploy(): void
    {
        if (! $this->isRedeploy()) {
            $this->abortRunningDeploy(stopInnerDocker: false);

            return;
        }
        (new CancelledGeneration($this->project))->remove();
        if ($this->stoppedWhenTheRedeployStarted()) {
            $this->stopRestartedApp();
        }
    }

    /** The app ran nothing when this redeploy started: whatever it started goes back to stopped. */
    private function stoppedWhenTheRedeployStarted(): bool
    {
        return (new GenerationState($this->project->username()))->get(GenerationState::STOPPED) !== null;
    }

    private function stopRestartedApp(): void
    {
        $this->tryStep($this->project->userAppComposeCommand(['stop']), 120, true);
    }

    /** Decided from the project, not only the routing snapshot, which a redeploy takes a while after it started. */
    private function isRedeploy(): bool
    {
        return $this->project->userModel()->deploysAsUpgrade()
            || (new RoutingSnapshot($this->project))->entry() !== null;
    }

    /**
     * Volumes are removed only on request: a failed or cancelled redeploy of a
     * live project must not take its named volumes (database data) with it.
     */
    public function abortRunningDeploy(bool $stopInnerDocker = true, bool $removeVolumes = false): void
    {
        $down = $removeVolumes ? ['down', '-v', '--remove-orphans'] : ['down', '--remove-orphans'];
        $this->tryStep($this->project->userAppComposeCommand($down), 120, true);
        $this->tryDaemonStep('build cache prune', static fn (AccountDaemon $daemon) => $daemon->pruneBuildCache(120));
        if ($stopInnerDocker) {
            $this->tryStep($this->project->engine()->storage()->stopEngineArgv(), 60, false);
        }
    }

    /**
     * @return list<string>
     */
    private function accountImageRefsForHostCleanup(): array
    {
        $refs = array_merge($this->composeFileImageRefs(), $this->innerDaemonImageRefs());

        return array_values(array_unique($refs));
    }

    /**
     * Images named by the run file and the client's own compose file.
     *
     * @return list<string>
     */
    private function composeFileImageRefs(): array
    {
        $refs = [];
        $system = $this->project->system();
        $filesystem = $system->filesystem();
        $paths = array_unique(array_filter([
            $this->project->userAppComposeFilePath(),
            $this->project->userAppExistingComposeFilePath(),
        ]));
        foreach ($paths as $path) {
            if (! $filesystem->fileExists($path)) {
                continue;
            }
            $contents = $filesystem->fileGetContents($path);
            if ($contents === '') {
                continue;
            }
            foreach (DeployCompose::imageRefs($contents, 32) as $image) {
                $refs[] = $image;
            }
        }

        return $refs;
    }

    /**
     * Images the inner daemon holds; none when it is already down, where the compose refs are enough.
     *
     * @return list<string>
     */
    private function innerDaemonImageRefs(): array
    {
        try {
            $tags = $this->project->accountDaemon()->imageTags();
        } catch (\Exception) {
            return [];
        }

        return DindAccountCleanup::parseImageRefLines(implode("\n", $tags));
    }

    /**
     * @return list<string>
     */
    private function hostContainerImageRefs(): array
    {
        try {
            $out = $this->project->system()->exec(
                Privileged::docker()->containerImages(),
                [],
                30
            );
        } catch (\Exception) {
            return [];
        }

        return DindAccountCleanup::parseImageRefLines((string) $out);
    }

    /**
     * @return list<string>|null
     */
    private function otherAccountImageRefs(string $username): ?array
    {
        $system = $this->project->system();
        try {
            $out = $system->exec(
                DindAccountCleanup::otherAccountComposeImagesArgv(
                    $system->homesDirPath(),
                    $username
                ),
                [],
                60
            );
        } catch (\Exception $e) {
            Log::warning(
                "Could not scan sibling compose files before deleting {$username}: ".$e->getMessage(),
            );

            return null;
        }

        return DindAccountCleanup::parseComposeImageLines((string) $out);
    }

    /**
     * @param  list<string>  $sidecarRefs
     */
    private function purgeHostDockerCache(array $sidecarRefs = []): void
    {
        $username = $this->project->username();

        try {
            $commands = $this->project->engine()->storage()->hostCleanupArgvs(
                $this->project->engineAccount(),
                $sidecarRefs
            );
        } catch (\Exception $e) {
            $this->reportHostDockerCacheFailure($username, $e);

            return;
        }

        foreach ($commands as $command) {
            try {
                $this->project->system()->exec($command, [], 300);
            } catch (\Exception $e) {
                $this->reportHostDockerCacheFailure($username, $e);
            }
        }
    }

    private function reportHostDockerCacheFailure(string $username, \Exception $e): void
    {
        Log::warning(
            "Could not purge host Docker cache for {$username}: ".$e->getMessage(),
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function tryStep(array $command, int $timeout = 300, bool $asUser = false): void
    {
        try {
            if ($asUser) {
                $this->project->shell()->runProcessAsUser($command, [], $timeout);
            } else {
                $this->project->shell()->runProcess($command, [], $timeout);
            }
        } catch (\Exception $e) {
            Log::warning(
                "Dind delete step failed for {$this->project->username()}: ".$e->getMessage(),
                ['command' => $command],
            );
        }
    }

    /**
     * {@see tryStep()} for a request to the account's dockerd: the daemon refusing it, a stopped account included,
     * is quiet as a failed command's exit is.
     *
     * @param  \Closure(AccountDaemon): void  $request
     */
    private function tryDaemonStep(string $step, \Closure $request): void
    {
        try {
            $request($this->project->accountDaemon());
        } catch (DockerErrorException) {
        } catch (\Exception $e) {
            Log::warning(
                "Dind delete step failed for {$this->project->username()}: ".$e->getMessage(),
                ['step' => $step],
            );
        }
    }
}
