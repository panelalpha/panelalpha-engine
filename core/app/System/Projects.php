<?php

namespace App\System;

use App\Exceptions\ProblemException;
use App\Exceptions\RollbackIncompleteException;
use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\DetectAppPort;
use App\Models\Domain as DomainModel;
use App\Models\MysqlDatabase;
use App\Models\User as ModelsUser;
use App\System as EngineSystem;
use App\System\Project\CopyAttempt;
use App\System\Project\Dind;
use App\System\Project\Dind\AppDatabase;
use App\System\Project\Dind\AppDatabaseCopy;
use App\System\Project\Dind\HeldImage;
use App\System\Project\Dind\Paths;
use App\System\Project\Dind\ProvisionedAppDatabase;
use App\System\Project\HomeSwap;
use App\System\Project\PhpHosting\WordPress\ContainerSiteCopy;
use App\System\Project\PhpHosting\WordPress\KeptSite;
use App\System\Project\PhpHosting\WordPress\SiteCopy;
use App\System\Project\PushAttempt;
use App\System\Services\Mysql;
use App\System\Services\Mysql\DatabaseCopier;
use App\System\Services\Mysql\DatabaseCopyResult;
use App\System\Services\Mysql\DefinerMap;
use App\System\Services\Mysql\TableReplacement;
use App\System\Services\Mysql\Tables;
use Illuminate\Support\Facades\Log;

class Projects
{
    private const int PROCESS_TIMEOUT_SECONDS = 86400;

    private const array PUSH_SWAP_SKIP_ENTRIES = ['docker', '.incoming', '.pre-push', '.pre-push-db'];

    public function __construct(
        protected EngineSystem $system,
    ) {}

    public function clone(string $sourceUsername, string $targetUsername, bool $copyDatabases = true): ?DatabaseCopyResult
    {
        $source = ModelsUser::findByUsernameOrFail($sourceUsername);
        $target = ModelsUser::findByUsernameOrFail($targetUsername);

        return $this->cloneOrCopy($source, $target, asStaging: false, copyDatabases: $copyDatabases);
    }

    public function copy(
        string $sourceUsername,
        string $targetUsername,
        bool $copyDatabases = true,
        ?callable $log = null,
    ): ?DatabaseCopyResult {
        $source = ModelsUser::findByUsernameOrFail($sourceUsername);
        $target = ModelsUser::findByUsernameOrFail($targetUsername);

        return $this->cloneOrCopy($source, $target, asStaging: true, copyDatabases: $copyDatabases, log: $log);
    }

    /**
     * A failure before the target runs the pushed app rolls the push back; once
     * it does, the push has committed and a failed cleanup only warns.
     *
     * @param  list<array{database: string, name: string}>|null  $tables
     */
    public function pushToApp(
        string $sourceUsername,
        string $targetUsername,
        bool $files = true,
        bool $databases = true,
        ?array $tables = null,
    ): DatabaseCopyResult {
        $push = $this->preparePush($sourceUsername, $targetUsername, $files, $databases, $tables);

        try {
            $this->refuseUnfinishedPush($push);
            $this->prepareWhileRunning($push);
            $this->pauseAppForCopy($push->fromProject);
            $this->pauseAppForCopy($push->toProject);
            $this->copyPushDatabases($push);
            if ($files) {
                $this->stagePushedFiles($push);
                $this->swapInPushedFiles($push);
            }
            $this->startPushedTarget($push);
        } catch (\Throwable $e) {
            throw $this->rollBackPush($push, $e);
        }

        return $this->commitPush($push);
    }

    /**
     * @param  list<array{database: string, name: string}>|null  $tables
     */
    private function preparePush(string $sourceUsername, string $targetUsername, bool $files, bool $databases, ?array $tables): PushAttempt
    {
        $from = ModelsUser::findByUsernameOrFail($sourceUsername);
        $to = ModelsUser::findByUsernameOrFail($targetUsername);
        self::assertCanPush($from, $to);
        self::assertContainerPushCarriesSomething($from, $to, $files, $databases);

        $targetSnapshot = ModelsUser::copiedDeploySnapshot($to->getDetails());
        if ($files) {
            $to->applyDeploySnapshotFrom($from);
            $to->save();
        }

        $fromProject = $this->system->project($from);
        $toProject = $this->system->project($to);
        $fromHome = rtrim($fromProject->homeDirPath(), '/');
        $toHome = rtrim($toProject->homeDirPath(), '/');
        $push = new PushAttempt(
            $from,
            $to,
            $files,
            $databases,
            $tables,
            $targetSnapshot,
            $fromProject,
            $toProject,
            $fromHome,
            $toHome,
            self::isDind($to),
            $this->homeSwap($toHome),
        );
        $push->keptRollbacks = $this->pathExistsAsRoot($push->rollbackDir) || $this->pathIsSymlinkAsRoot($push->rollbackDir);

        return $push;
    }

    /** A failed push's .pre-push aside or rollback dumps refuse the push; a stale .incoming is removed. */
    private function refuseUnfinishedPush(PushAttempt $push): void
    {
        if ($this->pathIsDirectoryAsRoot($push->prePushDir)) {
            throw new \RuntimeException(
                'Incomplete previous push (.pre-push present); refuse to continue.'
            );
        }

        if ($push->keptRollbacks) {
            throw new \RuntimeException(
                'A previous push left the target\'s database rollback dumps in .pre-push-db; '
                .'restore or remove them before pushing again.'
            );
        }

        if ($this->pathIsDirectoryAsRoot($push->incomingDir)) {
            $this->runPushProcess(
                ['sudo', 'rm', '-rf', $push->incomingDir],
                'Failed to remove stale .incoming directory'
            );
        }
    }

    /** What is read or copied before either app is paused. */
    private function prepareWhileRunning(PushAttempt $push): void
    {
        if ($push->files) {
            $this->assertPushDiskSpace($push->fromProject, $push->fromHome, $push->toHome, $push->isDindDest);
        }

        $push->keptSite = $this->keptWordPress($push->from, $push->to, $push->isDindDest);
        $push->containerHosts = $push->isDindDest && $push->files && $push->databases
            ? $this->containerSiteCopy()->sourceHosts($push->from)
            : [];
        if ($push->files && $push->isDindDest) {
            $push->heldImage = $this->provideIncomingImagesWhileRunning($push->fromHome, $push->incomingDir, $push->toProject);
        }
    }

    private function copyPushDatabases(PushAttempt $push): void
    {
        if (! $push->databases) {
            return;
        }

        $push->result = $push->isDindDest
            ? $this->pushAppDatabase($push->from, $push->to, $push->rollbackDir, $push->rollbacks, $push->provisioned)
            : $this->pushClassicDatabases($push);
    }

