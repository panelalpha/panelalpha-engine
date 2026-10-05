<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Dind\DindAccountCleanup;
use App\System\Project\Dind as DindProject;
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
    ) {
    }

    public function delete(): void
    {
        $username = $this->project->username();
        $neighbourRefs = $this->otherAccountImageRefs($username);
        $sidecarRefs = $neighbourRefs === null ? [] : DindAccountCleanup::hostSidecarRefsToRemove(
            $this->accountImageRefsForHostCleanup(),
            array_merge($this->hostContainerImageRefs(), $neighbourRefs)
        );

        $storage = $this->project->engine()->storage();
        $this->abortRunningDeploy(false, removeVolumes: true);
        $this->tryStep($storage->pruneAllArgv(), 300, true);

        // Stops dockerd through the account's init, then removes its store.
        try {
            $this->project->innerDocker()->wipeDataRoot();
        } catch (\Exception $e) {
            Log::warning(
                "Could not wipe inner Docker data-root for {$username}: " . $e->getMessage(),
            );
        }

        $this->purgeHostDockerCache($sidecarRefs);
    }

    /**
     * Volumes are removed only on request: a failed or cancelled redeploy of a
     * live project must not take its named volumes (database data) with it.
     */
    public function abortRunningDeploy(bool $stopInnerDocker = true, bool $removeVolumes = false): void
    {
        $storage = $this->project->engine()->storage();
        $down = $removeVolumes ? ['down', '-v', '--remove-orphans'] : ['down', '--remove-orphans'];
        $this->tryStep($this->project->userAppComposeCommand($down), 120, true);
        foreach ($storage->pruneBuildCacheArgv() as $prune) {
            $this->tryStep($prune, 120, true);
        }
        if ($stopInnerDocker) {
            $this->tryStep($storage->stopEngineArgv(), 60, false);
        }
    }

    /**
     * @return list<string>
     */
    private function accountImageRefsForHostCleanup(): array
    {
        $refs = [];
        $system = $this->project->system();
        $filesystem = $system->filesystem();
        $paths = array_unique(array_filter([
            $this->project->userAppComposeFilePath(),
            $this->project->userAppExistingComposeFilePath(),
        ]));
        foreach ($paths as $path) {
            if (!$filesystem->fileExists($path)) {
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

        try {
            $out = $this->project->shell()->execAsUserQuiet(
                $this->project->engine()->images()->listImagesArgv(),
                [],
                60
            );
            $refs = array_merge($refs, DindAccountCleanup::parseImageRefLines((string) $out));
        } catch (\Exception) {
            // Compose refs are enough when the inner daemon is already down.
        }

        return array_values(array_unique($refs));
    }

    /**
     * @return list<string>
     */
    private function hostContainerImageRefs(): array
    {
        try {
            $out = $this->project->system()->exec(
                ['sudo', 'docker', 'ps', '-a', '--format', '{{.Image}}'],
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
                "Could not scan sibling compose files before deleting {$username}: " . $e->getMessage(),
            );

            return null;
        }

        return DindAccountCleanup::parseComposeImageLines((string) $out);
    }

    /**
     * @param list<string> $sidecarRefs
     */
    private function purgeHostDockerCache(array $sidecarRefs = []): void
    {
        $username = $this->project->username();

        try {
            $this->project->system()->exec(
                $this->project->engine()->storage()->hostCleanupArgv(
                    $this->project->engineAccount(),
                    $sidecarRefs
                ),
                [],
                300
            );
        } catch (\Exception $e) {
            Log::warning(
                "Could not purge host Docker cache for {$username}: " . $e->getMessage(),
            );
        }
    }

    /**
     * @param list<string> $command
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
                "Dind delete step failed for {$this->project->username()}: " . $e->getMessage(),
                ['command' => $command],
            );
        }
    }
}
