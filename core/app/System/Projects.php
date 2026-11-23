<?php

namespace App\System;

use App\Exceptions\ProblemException;
use App\Exceptions\ProjectBusyException;
use App\Exceptions\RollbackIncompleteException;
use App\Jobs\CreateStaging;
use App\Jobs\PushState;
use App\Jobs\RenameMysqlDatabase;
use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\DetectAppPort;
use App\Lib\Project\ClassicAppType;
use App\Lib\Project\ProjectRebuild;
use App\Models\Domain as DomainModel;
use App\Models\MysqlDatabase;
use App\Models\Task;
use App\Models\User as ModelsUser;
use App\System as EngineSystem;
use App\System\Project\CopyAttempt;
use App\System\Project\Dind;
use App\System\Project\Dind\AppDatabase;
use App\System\Project\Dind\AppDatabaseCopy;
use App\System\Project\Dind\CopyVolumes;
use App\System\Project\Dind\HeldImage;
use App\System\Project\Dind\Paths;
use App\System\Project\Dind\ProvisionedAppDatabase;
use App\System\Project\HomeSwap;
use App\System\Project\PhpHosting\WordPress\ContainerSiteCopy;
use App\System\Project\PhpHosting\WordPress\Install;
use App\System\Project\PhpHosting\WordPress\InstallLocator;
use App\System\Project\PhpHosting\WordPress\KeptSite;
use App\System\Project\PhpHosting\WordPress\SiteCopy;
use App\System\Project\PhpHosting\WordPress\UnclearInstall;
use App\System\Project\PhpHosting\WordPress\WordPress;
use App\System\Project\PushAttempt;
use App\System\Services\Mysql;
use App\System\Services\Mysql\DatabaseCopier;
use App\System\Services\Mysql\DatabaseCopyResult;
use App\System\Services\Mysql\DefinerMap;
use App\System\Services\Mysql\TableReplacement;
use App\System\Services\Mysql\Tables;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class Projects
{
    private const int PROCESS_TIMEOUT_SECONDS = 86400;

    private const array PUSH_SWAP_SKIP_ENTRIES = ['docker', '.incoming', '.pre-push', CopyVolumes::STAGING];

    public function __construct(
        protected EngineSystem $system,
    ) {}

    /** The rows given, never looked up again by name: a project that took a deleted one's name is not this copy's. */
    public function clone(ModelsUser $source, ModelsUser $target, bool $copyDatabases = true): ?DatabaseCopyResult
    {
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
        self::assertTablesSelectSomething($tables);

        $targetSnapshot = ModelsUser::copiedDeploySnapshot($to->getDetails());
        if ($files) {
            $to->applyDeploySnapshotFrom($from);
            $to->save();
        }

        $push = $this->newPushAttempt($from, $to, $files, $databases, $tables, $targetSnapshot);
        $push->keptRollbacks = file_exists($push->rollbackDir) || is_link($push->rollbackDir);

        return $push;
    }

    /**
     * @param  list<array{database: string, name: string}>|null  $tables
     */
    private function newPushAttempt(
        ModelsUser $from,
        ModelsUser $to,
        bool $files,
        bool $databases,
        ?array $tables,
        array $targetSnapshot,
    ): PushAttempt {
        $fromProject = $this->system->project($from);
        $toProject = $this->system->project($to);
        $fromHome = rtrim($fromProject->homeDirPath(), '/');
        $toHome = rtrim($toProject->homeDirPath(), '/');

        return new PushAttempt(
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
            $this->system->pushRollbacksDirPath().'/'.$to->username,
            self::isDind($to),
            $this->homeSwap($toHome),
        );
    }

    /** A failed push's .pre-push aside, volume data or rollback dumps refuse the push; a stale .incoming is removed. */
    private function refuseUnfinishedPush(PushAttempt $push): void
    {
        if ($this->pathIsDirectoryAsRoot($push->prePushDir)) {
            throw new \RuntimeException(
                'Incomplete previous push (.pre-push present); refuse to continue.'
            );
        }

        if ($this->pathIsDirectoryAsRoot($push->volumeStagingDir)) {
            throw new \RuntimeException(
                'Incomplete previous push ('.CopyVolumes::STAGING.' present); refuse to continue.'
            );
        }

        if ($push->keptRollbacks) {
            throw new \RuntimeException(
                "A previous push left the target's database rollback dumps in the core container at {$push->rollbackDir}; "
                .'restore or remove them before pushing again.'
            );
        }

        if ($this->pathIsDirectoryAsRoot($push->incomingDir)) {
            $this->runPushProcess(
                Privileged::files()->removeTree($push->incomingDir),
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
            Privileged::files()->makeDirectories($push->incomingDir),
            'Failed to create .incoming directory'
        );
        $this->rsyncHomeIntoIncoming($push->fromHome, $push->incomingDir, $push->isDindDest);
        $push->toProject->handStagedHomeToAccount($push->incomingDir);
        if ($push->isDindDest) {
            $this->keepTargetRunFileValues($push->from, $push->to, $push->toProject, $push->incomingDir, $push->provisioned);
        } else {
            $this->rebaseIncomingDocumentRoot($push->from, $push->to, $push->incomingDir);
        }
        $push->volumesStaged = $push->isDindDest && $push->databases;
        $push->result = $push->result->withWarnings(
            $this->copyVolumesToIncoming($push->fromProject, $push->toProject, $push->incomingDir, $push->volumesStaged)
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
        if (! $push->isDindDest) {
            $this->keepTargetDomainDirectives($push);
        }
    }

    /** A domain's PHP settings are the target project's own configuration, not files a push brings or takes away. */
    private function keepTargetDomainDirectives(PushAttempt $push): void
    {
        $uid = $push->to->getUid();
        $gid = $push->to->getGid();
        if ($uid === null || $gid === null) {
            return;
        }

        foreach ($push->toProject->php()->domainDirectiveRelativePaths() as $relative) {
            $path = $push->toHome.'/'.ltrim($relative, '/');
            if (! $this->pathIsDirectoryAsRoot(dirname($path))) {
                continue;
            }

            $aside = $push->prePushDir.'/'.ltrim($relative, '/');
            $this->runPushProcess(
                $this->pathExistsAsRoot($aside)
                    ? Privileged::account()->copyReplacingAsAccount($uid, $gid, $aside, $path)
                    : Privileged::accountFiles($uid, $gid)->removeIfExists($path),
                "Failed to keep the target's domain PHP settings",
            );
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
            $this->removeRollbackDir($push->rollbackDir);
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
                ? $this->restorePushedFiles($push)
                : $this->restorePushedVolumes($push)),
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
     * Put back the stopped target's volumes and home from their pre-push copies, each whether or not the other could
     * be. What could not be put back is kept and named, to be restored by hand.
     *
     * @return list<string> every step that could not be undone
     */
    private function restorePushedFiles(PushAttempt $push): array
    {
        $this->pauseAppForCopy($push->toProject);

        return [...$this->restorePushedVolumes($push), ...$this->restorePushedHome($push)];
    }

    /**
     * The volumes the push swapped put back and what it only staged dropped; its staging stays when any could not be.
     *
     * @return list<string>
     */
    private function restorePushedVolumes(PushAttempt $push): array
    {
        if (! $push->volumesStaged) {
            return [];
        }
        try {
            $push->toProject->copyVolumesForClone()?->restoreVolumeBackups();
        } catch (\Throwable $rollbackError) {
            Log::error('Push volume rollback failed', [
                'staging' => $push->volumeStagingDir,
                'exception' => $rollbackError->getMessage(),
            ]);

            return ["restoring the volumes (their data before the push is kept in {$push->volumeStagingDir})"];
        }

        return [];
    }

    /**
     * The aside goes only once the home is back.
     *
     * @return list<string>
     */
    private function restorePushedHome(PushAttempt $push): array
    {
        try {
            $push->homeSwap->undo($push->incomingDir, $push->prePushDir, 'push');
            $this->cleanupPath($push->prePushDir);
            $push->toProject->fixPermissions();
        } catch (\Throwable $rollbackError) {
            Log::error('Push file rollback failed', [
                'pre_push' => $push->prePushDir,
                'exception' => $rollbackError->getMessage(),
            ]);

            return ["restoring the files (the previous home is kept in {$push->prePushDir})"];
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
     * The target's own image back under the tag the push built again, before the target starts: on that tag it would
     * start the pushed code. A holding tag that cannot be dropped afterwards is only logged.
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
     * Start the target again on its restored files and databases, in new containers: one kept would still mount the
     * pushed files and volumes the rollback removed, or stay stopped where it crashed on the pushed database.
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
                .')'.(is_dir($rollbackDir) ? "; its rollback dumps are kept in the core container at {$rollbackDir}." : '.');
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
     * The target's own databases, each replaced with the source's data under its rollback dump; a source database the
     * target does not own is created.
     *
     * @param  array<string, string>  $rollbacks  destination database => rollback dump
     * @param  list<string>  $created
     */
    private function pushDatabases(ModelsUser $from, ModelsUser $to, string $rollbackDir, array &$rollbacks, array &$created): DatabaseCopyResult
    {
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
                $this->replacePushedDatabase($copier, $definers, $to, $source, $dest, $rollbackDir, $rollbacks);
            } else {
                array_push($warnings, ...$this->createPushedDatabase($copier, $definers, $to, $source, $dest, $created));
            }
            $databases[$source] = $dest;
        }

        return new DatabaseCopyResult($databases, warnings: $warnings);
    }

    /**
     * The rollback is recorded only once its dump is complete, so a failed dump is never replayed into a target it did
     * not touch.
     *
     * @param  array<string, string>  $rollbacks
     */
    private function replacePushedDatabase(
        DatabaseCopier $copier,
        DefinerMap $definers,
        ModelsUser $to,
        string $source,
        string $dest,
        string $rollbackDir,
        array &$rollbacks,
    ): void {
        $rollbacks[$dest] = $copier->dumpRollback($dest, $rollbackDir);
        $copier->replaceDatabase($source, $dest, $rollbacks[$dest], $to, $definers);
    }

    /**
     * The target users the source users map to get the grants those had on the created database.
     *
     * @param  list<string>  $created
     * @return list<string>
     */
    private function createPushedDatabase(
        DatabaseCopier $copier,
        DefinerMap $definers,
        ModelsUser $to,
        string $source,
        string $dest,
        array &$created,
    ): array {
        MysqlDatabase::create(['user_id' => $to->id, 'database' => $dest]);
        $created[] = $dest;
        $copier->copyDatabase($source, $dest, $definers);

        return $copier->grantCreatedDatabase($source, $dest, $definers);
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
     * A first copy of the source's home and the images its run file names reach the target while both apps run, as a
     * Railpack build or base image transfer takes minutes; the copy once both are stopped moves only what changed.
     */
    private function provideIncomingImagesWhileRunning(string $fromHome, string $incomingDir, Project $toProject): ?HeldImage
    {
        $this->runPushProcess(
            Privileged::files()->makeDirectories($incomingDir),
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
        $excluded = ['.incoming', '.pre-push', CopyVolumes::STAGING, ...($isDindDest ? ['docker/'] : [])];

        $this->runPushProcess(
            Privileged::files()->mirrorExcluding($fromHome, $incomingDir, $excluded),
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
     * Move one directory inside an account home, as root and without following a symlink the source account planted:
     * -T, plus a refusal when either end is a link or the destination already exists.
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

        $this->runPushProcess(Privileged::files()->rename($from, $to), "Failed to move '{$from}' to '{$to}'");
    }

    public static function isDind(ModelsUser $user): bool
    {
        return $user->hasGitProject() || $user->getTemplate() === 'dind';
    }

    /**
     * The target keeps its own wp-config.php, with its database and salts, over the pushed one. Copied as the target
     * account, so a link in either tree reaches nothing the account could not.
     */
    private function keepTargetConfig(ModelsUser $to, KeptSite $kept, string $toHome, string $prePushDir): void
    {
        $config = $kept->install->configPath;
        if (! str_starts_with($config, $toHome.'/')) {
            throw new \RuntimeException("The target's wp-config.php is outside its home: {$config}");
        }
        $relative = substr($config, strlen($toHome) + 1);

        [$uid, $gid] = $to->linuxIds();
        $this->runPushProcess(
            Privileged::account()->copyReplacingAsAccount($uid, $gid, $prePushDir.'/'.$relative, $config),
            "Failed to keep the target's wp-config.php",
        );
    }

    private function keptWordPress(ModelsUser $from, ModelsUser $to, bool $isDindDest): ?KeptSite
    {
        $targetInstall = $isDindDest ? null : $this->wordPressInstall($to);
        if ($targetInstall === null) {
            return null;
        }
        $sourceInstall = $this->wordPressInstall($from);
        if ($sourceInstall === null) {
            return null;
        }

        return $this->siteCopy()->beforePush($from, $to, $targetInstall, $sourceInstall);
    }

    /** The project's WordPress: its own, or, for a copy with no record of its own yet, the source's under its home. */
    private function wordPressInstall(ModelsUser $user): ?Install
    {
        $install = $this->ownWordPressInstall($user);
        if ($install !== null) {
            return $install;
        }
        $source = $user->liveUser;

        return $source === null ? null : $this->copiedWordPressInstall($source, $user);
    }

    /**
     * The WordPress in the directory it was installed into, else at the main domain's document root, else the only one
     * below it, which is then recorded as the project's install.
     */
    private function ownWordPressInstall(ModelsUser $user): ?Install
    {
        $domain = $user->getMainDomain();
        if ($domain === null) {
            return null;
        }
        $home = rtrim($this->system->project($user)->homeDirPath(), '/');
        $documentRoot = rtrim($home.$domain->getDocumentRoot(), '/');
        $recorded = ClassicAppType::installPathOf($user);
        $install = $this->wordPressInstalls()->find($home, $documentRoot, $recorded);
        if ($install !== null && $install->dir !== rtrim($recorded ?? $documentRoot, '/')) {
            $this->classicAppType()->recordInstall($user, $install->dir);
        }

        return $install;
    }

    /** A copy's WordPress, which has no record of its own yet: the source's install, under the copy's home and domain. */
    private function copiedWordPressInstall(ModelsUser $source, ModelsUser $copy): ?Install
    {
        $install = $this->ownWordPressInstall($source);
        if ($install === null) {
            return null;
        }
        $sourceHome = rtrim($this->system->project($source)->homeDirPath(), '/');
        $copyHome = rtrim($this->system->project($copy)->homeDirPath(), '/');
        if (! str_starts_with($install->dir, $sourceHome.'/')) {
            return null;
        }
        $relative = self::rebasedDocumentRoot(
            substr($install->dir, strlen($sourceHome)),
            (string) $source->domain,
            (string) $copy->domain,
        );

        return $this->wordPressInstalls()->at($copyHome, $copyHome.$relative);
    }

    protected function siteCopy(): SiteCopy
    {
        return new SiteCopy;
    }

    protected function wordPressInstalls(): InstallLocator
    {
        return new InstallLocator($this->system);
    }

    protected function classicAppType(): ClassicAppType
    {
        return app(ClassicAppType::class);
    }

    protected function containerSiteCopy(): ContainerSiteCopy
    {
        return new ContainerSiteCopy(fn (ModelsUser $user): WordPress => WordPress::for($user, system: $this->system, skipPlugins: true));
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
            Privileged::files()->symlinksIn($incomingDir),
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
        return $this->system->runProcess(Privileged::files()->isSymlink($path))->isSuccessful();
    }

    private function cleanupPath(string $path): void
    {
        if (! $this->pathExistsAsRoot($path) && ! $this->pathIsSymlinkAsRoot($path)) {
            return;
        }

        $this->runPushProcess(Privileged::files()->removeTree($path), "Failed to remove '{$path}'");
    }

    /** Core's own: the queue worker writes the dumps and reads them back, with no root step in between. */
    public function prepareRollbackDir(string $directory): void
    {
        if (is_link($directory)) {
            throw new \RuntimeException("Refusing to use '{$directory}': it is a symlink.");
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Failed to prepare the push database rollback directory '{$directory}'");
        }
        chmod($directory, 0700);
    }

    private function removeRollbackDir(string $directory): void
    {
        if (is_dir($directory) && ! is_link($directory) && ! File::deleteDirectory($directory)) {
            throw new \RuntimeException("Failed to remove '{$directory}'");
        }
    }

    private function cloneOrCopy(
        ModelsUser $source,
        ModelsUser $dest,
        bool $asStaging,
        bool $copyDatabases = true,
        ?callable $log = null,
    ): ?DatabaseCopyResult {
        $copy = $this->newCopyAttempt($source, $dest, $asStaging, $copyDatabases);

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

    private function newCopyAttempt(ModelsUser $source, ModelsUser $dest, bool $asStaging, bool $copyDatabases): CopyAttempt
    {
        return new CopyAttempt(
            $source,
            $dest,
            $this->system->project($source),
            $this->system->project($dest),
            self::isDind($dest),
            $asStaging,
            $copyDatabases,
        );
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
        $this->assertWordPressCanBeCopied($copy->source, $copy->copyDatabases);
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
            $copy->destProject->repointCopiedAppFiles($copy->source);
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
        if (! $copy->isDindDest) {
            $this->pointCopiedWordPressAtItself($copy);
        }
    }

    /**
     * A copy of a WordPress whose own wp-config.php cannot be found is refused: it
     * would keep writing to the source's database. The copy records its own install.
     */
    private function pointCopiedWordPressAtItself(CopyAttempt $copy): void
    {
        $install = $this->copiedWordPressInstall($copy->source, $copy->dest);
        if ($install === null) {
            if ($this->wordPressInstall($copy->source) === null) {
                return;
            }
            throw new \RuntimeException(
                "The copy of '{$copy->source->username}' holds no wp-config.php of its WordPress, so it would keep using "
                ."the source's database; the copy is not finished."
            );
        }
        $this->siteCopy()->pointCopyAtItself($copy->dest, $copy->databaseResult, $install);
        $this->classicAppType()->recordInstall($copy->dest, $install->dir);
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
     * A classic WordPress copy is pointed at the databases copied for it, so one without them, or of a source whose
     * WordPress cannot be told apart, is refused before anything is copied.
     *
     * @return list<array{field: string, code: string, message: string}>
     */
    public function wordPressCopyProblems(ModelsUser $source, bool $copyDatabases): array
    {
        if (self::isDind($source)) {
            return [];
        }
        try {
            $install = $this->wordPressInstall($source);
        } catch (UnclearInstall $e) {
            return [['field' => 'project', 'code' => 'wordpress_install_unclear', 'message' => $e->getMessage()]];
        }
        if ($copyDatabases || $install === null) {
            return [];
        }

        return [[
            'field' => 'copy_databases',
            'code' => 'wordpress_copy_needs_databases',
            'message' => "Project '{$source->username}' runs WordPress, and a copy of it needs its own databases "
                ."or it would use the source's; leave copy_databases true.",
        ]];
    }

    private function assertWordPressCanBeCopied(ModelsUser $source, bool $copyDatabases): void
    {
        $problems = $this->wordPressCopyProblems($source, $copyDatabases);
        if ($problems !== []) {
            throw ProblemException::of($problems);
        }
    }

    /**
     * A first copy of the source's home and the images its run file names reach the copy while the source runs, as a
     * Railpack build or base image transfer takes minutes; the copy once it is stopped moves only what changed.
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
        if (($async['staging'] ?? null) === 'running') {
            throw ProjectBusyException::stagingRunning($user->username);
        }
        if (self::isPushing($user)) {
            throw ProjectBusyException::pushRunning($user->username);
        }
        self::assertNotBeingCopied($user);

        app(ProjectRebuild::class)->assertIdle($user);
    }

    /**
     * A staging copy or push runs as its destination's task and carries its flag there,
     * so the project it reads from is found by the source its task names.
     */
    private static function assertNotBeingCopied(ModelsUser $user): void
    {
        $copy = Task::query()
            ->whereIn('job_type', [CreateStaging::class, PushState::class])
            ->where('details->source', $user->username)
            ->whereNotIn('status', Task::TERMINAL_STATUSES)
            ->orderByDesc('id')
            ->first();
        if ($copy !== null) {
            throw ProjectBusyException::taskRunning($user->username, $copy);
        }
    }

    /**
     * 409 while a push into this project or the other of its staging pair is
     * queued or running: the flag is on the target, and the push reads its source.
     *
     * @throws ProjectBusyException
     */
    public static function assertNoPushInPair(ModelsUser $user): void
    {
        foreach ([$user, $user->liveUser, $user->stagingUser] as $side) {
            if ($side !== null && self::isPushing($side)) {
                throw ProjectBusyException::pushRunning($user->username);
            }
        }
    }

    /** The push flag a push sets on its target when it is accepted. */
    private static function isPushing(ModelsUser $user): bool
    {
        return ($user->asyncStatus()['push'] ?? null) === 'running';
    }

    /**
     * Both projects of a push idle, and neither with a database rename queued or running.
     *
     * @throws ProjectBusyException
     */
    public static function assertPushIdle(ModelsUser $from, ModelsUser $to): void
    {
        self::assertIdle($from);
        self::assertIdle($to);
        self::assertNoDatabaseRename($from, $to);
    }

    /**
     * 409 while a database rename of one of the projects is queued or running:
     * a push would copy or replace a database whose tables are being moved.
     *
     * @throws ProjectBusyException
     */
    private static function assertNoDatabaseRename(ModelsUser ...$users): void
    {
        $rename = Task::query()
            ->whereIn('username', array_map(static fn (ModelsUser $user): string => $user->username, $users))
            ->where('job_type', RenameMysqlDatabase::class)
            ->whereNotIn('status', Task::TERMINAL_STATUSES)
            ->orderBy('id')
            ->first();
        if ($rename !== null) {
            throw ProjectBusyException::taskRunning((string) $rename->username, $rename);
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
        $problems = self::stagingSourceProblems($source);

        if ($destPrefix !== null) {
            $problems = [...$problems, ...self::databaseNameProblems($source, $destPrefix, 'new_username')];
        }

        return $problems;
    }

    /**
     * @return list<array{field: string, code: string, message: string}>
     */
    private static function stagingSourceProblems(ModelsUser $source): array
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
        $problems = self::mappedDatabaseNameProblems($source, $sourcePrefix, $destPrefix, $field);

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

    /**
     * @return list<array{field: string, code: string, message: string}>
     */
    private static function mappedDatabaseNameProblems(ModelsUser $source, string $sourcePrefix, string $destPrefix, string $field): array
    {
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
     * An empty selection is not a whole-database push: it would drop every table the target holds alone.
     *
     * @param  list<array{database: string, name: string}>|null  $tables
     */
    private static function assertTablesSelectSomething(?array $tables): void
    {
        if ($tables === []) {
            throw new \RuntimeException('Selecting tables needs at least one table.', 422);
        }
    }

    /**
     * Without its files a container project pushes only the application database the engine made for it; a source
     * without one would stop and restart both apps to carry nothing.
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
        $problems = [...$problems, ...self::stagingPairProblems($from, $to)];

        return [...$problems, ...self::databaseNameProblems($from, $to->getMysqlPrefix(), 'target')];
    }

    /**
     * @return list<array{field: string, code: string, message: string}>
     */
    private static function stagingPairProblems(ModelsUser $from, ModelsUser $to): array
    {
        $problems = [];
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

        return $problems;
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
                $problems[] = self::tableProblem('database_not_found', "The source project does not own database '{$database}'.");

                continue;
            }

            $known[$database] ??= $sourceTables->names($database);
            if (! in_array($table, $known[$database], true)) {
                $problems[] = self::tableProblem('table_not_found', "Table '{$database}.{$table}' does not exist in the source project.");
            }
        }

        return $problems;
    }

    /**
     * @return array{field: string, code: string, message: string}
     */
    private static function tableProblem(string $code, string $message): array
    {
        return [
            'field' => 'tables',
            'code' => $code,
            'message' => $message,
        ];
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
        return $this->system->runProcess(Privileged::files()->isDirectory($path))->isSuccessful();
    }

    private function pathExistsAsRoot(string $path): bool
    {
        return $this->system->runProcess(Privileged::files()->exists($path))->isSuccessful();
    }

    /**
     * @return list<string>
     */
    private function listDockerVolumeEntriesAsRoot(string $volumesDir): array
    {
        $process = $this->system->runProcess(Privileged::files()->listNames($volumesDir));
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

        $process = $this->system->runProcess(Privileged::files()->diskUsageInBytes($path));
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
        $required = $this->homeBytesToPush($fromHome, $isDindDest);
        $required += $this->volumeBytesToPush($fromProject, $fromHome);
        $availableBytes = $this->availableBytesOn($toHome);

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

    private function homeBytesToPush(string $fromHome, bool $isDindDest)
    {
        $required = 0;
        $skipHomeEntries = ['.incoming', '.pre-push', CopyVolumes::STAGING];
        if ($isDindDest) {
            $skipHomeEntries[] = 'docker';
        }

        $listProcess = $this->system->runProcess(Privileged::files()->listAllNames($fromHome));
        if ($listProcess->isSuccessful()) {
            foreach (preg_split('/\R/', trim($listProcess->getOutput())) ?: [] as $entry) {
                $entry = trim($entry);
                if ($entry === '' || in_array($entry, $skipHomeEntries, true)) {
                    continue;
                }
                $required += $this->duBytesAsRoot($fromHome.'/'.$entry);
            }
        }

        return $required;
    }

    private function volumeBytesToPush(Project $fromProject, string $fromHome)
    {
        $required = 0;
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

        return $required;
    }

    private function availableBytesOn(string $toHome)
    {
        $dfProcess = $this->system->runProcess(Privileged::files()->diskFree($toHome));
        if (! $dfProcess->isSuccessful()) {
            throw new \RuntimeException('Could not determine available disk space on target home.');
        }

        $lines = preg_split('/\R/', trim($dfProcess->getOutput())) ?: [];
        $dataLine = $lines[count($lines) - 1] ?? '';
        $parts = preg_split('/\s+/', trim($dataLine));
        $availableKb = isset($parts[3]) ? (int) $parts[3] : 0;

        return $availableKb * 1024;
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
                Privileged::files()->removeTree($incomingDir),
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
            $this->cleanupAfterCommit($rollbackDir, fn () => $this->removeRollbackDir($rollbackDir)),
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
                Privileged::files()->removeTree($prePushDir),
                'Failed to remove .pre-push directory after successful push'
            );
        }
    }
}