    private function pushClassicDatabases(PushAttempt $push): DatabaseCopyResult
    {
        if (! $push->replacesTables()) {
            return $this->pushDatabases($push->from, $push->to, $push->rollbackDir, $push->rollbacks, $push->createdDatabases);
        }

        $this->prepareRollbackDir($push->rollbackDir);
        $push->tableReplacement = $this->databaseCopier()->replaceTables($push->from, $push->to, (array) $push->tables, $push->rollbackDir);

        return $push->tableReplacement->result;
    }

    /** The source's home and volumes copied into the target's .incoming, with the target's own values kept. */
    private function stagePushedFiles(PushAttempt $push): void
    {
        $this->runPushProcess(
            ['sudo', 'mkdir', '-p', $push->incomingDir],
            'Failed to create .incoming directory'
        );
        $this->rsyncHomeIntoIncoming($push->fromHome, $push->incomingDir, $push->isDindDest);
        $push->toProject->handStagedHomeToAccount($push->incomingDir);
        if ($push->isDindDest) {
            $this->keepTargetRunFileValues($push->from, $push->to, $push->toProject, $push->incomingDir, $push->provisioned);
        } else {
            $this->rebaseIncomingDocumentRoot($push->from, $push->to, $push->incomingDir);
        }
        $push->result = $push->result->withWarnings(
            $this->copyVolumesToIncoming($push->fromProject, $push->toProject, $push->incomingDir, $push->isDindDest && $push->databases)
        );
        $this->refuseIncomingLinks($push->incomingDir);
    }

    /** The target's home and volumes go aside into .pre-push and the staged copy takes their place. */
    private function swapInPushedFiles(PushAttempt $push): void
    {
        $push->homeSwap->swap($push->incomingDir, $push->prePushDir, self::PUSH_SWAP_SKIP_ENTRIES, 'push swap');
        $push->volumesSwapStarted = true;
        $push->toProject->copyVolumesForClone()?->swapIncomingVolumes();
        $push->toProject->fixPermissions();
        if ($push->keptSite !== null) {
            $this->keepTargetConfig($push->to, $push->keptSite, $push->toHome, $push->prePushDir);
        }
    }

    private function startPushedTarget(PushAttempt $push): void
    {
        if ($push->keptSite !== null && $push->databases) {
            $this->siteCopy()->afterPushedDatabase(
                $push->to,
                $push->keptSite,
                self::tablesOnTarget($push->tableReplacement, $push->tables),
            );
        }

        $this->startTargetAfterPush($push->to, $push->toProject);
        $push->targetStarted = true;

        $this->resumeBoth($push->fromProject, $push->toProject);
        $this->pointContainerWordPressAtItself($push->to, $push->containerHosts);
        $this->refuseTargetNotRunning($push->toProject);
    }

    /** A pushed app that crash-loops, stopped or never listens failed to start, as one `up` refused did. */
    private function refuseTargetNotRunning(Project $toProject): void
    {
        $failure = $toProject->startedAppFailure();
        if ($failure !== null) {
            throw new \RuntimeException("The pushed application did not start: {$failure}");
        }
    }

    /**
     * Undo what the push changed, in the reverse of the order it was done, and
     * answer the error the push fails with.
     */
    private function rollBackPush(PushAttempt $push, \Throwable $e): \RuntimeException
    {
        $unrestored = $this->undoPush($push, $e);
        $this->cleanupIncomingDir($push->incomingDir);
        $this->resumeBoth($push->fromProject, $push->toProject);
        if ($unrestored === [] && ! $push->keptRollbacks) {
            $this->cleanupPath($push->rollbackDir);
        }

        return $this->failPush($push->to, $e, $unrestored, $push->rollbackDir);
    }

    /**
     * @return list<string> every step that could not be undone
     */
    private function undoPush(PushAttempt $push, \Throwable $e): array
    {
        $restoreFiles = $push->homeSwap->hasStarted() || $push->volumesSwapStarted;

        return [
            ...($restoreFiles
                ? $this->restorePushedFiles($push->toProject, $push->homeSwap, $push->incomingDir, $push->prePushDir, $push->volumesSwapStarted)
                : []),
            ...$this->restorePushTables($e, $push->tableReplacement),
            ...$this->restorePushDatabases($push->rollbacks, $push->createdDatabases, $push->from, $push->to),
            ...$this->removeProvisionedAppDatabase($push->provisioned, $push->to),
            ...$this->restoreTargetSnapshot($push->to, $push->targetSnapshot),
            ...$this->putBackHeldImage($push->heldImage),
            ...($restoreFiles || $push->targetStarted ? $this->restartTargetAfterRollback($push->to, $push->toProject) : []),
        ];
    }

    private function commitPush(PushAttempt $push): DatabaseCopyResult
    {
        $this->releaseHeldImage($push->heldImage);
        $result = $push->result->withWarnings(
            $this->cleanupPushSuccess($push->toProject, $push->prePushDir, $push->incomingDir, $push->rollbackDir)
        );
        if ($push->files && $push->isDindDest) {
            $push->toProject->adoptCopiedEnvVars($push->from);
        }
        $this->markPushCompleted($push->to);

        return $result;
    }

    /**
     * Put the target's previous home back from .pre-push, and its volumes from
     * their _data.pre-push copies, after a push swapped some of them. The target
     * is stopped first, so nothing writes into the data being put back. The aside
     * is removed only once the home is back; a failed file rollback keeps it and
     * names it, so the target can be restored by hand.
     *
     * @return list<string> every step that could not be undone
     */
    private function restorePushedFiles(
        Project $toProject,
        HomeSwap $homeSwap,
        string $incomingDir,
        string $prePushDir,
        bool $volumesSwapStarted,
    ): array {
        $this->pauseAppForCopy($toProject);
        try {
            if ($volumesSwapStarted) {
                $toProject->copyVolumesForClone()?->restoreVolumeBackups();
            }
            $homeSwap->undo($incomingDir, $prePushDir, 'push');
            $this->cleanupPath($prePushDir);
            $toProject->fixPermissions();
        } catch (\Throwable $rollbackError) {
            Log::error('Push file rollback failed', [
                'pre_push' => $prePushDir,
                'exception' => $rollbackError->getMessage(),
            ]);

            return ["restoring the files (the previous home is kept in {$prePushDir})"];
        }

        return [];
    }

    /**
     * The target's own deploy fields back, so it starts its restored files the way it was deployed
     * and names its own commit and branch again.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<string> every step that could not be undone
     */
    private function restoreTargetSnapshot(ModelsUser $to, array $snapshot): array
    {
        try {
            $to->restoreDeploySnapshot($snapshot);
            $to->save();
        } catch (\Throwable $restoreError) {
            Log::error('Restoring the target\'s deploy settings after a failed push failed', [
                'username' => $to->username,
                'exception' => $restoreError->getMessage(),
            ]);

            return ['restoring the deploy settings'];
        }

        return [];
    }

