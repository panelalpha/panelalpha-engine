<?php

namespace App\System;

use App\Integrations\Statistics\Statistics;
use App\Integrations\Tunnels\Cloudflare;
use App\Integrations\Tunnels\PanelAlphaConnect;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Project\ApiAccess;
use App\Models\Backup as BackupRecord;
use App\Models\Domain as DomainModel;
use App\Models\ProxyRule;
use App\Models\User as ModelsUser;
use App\System as EngineSystem;
use App\System\Project\Backup;
use App\System\Project\Cron;
use App\System\Project\Deployment\DeploymentWorkflow;
use App\System\Project\Deployment\RollBackProject;
use App\System\Project\Deployment\TemplateDeployMechanics;
use App\System\Project\Dind;
use App\System\Project\Dind\CopiedImages;
use App\System\Project\Dind\CopiedRunFile;
use App\System\Project\Dind\CopyVolumes;
use App\System\Project\Dind\HeldImage;
use App\System\Project\Domain as DomainProject;
use App\System\Project\FileManager;
use App\System\Project\Ftp;
use App\System\Project\Git as ProjectGit;
use App\System\Project\Php;
use App\System\Project\PhpHosting;
use App\System\Project\Runtime;
use App\System\Project\Settings;
use App\System\Project\Sftp;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class Project
{
    /** rsync's exit status when a file left the source while it was being copied. */
    public const int RSYNC_VANISHED_FILES = 24;

    private Runtime $runtime;

    private ?DeploymentWorkflow $templateDeployment = null;

    public function __construct(
        private readonly EngineSystem $system,
        private readonly ModelsUser $model,
    ) {
        $this->runtime = self::resolveRuntime($this);
    }

    private static function resolveRuntime(self $project): Runtime
    {
        $model = $project->model();
        if ($model->hasGitProject() || $model->getTemplate() === 'dind') {
            return new Dind($project);
        }

        return new PhpHosting($project);
    }

    public function system(): EngineSystem
    {
        return $this->system;
    }

    public function model(): ModelsUser
    {
        return $this->model;
    }

    public function userModel(): ModelsUser
    {
        return $this->model;
    }

    public function username(): string
    {
        return $this->model->username;
    }

    public function kind(): string
    {
        return $this->runtime->kind();
    }

    /** A DinD runtime: the `dind` template or any git project, whatever its template. */
    public function runsInDind(): bool
    {
        return $this->runtime instanceof Dind;
    }

    public function runtime(): Runtime
    {
        return $this->runtime;
    }

    public function homeDirPath(): string
    {
        return $this->system->projectHomeDirPath($this->username());
    }

    public function projectDirPath(): string
    {
        return $this->system->projectDirPath($this->username());
    }

    public function composeFilePath(): string
    {
        return $this->runtime->composeFilePath();
    }

    public function exists(): bool
    {
        return $this->runtime->exists();
    }

    public function cron(): Cron
    {
        return new Cron($this);
    }

    public function settings(): Settings
    {
        return new Settings($this);
    }

    public function fileManager(): FileManager
    {
        return new FileManager($this);
    }

    public function php(): Php
    {
        return new Php($this);
    }

    public function ftp(): Ftp
    {
        return new Ftp($this);
    }

    public function sftp(): Sftp
    {
        return new Sftp($this);
    }

    public function git(?string $path = null): ProjectGit
    {
        return new ProjectGit($this, $path);
    }

    public function backup(BackupRecord $record): Backup
    {
        return new Backup($this, $record);
    }

    public function domain(DomainModel $domainModel): DomainProject
    {
        return new DomainProject($this, $domainModel);
    }

    public function resolvePath(string $path): string
    {
        return $this->fileManager()->resolvePath($path);
    }

    /** A dind account has no `php` service and never reads this crontab. */
    public function reloadCron(): void
    {
        if ($this->runtime instanceof PhpHosting) {
            $this->runtime->reloadCron();
        }
    }

    /**
     * @param  list<string>  $args
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    public function runWpCli(array $args, ?string $service = null): array
    {
        if ($this->runtime instanceof Dind) {
            return $this->runtime->wordPressCli()->run($args, $service);
        }
        if (! $this->runtime instanceof PhpHosting) {
            throw new \RuntimeException('WP-CLI is not available for this project.');
        }

        return $this->runtime->phpRuntime()->runWpCli($args);
    }

    public function syncPhpHandlersScripts(): void
    {
        if ($this->runtime instanceof PhpHosting) {
            $this->runtime->phpRuntime()->syncPhpHandlersScripts();
        }
    }

    public function syncServices(): void
    {
        if ($this->runtime instanceof PhpHosting) {
            $this->runtime->syncServices();
        }
    }

    public function reloadApache(): void
    {
        if ($this->runtime instanceof PhpHosting) {
            $this->runtime->reloadApache();
        }
    }

    public function enableApacheMod(string $mod): void
    {
        if ($this->runtime instanceof PhpHosting) {
            $this->runtime->enableApacheMod($mod);
        }
    }

    public function disableApacheMod(string $mod): void
    {
        if ($this->runtime instanceof PhpHosting) {
            $this->runtime->disableApacheMod($mod);
        }
    }

    public function createDomainConfig(DomainModel $domain): void
    {
        $this->runtime->createDomainConfig($domain);
    }

    public function reloadWebserver(): void
    {
        $this->runtime->reloadWebserver();
    }

    public function rebuildDomain(DomainModel $domain): void
    {
        $this->runtime->rebuildDomain($domain);
    }

    public function deleteDomainConfig(string $domainName): void
    {
        $this->runtime->deleteDomainConfig($domainName);
    }

    public function provision(): void
    {
        if ($this->exists()) {
            throw new \LogicException("Project '{$this->username()}' already has an outer compose file.");
        }

        try {
            $this->createDirectories();
            $this->syncLinuxUser();
            $this->configureQuota();

            $this->runtime->materialize();
            $this->runtime->start();
            $this->runtime->awaitReady();

            $this->fixPermissions();
        } catch (\Throwable $failure) {
            $this->rollbackProvision($failure);
        }
    }

    public function deprovision(): void
    {
        $this->runtime->remove();
        $this->tearDownLinuxIsolation();
    }

    public function delete(): void
    {
        $this->deprovision();
    }

    /**
     * Product delete: every resource owned by this Project, including the users row.
     * Distinct from delete()/deprovision(), which tear down host isolation only.
     */
    public function destroy(): void
    {
        $this->assertDeletable();
        $user = $this->model;

        $this->deleteBackups($user);
        $this->tearDownCloudflare($user);
        PanelAlphaConnect::releaseProjectSites($user);
        $this->deleteProxyRules($user->username);
        $this->deleteDomains($user);
        $this->deleteSftpLogins($user);
        $this->tearDownHosting();
        $this->deleteFtpLogins($user);
        $this->deleteMysqlDatabasesAndUsers($user);
        $this->deleteOwnedRows($user);
        DeployLogger::deleteUserLogs($user->username);
        $this->deleteAccountRow($user);
    }

    private function deleteBackups(ModelsUser $user): void
    {
        foreach ($user->backups()->with('container')->get() as $record) {
            $backupId = $record->id;
            $this->backup($record)->delete();

            if (BackupRecord::query()->find($backupId) !== null) {
                throw new \RuntimeException("Backup {$backupId} storage delete failed");
            }
        }
    }

    /** Runs while the API token and the domains still exist; a failure is only logged. */
    private function tearDownCloudflare(ModelsUser $user): void
    {
        try {
            Cloudflare::teardownProject($user);
        } catch (\Throwable $e) {
            Log::warning(
                "Cloudflare project teardown failed for {$user->username}: ".$e->getMessage()
            );
        }
    }

    /**
     * Runs before the heavy teardown, so a failed later step cannot leave the rules
     * behind once the user row is gone (the FK cascade needs the username set).
     */
    private function deleteProxyRules(string $username): void
    {
        ProxyRule::forUser($username)->delete();
        ProxyRule::pruneOrphanUserRules();
    }

    private function deleteDomains(ModelsUser $user): void
    {
        $domainNames = $user->domains->pluck('domain')->all();
        $user->domains()->delete();
        $this->system->webserver()->deleteDomainsConfigs($domainNames);
        $this->forgetDomainsStatistics($domainNames);
        if (! config('env.KEEP_WEBSERVER_LOGS_FOR_DELETED_DOMAINS')) {
            $this->system->webserver()->deleteDomainsLogsDirs($domainNames);
        }
    }

    /**
     * Forgets each domain's statistics so the next project given the same domain
     * starts without its traffic. A failure for one domain is logged and skipped.
     *
     * @param  list<string>  $domainNames
     */
    protected function forgetDomainsStatistics(array $domainNames): void
    {
        foreach ($domainNames as $name) {
            try {
                app(Statistics::class)->forgetDomain($name);
            } catch (\Throwable $e) {
                Log::warning("Statistics cleanup during project delete failed for {$name}: ".$e->getMessage());
            }
        }
    }

    /**
     * Not best-effort: a login left behind would open the home of the next
     * project with this name. A failure keeps the rows for a second delete.
     */
    private function deleteFtpLogins(ModelsUser $user): void
    {
        /** @var string[] $ftpAccounts */
        $ftpAccounts = $user->ftpAccounts->pluck('user')->all();
        $this->ftp()->deleteMany($ftpAccounts);
    }

    /**
     * SFTP logins live in one host-wide logins.conf, rebuilt without these rows so none stays valid for the
     * next account given this uid and home. Best-effort: a host without the sftp profile must still delete.
     * Runs before the OS user and the home are removed: open sessions end first and cannot log in again, so
     * none keeps writing into the home while it is removed.
     */
    private function deleteSftpLogins(ModelsUser $user): void
    {
        $logins = $user->sftpAccounts()->pluck('username')->all();
        if ($logins === []) {
            return;
        }

        $this->system->sftp()->endSessions($logins);
        $user->sftpAccounts()->delete();

        try {
            $this->sftp()->rebuild();
        } catch (\Throwable $e) {
            Log::warning(
                "SFTP logins rebuild failed after deleting {$user->username}: ".$e->getMessage()
            );
        }
    }

    /**
     * Not best-effort: AppDatabase adopts an existing `<prefix>app` database,
     * so one left behind is handed to the next project with this name.
     */
    private function deleteMysqlDatabasesAndUsers(ModelsUser $user): void
    {
        /** @var string[] $mysqlDatabases */
        $mysqlDatabases = $user->mysqlDatabases->pluck('database')->all();
        /** @var string[] $mysqlUsers */
        $mysqlUsers = $user->mysqlUsers->pluck('user')->all();
        if ($mysqlDatabases === [] && $mysqlUsers === []) {
            return;
        }

        $mysql = $this->system->mysql();
        foreach ($mysqlDatabases as $database) {
            if ($mysql->databases()->databaseExists($database)) {
                $mysql->databases()->deleteDatabase($database);
            }
        }
        foreach ($mysqlUsers as $mysqlUser) {
            if ($mysql->users()->userExists($mysqlUser)) {
                $mysql->users()->deleteUser($mysqlUser);
            }
        }
    }

    /** The hook_deliveries rows go with deploy_hooks through the DB-level cascade. */
    private function deleteOwnedRows(ModelsUser $user): void
    {
        $user->mysqlUsers()->delete();
        $user->mysqlDatabases()->delete();
        $user->ftpAccounts()->delete();
        $user->deployHooks()->delete();
    }

    /** A live project goes after its staging copy, never before it. */
    public function assertDeletable(): void
    {
        $user = $this->model;
        if ($user->stagingUser()->exists()) {
            throw new \Exception(
                "Cannot delete project '{$user->username}' while staging '{$user->stagingUser->username}' exists. Delete the staging project first."
            );
        }
    }

    /**
     * The row goes before the proxy rebuild, and the rebuild is best-effort (it defers
     * the reload when sites-http is not running), so a down proxy cannot keep the row.
     */
    public function deleteAccountRow(ModelsUser $user): void
    {
        $user->delete();
        try {
            $this->system->rebuildDomains();
        } catch (\Throwable $e) {
            Log::warning("Webserver rebuild after deleting '{$user->username}' failed: ".$e->getMessage());
        }
    }

    public function tearDownHosting(): void
    {
        try {
            if ($this->exists()) {
                $this->deprovision();

                return;
            }
        } catch (\Exception $e) {
            Log::warning(
                "Could not deprovision project for user '{$this->username()}': ".$e->getMessage(),
                ['exception' => $e],
            );
        }

        $this->removeDindRuntimeQuietly();
        $this->tearDownLinuxIsolation();
    }

    private function removeDindRuntimeQuietly(): void
    {
        if (! $this->runtime instanceof Dind) {
            return;
        }

        try {
            $this->runtime->remove();
        } catch (\Exception $e) {
            Log::warning(
                "Could not remove DinD runtime for user '{$this->username()}': ".$e->getMessage(),
                ['exception' => $e],
            );
        }
    }

    /** The DinD deploy pipeline; null for a project on a plain template. */
    public function deployment(): ?DeploymentWorkflow
    {
        return $this->runtime instanceof Dind ? $this->runtime->deployment() : null;
    }

    /**
     * Run a project's first deploy. A template project goes through the same
     * workflow as DinD, with its own mechanics, and is rolled back on failure.
     */
    public function runDeployment(?DeployLogger $deployLogger = null, ?callable $beforeRollback = null): void
    {
        ($this->deployment() ?? $this->templateDeployment())->run($deployLogger, $beforeRollback);
    }

    private function templateDeployment(): DeploymentWorkflow
    {
        return $this->templateDeployment ??= DeploymentWorkflow::forMechanics(
            new TemplateDeployMechanics($this),
            new RollBackProject,
            explainNoiseOnlyOutput: false,
        );
    }

    /**
     * Rebuild the project from its source. Only a DinD project has a source to
     * re-ingest; a template project has its host side recreated instead.
     */
    public function rebuildFromSource(?DeployLogger $deployLogger = null, ?string $zipPath = null): void
    {
        if ($this->runtime instanceof Dind) {
            $this->deployment()->rebuildFromSource($deployLogger, $zipPath);

            return;
        }

        $this->recreateHostSideUnderDeployLock();
    }

    /**
     * Holds the lock a DinD rebuild takes through its deploy log: a delete holds it
     * too, and this would otherwise recreate the account that delete is removing.
     */
    private function recreateHostSideUnderDeployLock(): void
    {
        $lock = DeployLock::acquireFor($this->username());
        try {
            $this->prepareLinuxIsolation();
            $this->recreateOuterCompose();
        } finally {
            $lock->release();
        }
    }

    public function prepareLinuxIsolation(): void
    {
        if ($this->isRunning()) {
            $this->syncLinuxUser();

            return;
        }

        $this->createHomeDir();
        $this->createProjectDir();
        $this->syncLinuxUser();
        $this->configureQuota();
    }

    public function recreateOuterCompose(): void
    {
        if ($this->isRunning()) {
            return;
        }

        $this->createFromTemplate();
        $this->buildIfMissing();
        $this->down();
        $this->up();
    }

    public function importProjectArchive(string $zipPath): void
    {
        $this->requireDindRuntime()->importProjectArchive($zipPath);
    }

    public function preCheckUserApp(): void
    {
        $this->requireDindRuntime()->preCheckFromSources();
    }

    public function cloneUserApp(): void
    {
        (new Dind\Source\GitRepository($this->requireDindRuntime()))->cloneConfiguredRepository();
    }

    public function prepareUserAppFromSources(): void
    {
        if (! $this->runtime instanceof Dind) {
            return;
        }

        $this->runtime->prepareFromSources();
    }

    public function rebuildDomains(): void
    {
        if ($this->runtime instanceof PhpHosting) {
            $this->runtime->rebuildDomains();
        }
    }

    public function deleteAllDomainsConfigs(): void
    {
        if ($this->runtime instanceof PhpHosting) {
            $this->runtime->deleteAllDomainsConfigs();
        }
    }

    /**
     * @return list<string>
     */
    public function listDomains(): array
    {
        if ($this->runtime instanceof PhpHosting) {
            return $this->runtime->fpmApache()?->listDomains($this->runtime) ?? [];
        }

        return [];
    }

    /**
     * @param  list<string>  $domainNames
     */
    public function deleteDomainsConfigs(array $domainNames): void
    {
        if ($this->runtime instanceof PhpHosting) {
            $this->runtime->fpmApache()?->deleteDomainsConfigs($this->runtime, $domainNames);
        }
    }

    public function start(): void
    {
        $this->runtime->start();
        $this->runtime->awaitReady();
    }

    public function stop(): void
    {
        $this->runtime->stop();
    }

    public function up(): void
    {
        $this->start();
    }

    public function down(): void
    {
        $this->stop();
    }

    public function createFromTemplate(): void
    {
        $this->runtime->materialize();
    }

    public function isRunning(): bool
    {
        return $this->runtime->isRunning();
    }

    public function waitForAllRunning(int $tries = 12, int $intervalSeconds = 5): void
    {
        $this->runtime->awaitReady($tries, $intervalSeconds);
    }

    public function build(): void
    {
        $this->runtime->build();
    }

    public function buildIfMissing(): void
    {
        $this->runtime->buildIfMissing();
    }

    /**
     * Whether the account has an application to start. A DinD account made from
     * the template alone has none until a deploy sets its strategy.
     */
    public function hasUserApp(): bool
    {
        return $this->runtime instanceof Dind && $this->runtime->app() !== null;
    }

    /**
     * $forceRecreate recreates every container, so none keeps a mount of files or volumes put back under it.
     *
     * @return array{stdout: string, stderr: string, exit_code: ?int}
     */
    public function startUserApp(bool $forceRecreate = false): array
    {
        if (! $this->runtime instanceof Dind) {
            throw new \RuntimeException('startUserApp is not available for this project type.');
        }

        $app = $this->runtime->app();
        if ($app === null) {
            throw new \RuntimeException('Cannot start application before deploy strategy is set.');
        }

        return $app->start($forceRecreate);
    }

    /**
     * Why the user app just started is not running, read as a deploy reads it
     * ({@see Dind\AppHealth::startFailure()}); null when it runs or there is none.
     */
    public function startedAppFailure(): ?string
    {
        if (! $this->hasUserApp() || ! $this->runtime instanceof Dind) {
            return null;
        }

        return $this->runtime->appHealth()->startFailure();
    }

    public function syncGeneratedProxyRules(): void
    {
        if (! $this->runtime instanceof Dind) {
            return;
        }
        $this->runtime->networking()->detectAndCreateProxyRules($this->model);
    }

    public function abortRunningDeploy(bool $stopInnerDocker = true, bool $removeVolumes = false): void
    {
        if ($this->runtime instanceof Dind) {
            $this->runtime->abortRunningDeploy($stopInnerDocker, $removeVolumes);
        }
    }

    public function cancelRunningDeploy(): void
    {
        if ($this->runtime instanceof Dind) {
            $this->runtime->cancelRunningDeploy();
        }
    }

    public function copyVolumeDataFrom(self $source): int
    {
        $volumes = $this->copyVolumesForClone();
        if ($volumes === null) {
            return 0;
        }

        return $volumes->copyVolumeDataFrom($source->homeDirPath());
    }

    public function copyVolumeDataToIncoming(self $source): int
    {
        $volumes = $this->copyVolumesForClone();
        if ($volumes === null) {
            return 0;
        }

        return $volumes->copyVolumeDataToIncoming($source->homeDirPath());
    }

    /**
     * A warning for each anonymous volume with data this project's copy
     * leaves out ({@see CopyVolumes::anonymousVolumeWarnings()}).
     *
     * @return list<string>
     */
    public function anonymousVolumeWarnings(): array
    {
        return $this->copyVolumesForClone()?->anonymousVolumeWarnings() ?? [];
    }

    public function copyVolumesForClone(): ?CopyVolumes
    {
        if ($this->runtime()->kind() !== 'dind') {
            return null;
        }

        if (! $this->runtime instanceof Dind) {
            return null;
        }
        $app = $this->runtime->app();
        if ($app === null) {
            return null;
        }

        return $app->copyVolumes();
    }

    /**
     * Point a run file copied from $source at this project's own database and
     * address ({@see CopiedRunFile}): the one at $path, or the project's own.
     */
    public function repointCopiedRunFile(ModelsUser $source, ?string $path = null): void
    {
        if ($this->runtime instanceof Dind) {
            (new CopiedRunFile($this->runtime))->repointFrom($source, $path ?? $this->runtime->userAppComposeFilePath());
        }
    }

    /**
     * Store the env vars the files copied from $source were written with, so
     * the next rebuild writes them again instead of dropping or reverting them.
     */
    public function adoptCopiedEnvVars(ModelsUser $source): void
    {
        if (! $this->runtime instanceof Dind) {
            return;
        }
        $details = ModelsUser::copiedEnvDetails($source->getDetails());
        if (is_array($details['env_vars'])) {
            $details['env_vars'] = (new CopiedRunFile($this->runtime))
                ->repointedEnvVars($source, ApiAccess::withoutReservedKeys($details['env_vars']));
        }
        if ($this->holdsDetails($details)) {
            return;
        }
        $this->model->setDetails($details);
        $this->model->save();
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function holdsDetails(array $details): bool
    {
        $current = $this->model->getDetails();
        foreach ($details as $key => $value) {
            if (($current[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the images a run file copied into this project names into its account
     * ({@see CopiedImages}): the one at $path, or the project's own.
     */
    public function provideCopiedImages(?string $path = null): void
    {
        if ($this->runtime instanceof Dind && $this->hasUserApp()) {
            (new CopiedImages($this->runtime))->provide($path ?? $this->runtime->userAppComposeFilePath());
        }
    }

    /**
     * The image this project's account has under the tag a copied Railpack run
     * file at $path names, held aside before {@see provideCopiedImages()}
     * builds it again; null when there is none.
     */
    public function holdCopiedImage(string $path): ?HeldImage
    {
        if ($this->runtime instanceof Dind && $this->hasUserApp()) {
            return (new CopiedImages($this->runtime))->holdRailpackImage($path);
        }

        return null;
    }

    /**
     * $sourceRunning for a first copy taken while the source app still runs, so the one taken once
     * it is stopped moves only what changed: a file the app removes meanwhile is not an error.
     */
    public function copyHomeDirFrom(self $source, bool $sourceRunning = false): void
    {
        $src = rtrim($source->homeDirPath(), '/').'/';
        $dest = rtrim($this->homeDirPath(), '/').'/';

        $excludes = $this->isDindTemplate() ? ['--exclude=docker/'] : [];
        $this->runCopyProcess(
            ['sudo', 'rsync', '-a', '--delete', ...$excludes, $src, $dest],
            'Failed to rsync home directory from source',
            $sourceRunning ? [self::RSYNC_VANISHED_FILES] : [],
        );

        $this->fixPermissions();
    }

    public function copyProjectConfigFrom(self $source): void
    {
        $srcProject = rtrim($source->projectDirPath(), '/');
        $destProject = rtrim($this->projectDirPath(), '/');

        $subdirs = ['redis/conf.d', 'php-fpm/pool.d'];
        foreach ($subdirs as $subdir) {
            $srcDir = "{$srcProject}/{$subdir}";
            $destDir = "{$destProject}/{$subdir}";
            if (! is_dir($srcDir)) {
                continue;
            }
            $this->runCopyProcess([
                'sudo', 'rsync', '-a', '--delete',
                rtrim($srcDir, '/').'/',
                rtrim($destDir, '/').'/',
            ], "Failed to rsync project config '{$subdir}' from source");
        }

        $this->system->runProcess(['sudo', 'chown', '-R', 'www-data:www-data', $destProject]);
    }

    public function createDirectories(): void
    {
        $this->createHomeDirectory();
        $this->createEngineDirectory();
    }

    public function createHomeDir(): void
    {
        $this->createHomeDirectory();
    }

    public function createProjectDir(): void
    {
        $this->createEngineDirectory();
    }

    public function hostingExists(): bool
    {
        return $this->linuxUserExists()
            || $this->homeDirectoryExists()
            || $this->engineDirectoryExists();
    }

    /**
     * @return array{UID: int, GID: int}
     */
    public function syncLinuxUser(): array
    {
        $identity = $this->linuxUserExists()
            ? $this->readLinuxIdentity()
            : $this->createLinuxUser();

        $this->model->setDetails([
            'UID' => $identity['UID'],
            'GID' => $identity['GID'],
        ]);

        return $identity;
    }

    /**
     * @return array{UID: int, GID: int}
     */
    public function createLinuxUser(): array
    {
        $this->system->execOnHost([
            'useradd',
            '-M',
            '-d',
            $this->homeDirPath(),
            '-s',
            '/usr/sbin/nologin',
            '-U',
            $this->username(),
        ]);

        return $this->readLinuxIdentity();
    }

    /**
     * @return array{UID: int, GID: int}
     */
    public function readLinuxIdentity(): array
    {
        return [
            'UID' => (int) $this->system->execOnHost(['id', '-u', $this->username()]),
            'GID' => (int) $this->system->execOnHost(['id', '-g', $this->username()]),
        ];
    }

    /**
     * False when a limit was asked for and setquota refused it -- typically
     * because quota is off on the host filesystem, so it is not enforced.
     */
    public function configureQuota(): bool
    {
        $limitBlocks = self::positiveOrZero($this->model->getDiskSpaceLimit()) * 1024;
        $limitInodes = self::positiveOrZero($this->model->getInodesLimit());

        $mountPoint = $this->system->filesystem()->getHomeFilesystemMountPoint();
        $process = $this->system->runProcessOnHost([
            'setquota',
            '-u',
            $this->username(),
            (string) $limitBlocks,
            (string) $limitBlocks,
            (string) $limitInodes,
            (string) $limitInodes,
            $mountPoint,
        ]);

        $unlimitedNeedsNoQuota = $limitBlocks === 0 && $limitInodes === 0;
        if ($process->getExitCode() === 0 || $unlimitedNeedsNoQuota) {
            return true;
        }

        $this->warnQuotaNotEnforced($limitBlocks, $limitInodes, $mountPoint, $process);

        return false;
    }

    private static function positiveOrZero(mixed $limit): int
    {
        return is_int($limit) && $limit > 0 ? $limit : 0;
    }

    private function warnQuotaNotEnforced(int $limitBlocks, int $limitInodes, string $mountPoint, Process $process): void
    {
        Log::warning(sprintf(
            'Disk limit for project %s (%d MB, %d inodes) is NOT enforced: setquota on %s failed: %s. '
            .'Turn quota on with scripts/configure-quota.sh.',
            $this->username(),
            intdiv($limitBlocks, 1024),
            $limitInodes,
            $mountPoint,
            trim($process->getErrorOutput()) ?: 'exit '.(string) $process->getExitCode(),
        ));
    }

    /**
     * Hands the home to the account: its ancestors stay root-owned and not group/world
     * writable, the contents go to the account, and the protected dirs are restored.
     */
    public function fixPermissions(): void
    {
        $ids = $this->accountIds();
        if ($ids === null) {
            return;
        }
        [$uid, $gid] = $ids;
        $home = rtrim($this->homeDirPath(), '/');

        foreach (self::pathAndAncestorsTopDown($home) as $dir) {
            $this->secureRootOwnedDirectory($dir);
        }
        $this->chownTree($home, $uid, $gid, 1);
        $this->restoreProtectedDirectories($home);
        $this->chownLscacheDirectories($home, $uid);
    }

    /**
     * Hands a staged copy of the home to the account under the home's own rules: its
     * contents go to the account, the directory itself stays root-owned like the home
     * root, so the account can use the copy but cannot add or swap its top-level entries.
     */
    public function handStagedHomeToAccount(string $stagedHome): void
    {
        $ids = $this->accountIds();
        if ($ids === null) {
            return;
        }
        $stagedHome = rtrim($stagedHome, '/');

        $this->secureRootOwnedDirectory($stagedHome);
        $this->chownTree($stagedHome, $ids[0], $ids[1], 1);
        $this->restoreProtectedDirectories($stagedHome);
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function accountIds(): ?array
    {
        $uid = $this->model->getUid();
        $gid = $this->model->getGid();
        if (! $uid || ! $gid) {
            Log::warning("Cannot fix file permissions for user {$this->username()}, UID/GID is missing");

            return null;
        }

        return [$uid, $gid];
    }

    /**
     * @return list<string>
     */
    private static function pathAndAncestorsTopDown(string $path): array
    {
        $dir = $path;
        $dirs = [];
        while ($dir !== '/' && $dir !== '' && ! in_array($dir, $dirs, true)) {
            $dirs[] = $dir;
            $dir = dirname($dir);
        }

        return array_reverse($dirs);
    }

    private function secureRootOwnedDirectory(string $dir): void
    {
        $info = @stat($dir);
        if ($info === false) {
            throw new \RuntimeException("Directory does not exist: $dir");
        }
        if ($info['uid'] !== 0 || $info['gid'] !== 0) {
            $this->system->runProcess(sprintf(
                'sudo chown root:root %s',
                escapeshellarg($dir)
            ));
        }
        if (($info['mode'] & 022) !== 0) {
            $this->system->runProcess(sprintf(
                'sudo chmod 755 %s',
                escapeshellarg($dir)
            ));
        }
    }

    /**
     * A dind account's `docker/` tree is the inner daemon's and is left alone.
     * A push's rollback dumps in .pre-push-db belong to the engine process alone:
     * the account must neither read nor rewrite what the engine replays into its
     * databases, and the worker must still read them when a push fails.
     */
    private function chownTree(string $root, int $uid, int $gid, int $minDepth): void
    {
        $excludeArgs = sprintf(' -not \( -path %s -prune \)', escapeshellarg("{$root}/.pre-push-db"));
        if ($this->isDindTemplate()) {
            $excludeArgs .= sprintf(' -not \( -path %s -prune \)', escapeshellarg("{$root}/docker/*"));
        }

        $this->system->runProcess(sprintf(
            'sudo find %s -mindepth %d%s -exec chown -h %d:%d {} +',
            escapeshellarg($root),
            $minDepth,
            $excludeArgs,
            $uid,
            $gid
        ));
    }

    private function restoreProtectedDirectories(string $home): void
    {
        $dockerDir = "{$home}/docker";
        if ($this->isDindTemplate() && $this->isRealDirectory($dockerDir)) {
            $this->system->runProcess("sudo chown root:root {$dockerDir}");
        }
        if ($this->isRealDirectory("{$home}/.panelalpha")) {
            $this->system->runProcess(['sudo', 'chmod', '700', "{$home}/.panelalpha"]);
        }
    }

    private function chownLscacheDirectories(string $home, int $uid): void
    {
        foreach ($this->model->getDomains() as $domain) {
            $lscacheDir = $home.'/'.$domain->domain.'/.lscache';
            if ($this->isRealDirectory($lscacheDir)) {
                $this->system->runProcess(['sudo', 'chown', '-R', 'nobody:'.$uid, $lscacheDir]);
            }
        }
    }

    /** chown and chmod follow a link named on their command line, so a link is never one of these directories. */
    private function isRealDirectory(string $path): bool
    {
        $filesystem = $this->system->filesystem();
        if (! $filesystem->isSymlink($path)) {
            return $filesystem->directoryExists($path);
        }
        Log::warning("Leaving '{$path}' alone while fixing permissions: it is a symbolic link.");

        return false;
    }

    private function isDindTemplate(): bool
    {
        return $this->model->getTemplate() === 'dind';
    }

    public function tearDownLinuxIsolation(): void
    {
        if ($this->linuxUserExists()) {
            $this->deleteLinuxUser();
        }
        if ($this->homeDirectoryExists()) {
            $this->deleteHomeDirectory();
        }
        if ($this->engineDirectoryExists()) {
            $this->deleteEngineDirectory();
        }
    }

    public function linuxUserExists(): bool
    {
        return $this->system->isUidExists($this->username());
    }

    public function homeDirectoryExists(): bool
    {
        return is_dir($this->homeDirPath());
    }

    public function engineDirectoryExists(): bool
    {
        return is_dir($this->projectDirPath());
    }

    /** `.panelalpha` holds generated secrets and tunnel tokens, so it is closed to all but its owner. */
    private function createHomeDirectory(): void
    {
        $home = $this->homeDirPath();
        $this->system->exec(['sudo', 'mkdir', '-p', $home]);
        $this->system->exec(['sudo', 'chown', 'root:root', $home]);
        $this->system->exec(['sudo', 'mkdir', '-p', "{$home}/.panelalpha"]);
        $this->system->exec(['sudo', 'chmod', '700', "{$home}/.panelalpha"]);
        $this->system->exec(['sudo', 'mkdir', '-p', "{$home}/.wp-cli"]);
    }

    private function createEngineDirectory(): void
    {
        $dir = $this->projectDirPath();
        $this->system->exec(['sudo', 'mkdir', '-p', $dir]);
        $this->system->exec(['sudo', 'chown', '-R', 'www-data:www-data', $dir]);
    }

    private function deleteLinuxUser(): void
    {
        $this->system->execOnHost([
            'userdel',
            $this->username(),
        ]);
    }

    private function deleteHomeDirectory(): void
    {
        $this->system->exec(['sudo', 'rm', '-rf', $this->homeDirPath()]);
    }

    private function deleteEngineDirectory(): void
    {
        $this->system->exec(['sudo', 'rm', '-rf', $this->projectDirPath()]);
    }

    private function requireDindRuntime(): Dind
    {
        if (! $this->runtime instanceof Dind) {
            throw new \RuntimeException('This operation requires a DinD project.');
        }

        return $this->runtime;
    }

    private function rollbackProvision(\Throwable $failure): never
    {
        try {
            $this->runtime->remove();
            $this->tearDownLinuxIsolation();
        } catch (\Throwable $cleanupFailure) {
            Log::warning(
                "Cleanup after failed provision for '{$this->username()}' also failed: "
                .$cleanupFailure->getMessage(),
                ['exception' => $cleanupFailure],
            );
            throw $failure;
        }

        throw $failure;
    }

    /**
     * @param  list<string>  $cmd
     * @param  list<int>  $alsoAccepted  exit statuses that are not a failure here
     */
    private function runCopyProcess(array $cmd, string $context, array $alsoAccepted = []): void
    {
        $process = $this->system->runProcess($cmd);
        if ($process->isSuccessful() || in_array($process->getExitCode(), $alsoAccepted, true)) {
            return;
        }
        $output = trim($process->getErrorOutput().' '.$process->getOutput());
        throw new \RuntimeException($output !== '' ? "{$context}: {$output}" : $context);
    }
}
