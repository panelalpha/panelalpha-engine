<?php

namespace App\System;

use App\Lib\Deploy\DetectAppPort;
use App\Models\User as ModelsUser;
use App\System as EngineSystem;
use App\System\Project\Dind;
use App\System\Project\Dind\Paths;

class Projects
{
    private const int PROCESS_TIMEOUT_SECONDS = 86400;

    public function __construct(
        protected EngineSystem $system,
    ) {
    }

    public function clone(string $sourceUsername, string $targetUsername): void
    {
        $source = ModelsUser::findByUsernameOrFail($sourceUsername);
        $target = ModelsUser::findByUsernameOrFail($targetUsername);
        $this->cloneOrCopy($source, $target, asStaging: false);
    }

    public function copy(string $sourceUsername, string $targetUsername): void
    {
        $source = ModelsUser::findByUsernameOrFail($sourceUsername);
        $target = ModelsUser::findByUsernameOrFail($targetUsername);
        $this->cloneOrCopy($source, $target, asStaging: true);
    }

    public function pushToApp(string $sourceUsername, string $targetUsername): void
    {
        $from = ModelsUser::findByUsernameOrFail($sourceUsername);
        $to = ModelsUser::findByUsernameOrFail($targetUsername);
        self::assertCanPush($from, $to);

        $to->applyDeploySnapshotFrom($from);
        $to->save();

        $fromProject = $this->system->project($from);
        $toProject = $this->system->project($to);
        $fromHome = rtrim($fromProject->homeDirPath(), '/');
        $toHome = rtrim($toProject->homeDirPath(), '/');
        $incomingDir = $toHome . '/.incoming';
        $prePushDir = $toHome . '/.pre-push';
        $swapped = false;
        $isDindDest = $to->getTemplate() === 'dind';

        try {
            if ($this->pathIsDirectoryAsRoot($prePushDir)) {
                throw new \RuntimeException(
                    'Incomplete previous push (.pre-push present); refuse to continue.'
                );
            }

            if ($this->pathIsDirectoryAsRoot($incomingDir)) {
                $this->runPushProcess(
                    ['sudo', 'rm', '-rf', $incomingDir],
                    'Failed to remove stale .incoming directory'
                );
            }

            $this->assertPushDiskSpace($fromProject, $fromHome, $toHome, $isDindDest);

            $this->pauseAppForCopy($fromProject);
            $this->pauseAppForCopy($toProject);

            $this->runPushProcess(
                ['sudo', 'mkdir', '-p', $incomingDir],
                'Failed to create .incoming directory'
            );

            $rsyncCmd = [
                'sudo', 'rsync', '-a', '--delete',
                '--exclude=.incoming',
                '--exclude=.pre-push',
            ];
            if ($isDindDest) {
                $rsyncCmd[] = '--exclude=docker/';
            }
            $rsyncCmd[] = rtrim($fromHome, '/') . '/';
            $rsyncCmd[] = rtrim($incomingDir, '/') . '/';
            $this->runPushProcess(
                $rsyncCmd,
                'Failed to rsync source home into .incoming'
            );

            $incomingProject = rtrim($incomingDir, '/') . '/project';
            $this->prepareVolumes($toProject, $incomingProject);

            $sourceVolumeDataCount = $this->countVolumeDataDirs($fromProject);
            $copiedVolumes = $toProject->copyVolumeDataToIncoming($fromProject);

            if ($sourceVolumeDataCount > 0 && $copiedVolumes === 0) {
                $this->cleanupIncomingDir($incomingDir);
                $this->resumeBoth($fromProject, $toProject);
                $this->markPushFailed($to, 'Failed to copy DinD volume data from source (0 volumes copied).');
                throw new \RuntimeException(
                    'Failed to copy DinD volume data from source (0 volumes copied).'
                );
            }

            if ($sourceVolumeDataCount > 0 && $copiedVolumes !== $sourceVolumeDataCount) {
                $message = "Failed to copy DinD volume data from source ({$copiedVolumes} of {$sourceVolumeDataCount} volumes copied).";
                $this->cleanupIncomingDir($incomingDir);
                $this->resumeBoth($fromProject, $toProject);
                $this->markPushFailed($to, $message);
                throw new \RuntimeException($message);
            }

            $this->swapIncomingHome($toHome, $incomingDir, $prePushDir);
            $swapped = true;
            $toProject->copyVolumesForClone()?->swapIncomingVolumes();

            $toProject->fixPermissions();

            if (($to->hasGitProject() || $to->getTemplate() === 'dind') && $toProject->hasUserApp()) {
                $start = $toProject->startUserApp();
                if (($start['exit_code'] ?? 1) !== 0) {
                    $output = trim(($start['stderr'] ?? '') . ' ' . ($start['stdout'] ?? ''));
                    $this->resumeBoth($fromProject, $toProject);
                    $this->markPushFailed(
                        $to,
                        $output !== '' ? $output : 'Failed to start target application after push.'
                    );
                    throw new \RuntimeException(
                        $output !== '' ? $output : 'Failed to start target application after push.'
                    );
                }
            } else {
                $toProject->start();
            }

            $this->resumeAppAfterCopy($fromProject);

            $this->cleanupPushSuccess($prePushDir, $incomingDir);
            $toProject->copyVolumesForClone()?->discardVolumeBackups();
            $this->markPushCompleted($to);
        } catch (\Throwable $e) {
            if (!$swapped) {
                $this->cleanupIncomingDir($incomingDir);
                $this->resumeBoth($fromProject, $toProject);
            } else {
                $this->resumeBoth($fromProject, $toProject);
            }

            $details = $to->getDetails();
            $alreadyFailed = ($details['async_status']['push'] ?? null) === 'failed';
            if (!$alreadyFailed) {
                $this->markPushFailed($to, $e->getMessage());
            }

            if ($e instanceof \RuntimeException) {
                throw $e;
            }

            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    private function cloneOrCopy(ModelsUser $source, ModelsUser $dest, bool $asStaging): void
    {
        $sourceProject = $this->system->project($source);
        $destProject = $this->system->project($dest);
        $srcPaused = false;

        try {
            self::assertSameTemplate($source, $dest);
            self::assertNotPending($source);
            if (!$asStaging) {
                self::assertNotPending($dest);
            } else {
                self::assertCanCopyIntoStaging($source, $dest);
            }

            $dest->applyDeploySnapshotFrom($source);
            $dest->save();

            $destProject->createDirectories();
            $osUser = $destProject->createLinuxUser();
            $dest->setDetails([
                'UID' => $osUser['UID'],
                'GID' => $osUser['GID'],
            ]);
            $dest->save();
            $destProject->createFromTemplate();
            $destProject->start();
            $destProject->fixPermissions();
            $destProject->configureQuota();
            $destProject->waitForAllRunning();

            $this->pauseAppForCopy($sourceProject);
            $srcPaused = true;

            try {
                $destProject->copyHomeDirFrom($sourceProject);
                $destProject->copyProjectConfigFrom($sourceProject);
                $this->prepareVolumes($destProject);
                $srcCount = $this->countVolumeDataDirs($sourceProject);
                $copied = $destProject->copyVolumeDataFrom($sourceProject);
                if ($srcCount > 0 && $copied !== $srcCount) {
                    throw new \RuntimeException(
                        "Failed to copy DinD volume data from source ({$copied} of {$srcCount} volumes copied)."
                    );
                }
            } finally {
                if ($srcPaused) {
                    $this->resumeAppAfterCopy($sourceProject);
                    $srcPaused = false;
                }
            }

            $detectedPort = $this->detectAppPort($destProject);
            $srcDetails = $source->getDetails();
            if ($detectedPort !== null) {
                $dest->setAppPort($detectedPort);
                $dest->save();
            } elseif (array_key_exists('app_port', $srcDetails)) {
                $dest->setAppPort(
                    $srcDetails['app_port'] !== null ? (int) $srcDetails['app_port'] : null
                );
                $dest->save();
            }

            $domainModel = $dest->domains()->where('domain', $dest->domain)->first();
            if ($domainModel === null) {
                throw new \RuntimeException('Destination has no domain row.');
            }
            $destProject->syncGeneratedProxyRules();
            $this->system->project($dest)->domain($domainModel)->create();

            if (($dest->hasGitProject() || $dest->getTemplate() === 'dind') && $destProject->hasUserApp()) {
                $result = $destProject->startUserApp();
                if (($result['exit_code'] ?? 1) !== 0) {
                    $output = trim(($result['stderr'] ?? '') . ' ' . ($result['stdout'] ?? ''));
                    throw new \RuntimeException(
                        $output !== '' ? $output : 'Failed to start application.'
                    );
                }
            }

            $dest->markDeploySucceeded();
            $dest->setDetails(['error' => null]);
            if ($asStaging) {
                $dest->status = 'active';
                $dest->mergeAsyncStatus(['staging' => 'completed']);
            }
            $dest->save();
        } catch (\Throwable $e) {
            if ($srcPaused) {
                try {
                    $this->resumeAppAfterCopy($sourceProject);
                } catch (\Throwable $ignored) {
                }
            }
            $this->retainDestinationAfterFailure($dest, $e, $asStaging);
            throw $e;
        }
    }

    private function retainDestinationAfterFailure(ModelsUser $dest, \Throwable $e, bool $asStaging): void
    {
        try {
            $dest->setDetails([
                'error'             => $e->getMessage(),
                'deployment_status' => 'failed',
            ]);
            if ($asStaging) {
                $dest->mergeAsyncStatus(['staging' => 'failed']);
            }
            $dest->save();
        } catch (\Throwable $ignored) {
        }
    }

    public static function templateKey(ModelsUser $user): string
    {
        return $user->getTemplate() ?? '';
    }

    public static function assertSameTemplate(ModelsUser $a, ModelsUser $b): void
    {
        if (self::templateKey($a) === self::templateKey($b)) {
            return;
        }
        $left = $a->getTemplate() ?? 'null';
        $right = $b->getTemplate() ?? 'null';
        throw new \RuntimeException(
            "Projects '{$a->username}' ({$left}) and '{$b->username}' ({$right}) have different templates.",
            422
        );
    }

    public static function assertNotPending(ModelsUser $user): void
    {
        if ($user->status === 'pending') {
            throw new \RuntimeException("Project '{$user->username}' is pending.", 422);
        }
    }

    public static function assertIdle(ModelsUser $user): void
    {
        $async = $user->asyncStatus();
        if (($async['staging'] ?? null) === 'running' || ($async['push'] ?? null) === 'running') {
            throw new \RuntimeException("Project '{$user->username}' is busy.", 409);
        }
    }

    public static function assertCanCreate(ModelsUser $source): void
    {
        $problems = self::createProblems($source);
        if ($problems !== []) {
            throw new \RuntimeException($problems[0]['message'], 422);
        }
    }

    /**
     * Validates a live→pending-staging copy job. Does not check whether the live
     * project already has a staging row (the dest row exists by design).
     */
    public static function assertCanCopyIntoStaging(ModelsUser $source, ModelsUser $dest): void
    {
        self::assertSameTemplate($source, $dest);
        self::assertNotPending($source);
        if ($source->isStaging()) {
            throw new \RuntimeException(
                "Project '{$source->username}' is a staging project and cannot create staging.",
                422
            );
        }
        if ((int) $dest->staging !== (int) $source->id) {
            throw new \RuntimeException(
                "Projects '{$source->username}' and '{$dest->username}' are not a staging pair.",
                422
            );
        }
        if ($dest->status !== 'pending') {
            throw new \RuntimeException("Project '{$dest->username}' is not pending.", 422);
        }
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    public static function createProblems(ModelsUser $source): array
    {
        $problems = [];
        if ($source->status === 'pending') {
            $problems[] = [
                'field'   => 'project',
                'message' => "Project '{$source->username}' is pending.",
            ];
        }
        if ($source->isStaging()) {
            $problems[] = [
                'field'   => 'project',
                'message' => "Project '{$source->username}' is a staging project and cannot create staging.",
            ];
        }
        if ($source->exists && $source->stagingUser()->exists()) {
            $problems[] = [
                'field'   => 'project',
                'message' => 'Project already has a staging.',
            ];
        }

        return $problems;
    }

    public static function assertCanPush(ModelsUser $from, ModelsUser $to): void
    {
        $problems = self::pushProblems($from, $to);
        if ($problems !== []) {
            throw new \RuntimeException($problems[0]['message'], 422);
        }
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    public static function pushProblems(ModelsUser $from, ModelsUser $to): array
    {
        $problems = [];
        if (self::templateKey($from) !== self::templateKey($to)) {
            $left = $from->getTemplate() ?? 'null';
            $right = $to->getTemplate() ?? 'null';
            $problems[] = [
                'field'   => 'target',
                'message' => "Projects '{$from->username}' ({$left}) and '{$to->username}' ({$right}) have different templates.",
            ];
        }
        if ($from->status === 'pending') {
            $problems[] = [
                'field'   => 'project',
                'message' => "Project '{$from->username}' is pending.",
            ];
        }
        if ($to->status === 'pending') {
            $problems[] = [
                'field'   => 'target',
                'message' => "Project '{$to->username}' is pending.",
            ];
        }
        if ((int) $from->id === (int) $to->id) {
            $problems[] = [
                'field'   => 'target',
                'message' => 'Cannot push to the same project.',
            ];
        }
        $paired = (int) $from->staging === (int) $to->id
            || (int) $to->staging === (int) $from->id;
        if (!$paired) {
            $problems[] = [
                'field'   => 'target',
                'message' => "Projects '{$from->username}' and '{$to->username}' are not a staging pair.",
            ];
        }

        return $problems;
    }

    private function pauseAppForCopy(Project $project): void
    {
        $project->copyVolumesForClone()?->pauseForCopy();
    }

    private function resumeAppAfterCopy(Project $project): void
    {
        $project->copyVolumesForClone()?->resumeAfterCopy();
    }

    private function prepareVolumes(Project $project, ?string $composeDir = null): void
    {
        $volumes = $project->copyVolumesForClone();
        if ($volumes === null) {
            return;
        }
        $volumes->prepareVolumes($composeDir);
    }

    private function countVolumeDataDirs(Project $project): int
    {
        return $project->copyVolumesForClone()?->countVolumeDataDirs() ?? 0;
    }

    private function detectAppPort(Project $project): ?int
    {
        $runtime = $project->runtime();
        if (!$runtime instanceof Dind) {
            return null;
        }

        $path = (new Paths($runtime))->composeFileForPorts();
        if (!is_file($path)) {
            return null;
        }

        $ports = DetectAppPort::detectAllPorts($path);
        if (!empty($ports['primary'])) {
            return (int) $ports['primary'];
        }

        return DetectAppPort::detectPrimaryPort($path);
    }

    private function resumeBoth(Project $fromProject, Project $toProject): void
    {
        $this->resumeAppAfterCopy($fromProject);
        $this->resumeAppAfterCopy($toProject);
    }

    private function markPushCompleted(ModelsUser $to): void
    {
        $to->mergeAsyncStatus(['push' => 'completed']);
        $to->setDetails(['error' => null]);
        $to->save();
    }

    private function markPushFailed(ModelsUser $to, string $error): void
    {
        $to->mergeAsyncStatus(['push' => 'failed']);
        $to->setDetails(['error' => $error]);
        $to->save();
    }

    /**
     * @param list<string> $cmd
     */
    private function runPushProcess(array $cmd, string $context): void
    {
        $process = $this->system->runProcess($cmd, [], self::PROCESS_TIMEOUT_SECONDS);

        if ($process->isSuccessful()) {
            return;
        }

        $output = trim($process->getErrorOutput() . ' ' . $process->getOutput());
        throw new \RuntimeException(
            $output !== '' ? "{$context}: {$output}" : $context
        );
    }

    private function pathIsDirectoryAsRoot(string $path): bool
    {
        return $this->system->runProcess(['sudo', 'test', '-d', $path])->isSuccessful();
    }

    private function pathExistsAsRoot(string $path): bool
    {
        return $this->system->runProcess(['sudo', 'test', '-e', $path])->isSuccessful();
    }

    /**
     * @return list<string>
     */
    private function listDockerVolumeEntriesAsRoot(string $volumesDir): array
    {
        $process = $this->system->runProcess(['sudo', 'ls', '-1', $volumesDir]);
        if (!$process->isSuccessful()) {
            return [];
        }

        $entries = [];
        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '' || $entry === 'metadata.db') {
                continue;
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    private function duBytesAsRoot(string $path): int
    {
        if (!$this->pathIsDirectoryAsRoot($path) && !$this->pathExistsAsRoot($path)) {
            return 0;
        }

        $process = $this->system->runProcess(['sudo', 'du', '-sb', $path]);
        if (!$process->isSuccessful()) {
            return 0;
        }

        $line = trim($process->getOutput());
        if ($line === '') {
            return 0;
        }

        $parts = preg_split('/\s+/', $line);

        return isset($parts[0]) ? (int) $parts[0] : 0;
    }

    private function assertPushDiskSpace(
        Project $fromProject,
        string $fromHome,
        string $toHome,
        bool $isDindDest,
    ): void {
        $required = 0;
        $skipHomeEntries = ['.incoming', '.pre-push'];
        if ($isDindDest) {
            $skipHomeEntries[] = 'docker';
        }

        $listProcess = $this->system->runProcess(['sudo', 'ls', '-A', $fromHome]);
        if ($listProcess->isSuccessful()) {
            foreach (preg_split('/\R/', trim($listProcess->getOutput())) ?: [] as $entry) {
                $entry = trim($entry);
                if ($entry === '' || in_array($entry, $skipHomeEntries, true)) {
                    continue;
                }
                $required += $this->duBytesAsRoot($fromHome . '/' . $entry);
            }
        }

        $sourceVolumeDataCount = $this->countVolumeDataDirs($fromProject);
        if ($sourceVolumeDataCount > 0) {
            $srcVolumes = $fromHome . '/docker/volumes';
            if ($this->pathIsDirectoryAsRoot($srcVolumes)) {
                foreach ($this->listDockerVolumeEntriesAsRoot($srcVolumes) as $entry) {
                    $srcData = "{$srcVolumes}/{$entry}/_data";
                    if ($this->pathIsDirectoryAsRoot($srcData)) {
                        $required += $this->duBytesAsRoot($srcData);
                    }
                }
            }
        }

        $dfProcess = $this->system->runProcess(['sudo', 'df', '-P', $toHome]);
        if (!$dfProcess->isSuccessful()) {
            throw new \RuntimeException('Could not determine available disk space on target home.');
        }

        $lines = preg_split('/\R/', trim($dfProcess->getOutput())) ?: [];
        $dataLine = $lines[count($lines) - 1] ?? '';
        $parts = preg_split('/\s+/', trim($dataLine));
        $availableKb = isset($parts[3]) ? (int) $parts[3] : 0;
        $availableBytes = $availableKb * 1024;

        if ($required > $availableBytes) {
            throw new \RuntimeException(
                'Insufficient disk space on target for push (required '
                . $this->formatBytes($required)
                . ', available '
                . $this->formatBytes($availableBytes)
                . ').'
            );
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 1) . ' GiB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MiB';
        }

        return $bytes . ' B';
    }

    private function swapIncomingHome(
        string $toHome,
        string $incomingDir,
        string $prePushDir
    ): void {
        $this->runPushProcess(
            ['sudo', 'mkdir', '-p', $prePushDir],
            'Failed to create .pre-push directory'
        );

        $listProcess = $this->system->runProcess(
            ['sudo', 'ls', '-A', $toHome],
            [],
            self::PROCESS_TIMEOUT_SECONDS
        );
        if (!$listProcess->isSuccessful()) {
            throw new \RuntimeException('Failed to list target home before push swap.');
        }

        foreach (preg_split('/\R/', trim($listProcess->getOutput())) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '' || in_array($entry, ['docker', '.incoming', '.pre-push'], true)) {
                continue;
            }
            $this->runPushProcess(
                ['sudo', 'mv', $toHome . '/' . $entry, $prePushDir . '/'],
                "Failed to move '{$entry}' aside before push swap"
            );
        }

        $incomingList = $this->system->runProcess(
            ['sudo', 'ls', '-A', $incomingDir],
            [],
            self::PROCESS_TIMEOUT_SECONDS
        );
        if (!$incomingList->isSuccessful()) {
            throw new \RuntimeException('Failed to list .incoming directory before push swap.');
        }

        foreach (preg_split('/\R/', trim($incomingList->getOutput())) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $this->runPushProcess(
                ['sudo', 'mv', $incomingDir . '/' . $entry, $toHome . '/'],
                "Failed to move incoming '{$entry}' into target home"
            );
        }

        if ($this->pathIsDirectoryAsRoot($incomingDir)) {
            $this->runPushProcess(
                ['sudo', 'rmdir', $incomingDir],
                'Failed to remove empty .incoming directory after swap'
            );
        }
    }

    private function cleanupIncomingDir(string $incomingDir): void
    {
        if ($this->pathIsDirectoryAsRoot($incomingDir)
            || $this->pathExistsAsRoot($incomingDir)) {
            $this->runPushProcess(
                ['sudo', 'rm', '-rf', $incomingDir],
                'Failed to remove .incoming directory'
            );
        }
    }

    private function cleanupPushSuccess(string $prePushDir, string $incomingDir): void
    {
        if ($this->pathIsDirectoryAsRoot($prePushDir)
            || $this->pathExistsAsRoot($prePushDir)) {
            $this->runPushProcess(
                ['sudo', 'rm', '-rf', $prePushDir],
                'Failed to remove .pre-push directory after successful push'
            );
        }

        $this->cleanupIncomingDir($incomingDir);
    }
}
