<?php

namespace App\System;

use App\Exceptions\ProblemException;
use App\Lib\Deploy\DetectAppPort;
use App\Models\Domain as DomainModel;
use App\Models\MysqlDatabase;
use App\Models\User as ModelsUser;
use App\System as EngineSystem;
use App\System\Project\Dind;
use App\System\Project\Dind\Paths;
use App\System\Project\HomeSwap;
use App\System\Services\Mysql;
use App\System\Services\Mysql\DatabaseCopier;
use App\System\Services\Mysql\DatabaseCopyResult;
use App\System\Services\Mysql\Tables;
use Illuminate\Support\Facades\Log;

class Projects
{
    private const int PROCESS_TIMEOUT_SECONDS = 86400;

    public function __construct(
        protected EngineSystem $system,
    ) {
    }

    public function clone(string $sourceUsername, string $targetUsername, bool $copyDatabases = true): void
    {
        $source = ModelsUser::findByUsernameOrFail($sourceUsername);
        $target = ModelsUser::findByUsernameOrFail($targetUsername);
        $this->cloneOrCopy($source, $target, asStaging: false, copyDatabases: $copyDatabases);
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
     * @param list<array{database: string, name: string}>|null $tables
     */
    public function pushToApp(
        string $sourceUsername,
        string $targetUsername,
        bool $files = true,
        bool $databases = true,
        ?array $tables = null,
    ): DatabaseCopyResult {
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
        $rollbackDir = $toHome . '/.pre-push-db';
        $isDindDest = self::isDind($to);
        $rollbacks = [];
        $createdDatabases = [];
        $tableReplacement = null;
        $result = new DatabaseCopyResult();

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

            if ($files) {
                $this->assertPushDiskSpace($fromProject, $fromHome, $toHome, $isDindDest);
            }

            $this->pauseAppForCopy($fromProject);
            $this->pauseAppForCopy($toProject);

            if ($databases && !$isDindDest) {
                if ($tables === null || $tables === []) {
                    $result = $this->pushDatabases($from, $to, $rollbackDir, $rollbacks, $createdDatabases);
                } else {
                    $this->prepareRollbackDir($rollbackDir);
                    $tableReplacement = $this->databaseCopier()->replaceTables($from, $to, $tables, $rollbackDir);
                    $result = $tableReplacement->result;
                }
            }

            if ($files) {
                $this->runPushProcess(
                    ['sudo', 'mkdir', '-p', $incomingDir],
                    'Failed to create .incoming directory'
                );
                $this->rsyncHomeIntoIncoming($fromHome, $incomingDir, $isDindDest);
                if (!$isDindDest) {
                    $this->rebaseIncomingDocumentRoot($from, $to, $incomingDir);
                }
                $this->copyVolumesToIncoming($fromProject, $toProject, $incomingDir, $isDindDest && $databases);
                $this->swapIncomingHome($toHome, $incomingDir, $prePushDir);
                $toProject->copyVolumesForClone()?->swapIncomingVolumes();
                $toProject->fixPermissions();
            }

            $this->startTargetAfterPush($to, $toProject);

            $this->resumeBoth($fromProject, $toProject);

            $this->cleanupPushSuccess($prePushDir, $incomingDir, $rollbackDir);
            $toProject->copyVolumesForClone()?->discardVolumeBackups();
            $this->markPushCompleted($to);

            return $result;
        } catch (\Throwable $e) {
            if ($tableReplacement !== null) {
                $this->databaseCopier()->restoreTables($tableReplacement);
            }
            $this->restorePushDatabases($rollbacks, $createdDatabases, $to);
            $this->cleanupIncomingDir($incomingDir);
            $this->resumeBoth($fromProject, $toProject);
            $this->cleanupPath($rollbackDir);

            $details = $to->getDetails();
            if (($details['async_status']['push'] ?? null) !== 'failed') {
                $this->markPushFailed($to, $e->getMessage());
            }

            if ($e instanceof \RuntimeException) {
                throw $e;
            }

            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * The target's own databases, each replaced with the source's data under its
     * rollback dump. A source database the target does not own is created
     * without the source's grants and recorded as a warning.
     *
     * @param array<string, string> $rollbacks destination database => rollback dump
     * @param list<string>          $created
     */
    private function pushDatabases(
        ModelsUser $from,
        ModelsUser $to,
        string $rollbackDir,
        array &$rollbacks,
        array &$created,
    ): DatabaseCopyResult {
        $copier = $this->databaseCopier();
        $sourcePrefix = $from->getMysqlPrefix();
        $targetPrefix = $to->getMysqlPrefix();
        $owned = $to->mysqlDatabases()->pluck('database')->all();

        if ($from->mysqlDatabases->isEmpty()) {
            return new DatabaseCopyResult();
        }

        $this->prepareRollbackDir($rollbackDir);

        $databases = [];
        $warnings = [];
        foreach ($from->mysqlDatabases as $row) {
            $source = $row->database;
            $dest = DatabaseCopier::mapName($source, $sourcePrefix, $targetPrefix);
            if (in_array($dest, $owned, true)) {
                $rollbacks[$dest] = DatabaseCopier::rollbackPath($rollbackDir, $dest);
                $copier->replaceDatabase($source, $dest, $rollbackDir, $to);
            } else {
                MysqlDatabase::create(['user_id' => $to->id, 'database' => $dest]);
                $created[] = $dest;
                $copier->copyDatabase($source, $dest);
                $warnings[] = "Created database '{$dest}' without grants: the target does not own that database.";
            }
            $databases[$source] = $dest;
        }

        return new DatabaseCopyResult($databases, warnings: $warnings);
    }

    /**
     * @param array<string, string> $rollbacks
     * @param list<string>          $created
     */
    private function restorePushDatabases(array $rollbacks, array $created, ModelsUser $to): void
    {
        $mysql = $this->system->mysql();
        foreach ($rollbacks as $database => $dump) {
            try {
                $mysql->restoreFromGzip($database, $dump);
            } catch (\Throwable $e) {
                Log::warning("Push rollback failed for database '{$database}': " . $e->getMessage());
            }
        }

        foreach ($created as $database) {
            try {
                if ($mysql->databases()->databaseExists($database)) {
                    $mysql->databases()->deleteDatabase($database);
                }
                $to->mysqlDatabases()->where('database', $database)->delete();
            } catch (\Throwable $e) {
                Log::warning("Push cleanup failed for database '{$database}': " . $e->getMessage());
            }
        }
    }

    private function startTargetAfterPush(ModelsUser $to, Project $toProject): void
    {
        if (($to->hasGitProject() || $to->getTemplate() === 'dind') && $toProject->hasUserApp()) {
            $start = $toProject->startUserApp();
            if (($start['exit_code'] ?? 1) !== 0) {
                $output = trim(($start['stderr'] ?? '') . ' ' . ($start['stdout'] ?? ''));
                throw new \RuntimeException(
                    $output !== '' ? $output : 'Failed to start target application after push.'
                );
            }

            return;
        }

        $toProject->start();
    }

    private function rsyncHomeIntoIncoming(string $fromHome, string $incomingDir, bool $isDindDest): void
    {
        $cmd = [
            'sudo', 'rsync', '-a', '--delete',
            '--exclude=.incoming',
            '--exclude=.pre-push',
            '--exclude=.pre-push-db',
        ];
        if ($isDindDest) {
            $cmd[] = '--exclude=docker/';
        }
        $cmd[] = rtrim($fromHome, '/') . '/';
        $cmd[] = rtrim($incomingDir, '/') . '/';

        $this->runPushProcess($cmd, 'Failed to rsync source home into .incoming');
    }

    private function copyVolumesToIncoming(
        Project $fromProject,
        Project $toProject,
        string $incomingDir,
        bool $copyVolumes,
    ): void {
        if (!$copyVolumes) {
            return;
        }

        $incomingProject = rtrim($incomingDir, '/') . '/project';
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
    }

    private function rebaseIncomingDocumentRoot(ModelsUser $from, ModelsUser $to, string $incomingDir): void
    {
        if (!str_starts_with(self::sourceDocumentRoot($from), "/{$from->domain}/")) {
            return;
        }

        $this->moveAccountDirectory(
            rtrim($incomingDir, '/') . '/' . $from->domain,
            rtrim($incomingDir, '/') . '/' . $to->domain,
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
            $this->moveAccountDirectory($home . '/' . $source->domain, $home . '/' . $dest->domain);
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
        if (!str_starts_with($documentRoot, $prefix)) {
            return $documentRoot;
        }

        return "/{$destDomain}/" . substr($documentRoot, strlen($prefix));
    }

    /**
     * Move one directory inside an account home, as root and without following
     * a symlink the source account planted: -T, plus a refusal when either end
     * is a link or the destination already exists.
     */
    public function moveAccountDirectory(string $from, string $to): void
    {
        if (!$this->pathExistsAsRoot($from) && !$this->pathIsSymlinkAsRoot($from)) {
            return;
        }

        if ($this->pathIsSymlinkAsRoot($from) || !$this->pathIsDirectoryAsRoot($from)) {
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

    private function databaseCopier(): DatabaseCopier
    {
        return new DatabaseCopier($this->system->mysql());
    }

    private function pathIsSymlinkAsRoot(string $path): bool
    {
        return $this->system->runProcess(['sudo', 'test', '-L', $path])->isSuccessful();
    }

    private function cleanupPath(string $path): void
    {
        if (!$this->pathExistsAsRoot($path) && !$this->pathIsSymlinkAsRoot($path)) {
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
                if ($copyDatabases) {
                    $this->prepareVolumes($destProject);
                    $srcCount = $this->countVolumeDataDirs($sourceProject);
                    $copied = $destProject->copyVolumeDataFrom($sourceProject);
                    if ($srcCount > 0 && $copied !== $srcCount) {
                        throw new \RuntimeException(
                            "Failed to copy DinD volume data from source ({$copied} of {$srcCount} volumes copied)."
                        );
                    }
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
            $isDindDest = self::isDind($dest);
            if (!$isDindDest) {
                $this->rebaseDocumentRoot($destProject, $source, $dest, $domainModel);
            }
            $databaseResult = null;
            if ($copyDatabases && !$isDindDest) {
                $databaseResult = $this->databaseCopier()->copyProjectDatabases(
                    $source,
                    $dest,
                    $log ?? static function (string $line): void {
                    }
                );
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

            return $databaseResult;
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
                'field'   => 'project',
                'code'    => 'project_pending',
                'message' => "Project '{$source->username}' is pending.",
            ];
        }
        if ($source->isStaging()) {
            $problems[] = [
                'field'   => 'project',
                'code'    => 'project_is_staging',
                'message' => "Project '{$source->username}' is a staging project and cannot create staging.",
            ];
        }
        if ($source->exists && $source->stagingUser()->exists()) {
            $problems[] = [
                'field'   => 'project',
                'code'    => 'project_has_staging',
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
        if (!$source->exists) {
            return [];
        }

        $sourcePrefix = $source->getMysqlPrefix();
        $problems = [];

        foreach ($source->mysqlDatabases as $row) {
            $name = $row->database;
            $mapped = DatabaseCopier::mapName($name, $sourcePrefix, $destPrefix);
            if (!Mysql::isValidDatabaseName($name)) {
                $problems[] = [
                    'field'   => $field,
                    'code'    => 'database_name_unsupported',
                    'message' => "Database '{$name}' is outside the name grammar the engine can copy.",
                ];
            } elseif (strlen($mapped) > 64) {
                $problems[] = [
                    'field'   => $field,
                    'code'    => 'database_name_too_long',
                    'message' => "Database '{$name}' maps to '{$mapped}', longer than 64 characters.",
                ];
            }
        }

        foreach ($source->mysqlUsers as $row) {
            $name = $row->user;
            $mapped = DatabaseCopier::mapName($name, $sourcePrefix, $destPrefix);
            if (strlen($mapped) > 32) {
                $problems[] = [
                    'field'   => $field,
                    'code'    => 'database_user_name_too_long',
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
                'field'   => 'target',
                'code'    => 'target_template_mismatch',
                'message' => "Projects '{$from->username}' ({$left}) and '{$to->username}' ({$right}) have different templates.",
            ];
        }
        if ($from->status === 'pending') {
            $problems[] = [
                'field'   => 'project',
                'code'    => 'project_pending',
                'message' => "Project '{$from->username}' is pending.",
            ];
        }
        if ($to->status === 'pending') {
            $problems[] = [
                'field'   => 'target',
                'code'    => 'target_pending',
                'message' => "Project '{$to->username}' is pending.",
            ];
        }
        if ((int) $from->id === (int) $to->id) {
            $problems[] = [
                'field'   => 'target',
                'code'    => 'target_same_project',
                'message' => 'Cannot push to the same project.',
            ];
        }
        $paired = (int) $from->staging === (int) $to->id
            || (int) $to->staging === (int) $from->id;
        if (!$paired) {
            $problems[] = [
                'field'   => 'target',
                'code'    => 'target_not_staging_pair',
                'message' => "Projects '{$from->username}' and '{$to->username}' are not a staging pair.",
            ];
        }

        return [...$problems, ...self::databaseNameProblems($from, $to->getMysqlPrefix(), 'target')];
    }

    /**
     * Every selected table must name a database the source owns and a table that
     * database holds, checked before the push is queued.
     *
     * @param list<array{database: string, name: string}> $tables
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
            if (!is_string($database) || !is_string($table)) {
                continue;
            }

            if (!in_array($database, $owned, true)) {
                $problems[] = [
                    'field'   => 'tables',
                    'code'    => 'database_not_found',
                    'message' => "The source project does not own database '{$database}'.",
                ];
                continue;
            }

            $known[$database] ??= $sourceTables->names($database);
            if (!in_array($table, $known[$database], true)) {
                $problems[] = [
                    'field'   => 'tables',
                    'code'    => 'table_not_found',
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
        (new HomeSwap($this->system, rtrim($toHome, '/'), self::PROCESS_TIMEOUT_SECONDS))->swap(
            $incomingDir,
            $prePushDir,
            ['docker', '.incoming', '.pre-push', '.pre-push-db'],
            'push swap',
        );
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

    private function cleanupPushSuccess(string $prePushDir, string $incomingDir, string $rollbackDir): void
    {
        if ($this->pathIsDirectoryAsRoot($prePushDir)
            || $this->pathExistsAsRoot($prePushDir)) {
            $this->runPushProcess(
                ['sudo', 'rm', '-rf', $prePushDir],
                'Failed to remove .pre-push directory after successful push'
            );
        }

        $this->cleanupIncomingDir($incomingDir);
        $this->cleanupPath($rollbackDir);
    }
}