    /**
     * The target's own image back under the tag the push built again, before
     * the target starts: on that tag it would start the pushed code. A holding
     * tag that cannot be dropped afterwards is only logged.
     *
     * @return list<string> every step that could not be undone
     */
    private function putBackHeldImage(?HeldImage $heldImage): array
    {
        try {
            $heldImage?->putBack();
        } catch (\Throwable $e) {
            Log::error('Putting the target\'s image back after a failed push failed', [
                'holding_tag' => $heldImage?->holdingTag(),
                'exception' => $e->getMessage(),
            ]);

            return ["putting the target's image back (it is kept as {$heldImage?->holdingTag()})"];
        }
        $this->releaseHeldImage($heldImage);

        return [];
    }

    private function releaseHeldImage(?HeldImage $heldImage): void
    {
        try {
            $heldImage?->release();
        } catch (\Throwable $e) {
            Log::warning('Could not remove the image a push held aside', [
                'holding_tag' => $heldImage?->holdingTag(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Start the target again on its restored files and databases, in new containers: one
     * kept would still mount the pushed files and volumes the rollback removed, or stay
     * stopped where it crashed on the pushed database.
     *
     * @return list<string> every step that could not be undone
     */
    private function restartTargetAfterRollback(ModelsUser $to, Project $toProject): array
    {
        try {
            $this->startTargetAfterPush($to, $toProject, recreate: true);
        } catch (\Throwable $startError) {
            Log::error('Starting the target after a push rollback failed', [
                'username' => $to->username,
                'exception' => $startError->getMessage(),
            ]);

            return ['starting the target again'];
        }

        return [];
    }

    /**
     * The pushed tables under the target's database names, or null when whole
     * databases were pushed.
     *
     * @param  list<array{database: string, name: string}>|null  $tables
     * @return list<array{database: string, name: string}>|null
     */
    private static function tablesOnTarget(?TableReplacement $replacement, ?array $tables): ?array
    {
        if ($replacement === null || $tables === null) {
            return null;
        }
        $databases = $replacement->result->databases;

        return array_map(
            static fn (array $table): array => [
                'database' => $databases[$table['database']] ?? $table['database'],
                'name' => $table['name'],
            ],
            $tables,
        );
    }

    /**
     * @return list<string> every step that could not be undone
     */
    private function restorePushTables(\Throwable $e, ?TableReplacement $replacement): array
    {
        if ($replacement !== null) {
            return $this->databaseCopier()->restoreTables($replacement);
        }

        return $e instanceof RollbackIncompleteException ? $e->failures : [];
    }

    /**
     * A push whose rollback failed keeps its dumps, and its error says where
     * they are and what was not restored, so the target can be restored by hand.
     *
     * @param  list<string>  $unrestored
     */
    private function failPush(ModelsUser $to, \Throwable $e, array $unrestored, string $rollbackDir): \RuntimeException
    {
        if ($unrestored !== []) {
            $error = $e->getMessage().' The target was not fully restored (failed: '.implode(', ', $unrestored)
                .')'.($this->pathExistsAsRoot($rollbackDir) ? "; its rollback dumps are kept in {$rollbackDir}." : '.');
            $this->markPushFailed($to, $error);

            return new \RuntimeException($error, 0, $e);
        }

        $details = $to->getDetails();
        if (($details['async_status']['push'] ?? null) !== 'failed') {
            $this->markPushFailed($to, $e->getMessage());
        }

        return $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
    }

    /**
     * The target's own databases, each replaced with the source's data under its
     * rollback dump. A rollback is recorded only once its dump is complete, so a
     * failed dump is never replayed into a target it did not touch. A source
     * database the target does not own is created, and the target users the
     * source users map to get the grants those had on it.
     *
     * @param  array<string, string>  $rollbacks  destination database => rollback dump
     * @param  list<string>  $created
     */
    private function pushDatabases(
        ModelsUser $from,
        ModelsUser $to,
        string $rollbackDir,
        array &$rollbacks,
        array &$created,
    ): DatabaseCopyResult {
        $copier = $this->databaseCopier();
        $definers = DefinerMap::between($from, $to);
        $sourcePrefix = $from->getMysqlPrefix();
        $targetPrefix = $to->getMysqlPrefix();
        $owned = $to->mysqlDatabases()->pluck('database')->all();

        if ($from->mysqlDatabases->isEmpty()) {
            return new DatabaseCopyResult;
        }

        $this->prepareRollbackDir($rollbackDir);

        $databases = [];
        $warnings = [];
        foreach ($from->mysqlDatabases as $row) {
            $source = $row->database;
            $dest = DatabaseCopier::mapName($source, $sourcePrefix, $targetPrefix);
            if (in_array($dest, $owned, true)) {
                $rollbacks[$dest] = $copier->dumpRollback($dest, $rollbackDir);
                $copier->replaceDatabase($source, $dest, $rollbacks[$dest], $to, $definers);
            } else {
                MysqlDatabase::create(['user_id' => $to->id, 'database' => $dest]);
                $created[] = $dest;
                $copier->copyDatabase($source, $dest, $definers);
                array_push($warnings, ...$copier->grantCreatedDatabase($source, $dest, $definers));
            }
            $databases[$source] = $dest;
        }

        return new DatabaseCopyResult($databases, warnings: $warnings);
    }

    /**
     * @param  array<string, string>  $rollbacks
     * @param  list<string>  $created
     * @return list<string> every step that could not be undone
     */
    private function restorePushDatabases(array $rollbacks, array $created, ModelsUser $from, ModelsUser $to): array
    {
        $mysql = $this->system->mysql();
        $failures = [];
        foreach ($rollbacks as $database => $dump) {
            try {
                $mysql->restoreFromGzip($database, $dump);
            } catch (\Throwable $e) {
                $failures[] = "restoring database '{$database}'";
                Log::warning("Push rollback failed for database '{$database}': ".$e->getMessage());
            }
        }

        foreach ($created as $database) {
            try {
                $this->databaseCopier()->dropCreatedDatabase($database, DefinerMap::between($from, $to));
                $to->mysqlDatabases()->where('database', $database)->delete();
            } catch (\Throwable $e) {
                $failures[] = "dropping created database '{$database}'";
                Log::warning("Push cleanup failed for database '{$database}': ".$e->getMessage());
            }
        }

        return $failures;
    }

    /**
     * @return list<string> every step that could not be undone
     */
    private function removeProvisionedAppDatabase(?ProvisionedAppDatabase $provisioned, ModelsUser $to): array
    {
        if ($provisioned === null) {
            return [];
        }

        try {
            $provisioned->remove($to);
        } catch (\Throwable $e) {
            Log::warning("Push cleanup failed for application database '{$provisioned->name}': ".$e->getMessage());

            return ["removing the application database '{$provisioned->name}' and its user"];
        }

        return [];
    }

    private function startTargetAfterPush(ModelsUser $to, Project $toProject, bool $recreate = false): void
    {
        if (($to->hasGitProject() || $to->getTemplate() === 'dind') && $toProject->hasUserApp()) {
            $start = $toProject->startUserApp($recreate);
            if (($start['exit_code'] ?? 1) !== 0) {
                $output = trim(($start['stderr'] ?? '').' '.($start['stdout'] ?? ''));
                throw new \RuntimeException(
                    $output !== '' ? $output : 'Failed to start target application after push.'
                );
            }

            return;
        }

        $toProject->start();
    }

    /**
     * A first copy of the source's home, taken while both apps still run, and the images its run
     * file names got into the target from it: a Railpack build or a base image transfer takes
     * minutes. The copy taken once both are stopped then moves only what changed.
     */
    private function provideIncomingImagesWhileRunning(string $fromHome, string $incomingDir, Project $toProject): ?HeldImage
    {
        $this->runPushProcess(
            ['sudo', 'mkdir', '-p', $incomingDir],
            'Failed to create .incoming directory'
        );
        $this->rsyncHomeIntoIncoming($fromHome, $incomingDir, isDindDest: true, sourceRunning: true);
        $toProject->handStagedHomeToAccount($incomingDir);
        $heldImage = $toProject->holdCopiedImage(self::incomingRunFile($incomingDir));
        $toProject->provideCopiedImages(self::incomingRunFile($incomingDir));

        return $heldImage;
    }

    private function rsyncHomeIntoIncoming(
        string $fromHome,
        string $incomingDir,
        bool $isDindDest,
        bool $sourceRunning = false,
    ): void {
        $cmd = [
            'sudo', 'rsync', '-a', '--delete',
            '--exclude=.incoming',
            '--exclude=.pre-push',
            '--exclude=.pre-push-db',
        ];
        if ($isDindDest) {
            $cmd[] = '--exclude=docker/';
        }
        $cmd[] = rtrim($fromHome, '/').'/';
        $cmd[] = rtrim($incomingDir, '/').'/';

        $this->runPushProcess(
            $cmd,
            'Failed to rsync source home into .incoming',
            $sourceRunning ? [Project::RSYNC_VANISHED_FILES] : [],
        );
    }

    /**
     * @return list<string> a warning for each anonymous volume with data left out
     */
    private function copyVolumesToIncoming(
        Project $fromProject,
        Project $toProject,
        string $incomingDir,
        bool $copyVolumes,
    ): array {
        if (! $copyVolumes) {
            return [];
        }

        $incomingProject = rtrim($incomingDir, '/').'/project';
        $this->prepareVolumes($toProject, $incomingProject);

        $sourceVolumeDataCount = $this->countVolumeDataDirs($fromProject);
        $copiedVolumes = $toProject->copyVolumeDataToIncoming($fromProject);

        if ($sourceVolumeDataCount > 0 && $copiedVolumes === 0) {
            throw new \RuntimeException(
                'Failed to copy DinD volume data from source (0 volumes copied).'
            );
        }

        if ($sourceVolumeDataCount > 0 && $copiedVolumes !== $sourceVolumeDataCount) {
            throw new \RuntimeException(
                "Failed to copy DinD volume data from source ({$copiedVolumes} of {$sourceVolumeDataCount} volumes copied)."
            );
        }

        return $fromProject->anonymousVolumeWarnings();
    }

    private function rebaseIncomingDocumentRoot(ModelsUser $from, ModelsUser $to, string $incomingDir): void
    {
        if (! str_starts_with(self::sourceDocumentRoot($from), "/{$from->domain}/")) {
            return;
        }

        $this->moveAccountDirectory(
            rtrim($incomingDir, '/').'/'.$from->domain,
            rtrim($incomingDir, '/').'/'.$to->domain,
        );
    }

    private function rebaseDocumentRoot(
        Project $destProject,
        ModelsUser $source,
        ModelsUser $dest,
        DomainModel $domain,
    ): void {
        $sourceRoot = self::sourceDocumentRoot($source);
        $rebased = self::rebasedDocumentRoot($sourceRoot, $source->domain, $dest->domain);

        if ($rebased !== $sourceRoot) {
            $home = rtrim($destProject->homeDirPath(), '/');
            $this->moveAccountDirectory($home.'/'.$source->domain, $home.'/'.$dest->domain);
            $destProject->fixPermissions();
        }

        $domain->setDocumentRoot($rebased);
        $domain->save();
    }

    private static function sourceDocumentRoot(ModelsUser $user): string
    {
        return $user->getMainDomain()?->getDocumentRoot() ?? "/{$user->domain}/public_html";
    }

    public static function rebasedDocumentRoot(string $documentRoot, string $sourceDomain, string $destDomain): string
    {
        $prefix = "/{$sourceDomain}/";
        if (! str_starts_with($documentRoot, $prefix)) {
            return $documentRoot;
        }

        return "/{$destDomain}/".substr($documentRoot, strlen($prefix));
    }

    /**
     * Move one directory inside an account home, as root and without following
     * a symlink the source account planted: -T, plus a refusal when either end
     * is a link or the destination already exists.
     */
    public function moveAccountDirectory(string $from, string $to): void
    {
        if (! $this->pathExistsAsRoot($from) && ! $this->pathIsSymlinkAsRoot($from)) {
            return;
        }

        if ($this->pathIsSymlinkAsRoot($from) || ! $this->pathIsDirectoryAsRoot($from)) {
            throw new \RuntimeException("Refusing to move '{$from}': it is not a directory.");
        }

        if ($this->pathExistsAsRoot($to) || $this->pathIsSymlinkAsRoot($to)) {
            throw new \RuntimeException("Refusing to move over existing '{$to}'.");
        }

        $this->runPushProcess(['sudo', 'mv', '-T', $from, $to], "Failed to move '{$from}' to '{$to}'");
    }

    public static function isDind(ModelsUser $user): bool
    {
        return $user->hasGitProject() || $user->getTemplate() === 'dind';
    }

    /**
     * The target keeps its own wp-config.php, with its database and salts, over
     * the pushed one. Copied as the target account, so a link in either tree
     * reaches nothing the account could not.
     */
    private function keepTargetConfig(ModelsUser $to, KeptSite $kept, string $toHome, string $prePushDir): void
    {
        if (! str_starts_with($kept->configPath, $toHome.'/')) {
            throw new \RuntimeException("The target's wp-config.php is outside its home: {$kept->configPath}");
        }
        $relative = substr($kept->configPath, strlen($toHome) + 1);

        $this->runPushProcess(
            [
                'sudo', 'setpriv', '--reuid', (string) $to->getUid(), '--regid', (string) $to->getGid(), '--clear-groups',
                'cp', '-P', '--remove-destination', $prePushDir.'/'.$relative, $kept->configPath,
            ],
            "Failed to keep the target's wp-config.php",
        );
    }

    private function keptWordPress(ModelsUser $from, ModelsUser $to, bool $isDindDest): ?KeptSite
    {
        $targetConfig = $isDindDest ? null : $this->wordPressConfig($to);
        if ($targetConfig === null || $this->wordPressConfig($from) === null) {
            return null;
        }

        return $this->siteCopy()->beforePush($from, $to, $targetConfig);
    }

    /**
     * The wp-config.php of a WordPress at the project's main domain: in its
     * document root, or the directory above, where WordPress also looks.
     */
    private function wordPressConfig(ModelsUser $user): ?string
    {
        $domain = $user->getMainDomain();
        if ($domain === null) {
            return null;
        }
        $home = rtrim($this->system->project($user)->homeDirPath(), '/');
        $documentRoot = $home.$domain->getDocumentRoot();
        foreach ([$documentRoot, dirname($documentRoot)] as $dir) {
            if (str_starts_with($dir, $home.'/') && $this->pathExistsAsRoot($dir.'/wp-config.php')) {
                return $dir.'/wp-config.php';
            }
        }

        return null;
    }

    protected function siteCopy(): SiteCopy
    {
        return new SiteCopy;
    }

    protected function containerSiteCopy(): ContainerSiteCopy
    {
        return new ContainerSiteCopy;
    }

    /**
     * A copied or pushed WordPress in the project's own containers answers as
     * its own domain. One on a database another project owns is left as it is.
     *
     * @param  list<string>  $sourceHosts  none when the source ran no WordPress
     */
    private function pointContainerWordPressAtItself(ModelsUser $copy, array $sourceHosts): void
    {
        if ($sourceHosts === [] || $this->containerSiteCopy()->pointCopyAtItself($copy, $sourceHosts)) {
            return;
        }
        Log::warning(
            "WordPress in '{$copy->username}' uses a database another project owns; its addresses were not rewritten.",
        );
    }

    private function databaseCopier(): DatabaseCopier
    {
        return new DatabaseCopier($this->system->mysql());
    }

    private function appDatabaseCopy(): AppDatabaseCopy
    {
        return new AppDatabaseCopy($this->system->mysql());
    }

    /**
     * A container target's application database, replaced with the source's
     * under the same rollback dump a classic push takes.
     *
     * @param  array<string, string>  $rollbacks  destination database => rollback dump
     */
    private function pushAppDatabase(
        ModelsUser $from,
        ModelsUser $to,
        string $rollbackDir,
        array &$rollbacks,
        ?ProvisionedAppDatabase &$provisioned,
    ): DatabaseCopyResult {
        if (! AppDatabase::isOwnedBy($from)) {
            return new DatabaseCopyResult;
        }
        $this->prepareRollbackDir($rollbackDir);

        return $this->appDatabaseCopy()->intoTarget($from, $to, $rollbackDir, $rollbacks, $provisioned);
    }

    /**
     * The pushed run file carries the source's database and address; the target
     * keeps its own, as a classic target keeps its wp-config.php.
     */
    private function keepTargetRunFileValues(
        ModelsUser $from,
        ModelsUser $to,
        Project $toProject,
        string $incomingDir,
        ?ProvisionedAppDatabase &$provisioned,
    ): void {
        if (AppDatabase::isOwnedBy($from)) {
            $this->appDatabaseCopy()->ensureTargetHasOne($to, $provisioned);
        }
        $toProject->repointCopiedRunFile($from, self::incomingRunFile($incomingDir));
    }

    private static function incomingRunFile(string $incomingDir): string
    {
        return rtrim($incomingDir, '/').'/project/'.EngineArtifacts::RUN_COMPOSE;
    }

    /**
     * The swap moves every top-level entry of .incoming into the root-owned home, where
     * root later chmods and writes fixed names such as .panelalpha and project.
     */
    private function refuseIncomingLinks(string $incomingDir): void
    {
        $process = $this->system->runProcess(
            ['sudo', 'find', $incomingDir, '-mindepth', '1', '-maxdepth', '1', '-type', 'l'],
            [],
            self::PROCESS_TIMEOUT_SECONDS,
        );
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Failed to check .incoming for symbolic links before the push swap');
        }

        $links = array_values(array_filter(array_map('trim', preg_split('/\R/', $process->getOutput()) ?: [])));
        if ($links !== []) {
            throw new \RuntimeException(sprintf(
                'Refusing to push: the copied home holds a symbolic link at its top level (%s).',
                implode(', ', array_map('basename', $links)),
            ));
        }
    }

    private function pathIsSymlinkAsRoot(string $path): bool
    {
        return $this->system->runProcess(['sudo', 'test', '-L', $path])->isSuccessful();
    }

    private function cleanupPath(string $path): void
    {
        if (! $this->pathExistsAsRoot($path) && ! $this->pathIsSymlinkAsRoot($path)) {
            return;
        }

        $this->runPushProcess(['sudo', 'rm', '-rf', $path], "Failed to remove '{$path}'");
    }

    /**
     * Create a push's rollback directory so the process that writes the dump
     * (the queue worker, not root) can reach it — and only that process. The
     * account's home root is root-owned, so it cannot plant a link in its place;
     * the symlink refusal is here because a copied source account could try.
     */
    public function prepareRollbackDir(string $directory): void
    {
        if ($this->pathIsSymlinkAsRoot($directory)) {
            throw new \RuntimeException("Refusing to use '{$directory}': it is a symlink.");
        }

        $failure = 'Failed to prepare the push database rollback directory';
        $this->runPushProcess(['sudo', 'mkdir', '-p', $directory], $failure);
        $this->runPushProcess(['sudo', 'chown', $this->engineProcessUser(), $directory], $failure);
        $this->runPushProcess(['sudo', 'chmod', '0700', $directory], $failure);
    }

    /**
     * The user the non-sudo engine process runs as: the queue worker in
     * production (www-data), root in the CLI and the test harness.
     */
    private function engineProcessUser(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $entry = posix_getpwuid(posix_geteuid());
            if (is_array($entry) && is_string($entry['name'] ?? null) && $entry['name'] !== '') {
                return $entry['name'];
            }
        }

        return 'www-data';
    }

    private function cloneOrCopy(
        ModelsUser $source,
        ModelsUser $dest,
        bool $asStaging,
        bool $copyDatabases = true,
        ?callable $log = null,
    ): ?DatabaseCopyResult {
        $copy = new CopyAttempt(
            $source,
            $dest,
            $this->system->project($source),
            $this->system->project($dest),
            self::isDind($dest),
            $asStaging,
            $copyDatabases,
        );

        try {
            $this->assertCanCopy($copy);
            $this->createCopyDestination($copy);
            $this->prepareCopyWhileRunning($copy);
            $this->copyWhileSourcePaused($copy);
            $this->adoptAppPort($copy);
            $domainModel = $this->copyDomain($copy);
            $this->copyClassicSite($copy, $domainModel, $log);
            $this->serveCopy($copy, $domainModel);
            $this->startCopiedApp($copy);
            $this->markCopySucceeded($copy);

            return self::withVolumeWarnings($copy->databaseResult, $copy->volumeWarnings);
        } catch (\Throwable $e) {
            $this->resumeSourceAfterFailedCopy($copy);
            $this->retainDestinationAfterFailure($dest, $e, $asStaging);
            throw $e;
        }
    }

    private function assertCanCopy(CopyAttempt $copy): void
    {
        self::assertSameTemplate($copy->source, $copy->dest);
        self::assertNotPending($copy->source);
        if (! $copy->asStaging) {
            self::assertNotPending($copy->dest);
        } else {
            self::assertCanCopyIntoStaging($copy->source, $copy->dest);
        }
        $this->assertCopyGetsDatabases($copy->source, $copy->copyDatabases);
    }

    /** The destination account, its Linux user and its running containers, deployed as the source is. */
    private function createCopyDestination(CopyAttempt $copy): void
    {
        $copy->dest->applyDeploySnapshotFrom($copy->source);
        $copy->dest->save();

        $copy->destProject->createDirectories();
        $osUser = $copy->destProject->createLinuxUser();
        $copy->dest->setDetails([
            'UID' => $osUser['UID'],
            'GID' => $osUser['GID'],
        ]);
        $copy->dest->save();
        $copy->destProject->createFromTemplate();
        $copy->destProject->start();
        $copy->destProject->fixPermissions();
        $copy->destProject->configureQuota();
        $copy->destProject->waitForAllRunning();
    }

    /** What is read or copied before the source is paused. */
    private function prepareCopyWhileRunning(CopyAttempt $copy): void
    {
        $copy->containerHosts = $copy->copyDatabases && self::isDind($copy->dest)
            ? $this->containerSiteCopy()->sourceHosts($copy->source)
            : [];
        if ($copy->isDindDest) {
            $this->provideCopyImagesWhileRunning($copy->sourceProject, $copy->destProject);
        }
    }

    private function copyWhileSourcePaused(CopyAttempt $copy): void
    {
        $this->pauseAppForCopy($copy->sourceProject);
        $copy->sourcePaused = true;

        try {
            $this->copyHomeAndApp($copy);
            if ($copy->copyDatabases) {
                $this->copyVolumeData($copy);
            }
        } finally {
            if ($copy->sourcePaused) {
                $this->resumeSourceAfterCopy($copy->sourceProject);
                $copy->sourcePaused = false;
            }
        }
    }

    private function copyHomeAndApp(CopyAttempt $copy): void
    {
        $copy->destProject->copyHomeDirFrom($copy->sourceProject);
        $copy->destProject->copyProjectConfigFrom($copy->sourceProject);
        if ($copy->isDindDest) {
            $copy->databaseResult = $this->appDatabaseCopy()->intoCopy($copy->source, $copy->dest, $copy->copyDatabases);
            $copy->destProject->repointCopiedRunFile($copy->source);
            $copy->destProject->adoptCopiedEnvVars($copy->source);
        }
    }

    private function copyVolumeData(CopyAttempt $copy): void
    {
        $this->prepareVolumes($copy->destProject);
        $srcCount = $this->countVolumeDataDirs($copy->sourceProject);
        $copied = $copy->destProject->copyVolumeDataFrom($copy->sourceProject);
        if ($srcCount > 0 && $copied !== $srcCount) {
            throw new \RuntimeException(
                "Failed to copy DinD volume data from source ({$copied} of {$srcCount} volumes copied)."
            );
        }
        $copy->volumeWarnings = $copy->sourceProject->anonymousVolumeWarnings();
    }

    /** The port the copied app listens on, or the source's when the copy names none. */
    private function adoptAppPort(CopyAttempt $copy): void
    {
        $detectedPort = $this->detectAppPort($copy->destProject);
        $srcDetails = $copy->source->getDetails();
        if ($detectedPort !== null) {
            $copy->dest->setAppPort($detectedPort);
            $copy->dest->save();
        } elseif (array_key_exists('app_port', $srcDetails)) {
            $copy->dest->setAppPort(
                $srcDetails['app_port'] !== null ? (int) $srcDetails['app_port'] : null
            );
            $copy->dest->save();
        }
    }

    private function copyDomain(CopyAttempt $copy): DomainModel
    {
        $domainModel = $copy->dest->domains()->where('domain', $copy->dest->domain)->first();
        if ($domainModel === null) {
            throw new \RuntimeException('Destination has no domain row.');
        }

        return $domainModel;
    }

    /** A classic copy's document root under its own domain, and its databases. */
    private function copyClassicSite(CopyAttempt $copy, DomainModel $domainModel, ?callable $log): void
    {
        if ($copy->isDindDest) {
            return;
        }

        $this->rebaseDocumentRoot($copy->destProject, $copy->source, $copy->dest, $domainModel);
        if ($copy->copyDatabases) {
            $copy->databaseResult = $this->databaseCopier()->copyProjectDatabases(
                $copy->source,
                $copy->dest,
                $log ?? static function (string $line): void {}
            );
        }
    }

    private function serveCopy(CopyAttempt $copy, DomainModel $domainModel): void
    {
        $copy->destProject->syncGeneratedProxyRules();
        $this->system->project($copy->dest)->domain($domainModel)->create();
        if (! $copy->isDindDest && $this->wordPressConfig($copy->dest) !== null) {
            $this->siteCopy()->pointCopyAtItself($copy->dest, $copy->databaseResult);
        }
    }

    private function startCopiedApp(CopyAttempt $copy): void
    {
        if (($copy->dest->hasGitProject() || $copy->dest->getTemplate() === 'dind') && $copy->destProject->hasUserApp()) {
            $result = $copy->destProject->startUserApp();
            if (($result['exit_code'] ?? 1) !== 0) {
                $output = trim(($result['stderr'] ?? '').' '.($result['stdout'] ?? ''));
                throw new \RuntimeException(
                    $output !== '' ? $output : 'Failed to start application.'
                );
            }
        }
        $this->pointContainerWordPressAtItself($copy->dest, $copy->containerHosts);
    }

    private function markCopySucceeded(CopyAttempt $copy): void
    {
        $copy->dest->markDeploySucceeded();
        $copy->dest->setDetails(['error' => null]);
        if ($copy->asStaging) {
            $copy->dest->status = 'active';
            $copy->dest->mergeAsyncStatus(['staging' => 'completed']);
        }
        $copy->dest->save();
    }

    /** Best effort: the error the copy failed with is the one reported. */
    private function resumeSourceAfterFailedCopy(CopyAttempt $copy): void
    {
        if (! $copy->sourcePaused) {
            return;
        }

        try {
            $this->resumeSourceAfterCopy($copy->sourceProject);
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * A classic WordPress copy is pointed at the databases copied for it, so
     * one without them is refused before anything is copied.
     *
     * @return list<array{field: string, code: string, message: string}>
     */
    public function copyDatabasesProblems(ModelsUser $source, bool $copyDatabases): array
    {
        if ($copyDatabases || self::isDind($source) || $this->wordPressConfig($source) === null) {
            return [];
        }

        return [[
            'field' => 'copy_databases',
            'code' => 'wordpress_copy_needs_databases',
            'message' => "Project '{$source->username}' runs WordPress, and a copy of it needs its own databases "
                ."or it would use the source's; leave copy_databases true.",
        ]];
    }

    private function assertCopyGetsDatabases(ModelsUser $source, bool $copyDatabases): void
    {
        $problems = $this->copyDatabasesProblems($source, $copyDatabases);
        if ($problems !== []) {
            throw ProblemException::of($problems);
        }
    }

    /**
     * A first copy of the source's home, taken while it still runs, and the images its run file
     * names got into the copy from it: a Railpack build or a base image transfer takes minutes.
     * The copy taken once the source is stopped then moves only what changed.
     */
    private function provideCopyImagesWhileRunning(Project $sourceProject, Project $destProject): void
    {
        $destProject->copyHomeDirFrom($sourceProject, sourceRunning: true);
        $destProject->provideCopiedImages();
    }

    /**
     * @param  list<string>  $warnings
     */
    private static function withVolumeWarnings(?DatabaseCopyResult $result, array $warnings): ?DatabaseCopyResult
    {
        return $warnings === [] ? $result : ($result ?? new DatabaseCopyResult)->withWarnings($warnings);
    }

    private function retainDestinationAfterFailure(ModelsUser $dest, \Throwable $e, bool $asStaging): void
    {
        try {
            $dest->setDetails([
                'error' => $e->getMessage(),
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

    public static function assertCanCreate(ModelsUser $source, ?string $destPrefix = null): void
    {
        $problems = self::createProblems($source, $destPrefix);
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
     * @return list<array{field: string, code: string, message: string}>
     */
    public static function createProblems(ModelsUser $source, ?string $destPrefix = null): array
    {
        $problems = [];
        if ($source->status === 'pending') {
            $problems[] = [
                'field' => 'project',
                'code' => 'project_pending',
                'message' => "Project '{$source->username}' is pending.",
            ];
        }
        if ($source->isStaging()) {
            $problems[] = [
                'field' => 'project',
                'code' => 'project_is_staging',
                'message' => "Project '{$source->username}' is a staging project and cannot create staging.",
            ];
        }
        if ($source->exists && $source->stagingUser()->exists()) {
            $problems[] = [
                'field' => 'project',
                'code' => 'project_has_staging',
                'message' => 'Project already has a staging.',
            ];
        }

        if ($destPrefix !== null) {
            $problems = [...$problems, ...self::databaseNameProblems($source, $destPrefix, 'new_username')];
        }

        return $problems;
    }

    /**
     * Refuse a copy whose database or user names would not fit the destination
     * prefix, before any account or database is created.
     */
    public static function assertDatabaseNamesFit(ModelsUser $source, string $destPrefix): void
    {
        $problems = self::databaseNameProblems($source, $destPrefix, 'new_username');
        if ($problems !== []) {
            throw ProblemException::of($problems);
        }
    }

    /**
     * @return list<array{field: string, code: string, message: string}>
     */
    private static function databaseNameProblems(ModelsUser $source, string $destPrefix, string $field): array
    {
        if (! $source->exists) {
            return [];
        }

        $sourcePrefix = $source->getMysqlPrefix();
        $problems = [];

        foreach ($source->mysqlDatabases as $row) {
            $name = $row->database;
            $mapped = DatabaseCopier::mapName($name, $sourcePrefix, $destPrefix);
            if (! Mysql::isValidDatabaseName($name)) {
                $problems[] = [
                    'field' => $field,
                    'code' => 'database_name_unsupported',
                    'message' => "Database '{$name}' is outside the name grammar the engine can copy.",
                ];
            } elseif (strlen($mapped) > 64) {
                $problems[] = [
                    'field' => $field,
                    'code' => 'database_name_too_long',
                    'message' => "Database '{$name}' maps to '{$mapped}', longer than 64 characters.",
                ];
            }
        }

        foreach ($source->mysqlUsers as $row) {
            $name = $row->user;
            $mapped = DatabaseCopier::mapName($name, $sourcePrefix, $destPrefix);
            if (strlen($mapped) > 32) {
                $problems[] = [
                    'field' => $field,
                    'code' => 'database_user_name_too_long',
                    'message' => "MySQL user '{$name}' maps to '{$mapped}', longer than 32 characters.",
                ];
            }
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

    private static function assertContainerPushCarriesSomething(ModelsUser $from, ModelsUser $to, bool $files, bool $databases): void
    {
        $problems = self::emptyContainerPushProblems($from, $to, $files, $databases);
        if ($problems !== []) {
            throw new \RuntimeException($problems[0]['message'], 422);
        }
    }

    /**
     * Without its files a container project pushes only the application database
     * the engine made for it; a source without one would stop and restart both apps
     * to carry nothing.
     *
     * @return list<array{field: string, code: string, message: string}>
     */
    public static function emptyContainerPushProblems(ModelsUser $from, ModelsUser $to, bool $files, bool $databases): array
    {
        if ($files || ! $databases || ! self::isDind($to) || AppDatabase::isOwnedBy($from)) {
            return [];
        }

        return [[
            'field' => 'databases',
            'code' => 'nothing_to_push',
            'message' => "Nothing to push: files are disabled, and '{$from->username}' has no application database, "
                .'the only database a container project pushes without its files.',
        ]];
    }

    /**
     * @return list<array{field: string, code: string, message: string}>
     */
    public static function pushProblems(ModelsUser $from, ModelsUser $to): array
    {
        $problems = [];
        if (self::templateKey($from) !== self::templateKey($to)) {
            $left = $from->getTemplate() ?? 'null';
            $right = $to->getTemplate() ?? 'null';
            $problems[] = [
                'field' => 'target',
                'code' => 'target_template_mismatch',
                'message' => "Projects '{$from->username}' ({$left}) and '{$to->username}' ({$right}) have different templates.",
            ];
        }
        if ($from->status === 'pending') {
            $problems[] = [
                'field' => 'project',
                'code' => 'project_pending',
                'message' => "Project '{$from->username}' is pending.",
            ];
        }
        if ($to->status === 'pending') {
            $problems[] = [
                'field' => 'target',
                'code' => 'target_pending',
                'message' => "Project '{$to->username}' is pending.",
            ];
        }
        if ((int) $from->id === (int) $to->id) {
            $problems[] = [
                'field' => 'target',
                'code' => 'target_same_project',
                'message' => 'Cannot push to the same project.',
            ];
        }
        $paired = (int) $from->staging === (int) $to->id
            || (int) $to->staging === (int) $from->id;
        if (! $paired) {
            $problems[] = [
                'field' => 'target',
                'code' => 'target_not_staging_pair',
                'message' => "Projects '{$from->username}' and '{$to->username}' are not a staging pair.",
            ];
        }

        return [...$problems, ...self::databaseNameProblems($from, $to->getMysqlPrefix(), 'target')];
    }

    /**
     * Every selected table must name a database the source owns and a table that
     * database holds, checked before the push is queued.
     *
     * @param  list<array{database: string, name: string}>  $tables
     * @return list<array{field: string, code: string, message: string}>
     */
    public static function tableProblems(ModelsUser $source, array $tables, Tables $sourceTables): array
    {
        $owned = $source->mysqlDatabases()->pluck('database')->all();
        $known = [];
        $problems = [];

        foreach ($tables as $selection) {
            $database = is_array($selection) ? ($selection['database'] ?? null) : null;
            $table = is_array($selection) ? ($selection['name'] ?? null) : null;
            if (! is_string($database) || ! is_string($table)) {
                continue;
            }

            if (! in_array($database, $owned, true)) {
                $problems[] = [
                    'field' => 'tables',
                    'code' => 'database_not_found',
                    'message' => "The source project does not own database '{$database}'.",
                ];

                continue;
            }

            $known[$database] ??= $sourceTables->names($database);
            if (! in_array($table, $known[$database], true)) {
                $problems[] = [
                    'field' => 'tables',
                    'code' => 'table_not_found',
                    'message' => "Table '{$database}.{$table}' does not exist in the source project.",
                ];
            }
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

    private function resumeSourceAfterCopy(Project $project): void
    {
        $project->copyVolumesForClone()?->resumeSourceAfterCopy();
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
        if (! $runtime instanceof Dind) {
            return null;
        }

        $path = (new Paths($runtime))->composeFileForPorts();
        if (! is_file($path)) {
            return null;
        }

        $ports = DetectAppPort::detectAllPorts($path);
        if (! empty($ports['primary'])) {
            return (int) $ports['primary'];
        }

        return DetectAppPort::detectPrimaryPort($path);
    }

    private function resumeBoth(Project $fromProject, Project $toProject): void
    {
        $this->resumeSourceAfterCopy($fromProject);
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
     * @param  list<string>  $cmd
     * @param  list<int>  $alsoAccepted  exit statuses that are not a failure here
     */
    private function runPushProcess(array $cmd, string $context, array $alsoAccepted = []): void
    {
        $process = $this->system->runProcess($cmd, [], self::PROCESS_TIMEOUT_SECONDS);

        if ($process->isSuccessful() || in_array($process->getExitCode(), $alsoAccepted, true)) {
            return;
        }

        $output = trim($process->getErrorOutput().' '.$process->getOutput());
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
        if (! $process->isSuccessful()) {
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
        if (! $this->pathIsDirectoryAsRoot($path) && ! $this->pathExistsAsRoot($path)) {
            return 0;
        }

        $process = $this->system->runProcess(['sudo', 'du', '-sb', $path]);
        if (! $process->isSuccessful()) {
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
        $skipHomeEntries = ['.incoming', '.pre-push', '.pre-push-db'];
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
                $required += $this->duBytesAsRoot($fromHome.'/'.$entry);
            }
        }

        $sourceVolumeDataCount = $this->countVolumeDataDirs($fromProject);
        if ($sourceVolumeDataCount > 0) {
            $srcVolumes = $fromHome.'/docker/volumes';
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
        if (! $dfProcess->isSuccessful()) {
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
                .$this->formatBytes($required)
                .', available '
                .$this->formatBytes($availableBytes)
                .').'
            );
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 1).' GiB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MiB';
        }

        return $bytes.' B';
    }

    private function homeSwap(string $toHome): HomeSwap
    {
        return new HomeSwap($this->system, rtrim($toHome, '/'), self::PROCESS_TIMEOUT_SECONDS);
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

    /**
     * Once the target runs the pushed app, nothing is rolled back any more: a
     * cleanup step that fails is logged and named in the push's warnings.
     *
     * @return list<string>
     */
    private function cleanupPushSuccess(Project $toProject, string $prePushDir, string $incomingDir, string $rollbackDir): array
    {
        return array_values(array_filter([
            $this->cleanupAfterCommit($prePushDir, fn () => $this->removePrePushDir($prePushDir)),
            $this->cleanupAfterCommit($incomingDir, fn () => $this->cleanupIncomingDir($incomingDir)),
            $this->cleanupAfterCommit($rollbackDir, fn () => $this->cleanupPath($rollbackDir)),
            $this->cleanupAfterCommit(
                'the volume data kept aside',
                fn () => $toProject->copyVolumesForClone()?->discardVolumeBackups(),
            ),
        ]));
    }

    private function cleanupAfterCommit(string $leftover, callable $cleanup): ?string
    {
        try {
            $cleanup();
        } catch (\Throwable $e) {
            Log::warning('Cleanup after a completed push failed', [
                'leftover' => $leftover,
                'exception' => $e->getMessage(),
            ]);

            return "The push was applied, but removing {$leftover} failed: {$e->getMessage()}";
        }

        return null;
    }

    private function removePrePushDir(string $prePushDir): void
    {
        if ($this->pathIsDirectoryAsRoot($prePushDir)
            || $this->pathExistsAsRoot($prePushDir)) {
            $this->runPushProcess(
                ['sudo', 'rm', '-rf', $prePushDir],
                'Failed to remove .pre-push directory after successful push'
            );
        }
    }
}
