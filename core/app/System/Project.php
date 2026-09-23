<?php

namespace App\System;

use App\Integrations\Tunnels\Cloudflare;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\Models\Backup as BackupRecord;
use App\Models\ProxyRule;
use App\System as EngineSystem;
use App\System\Project\Backup;
use App\System\Project\Cron;
use App\System\Project\Dind;
use App\System\Project\Deployment\DeploymentWorkflow;
use App\System\Project\Domain as DomainProject;
use App\System\Project\FileManager;
use App\System\Project\Ftp;
use App\System\Project\Git as ProjectGit;
use App\System\Project\Dind\CopyVolumes;
use App\System\Project\Php;
use App\System\Project\PhpHosting;
use App\System\Project\Runtime;
use App\System\Project\Settings;
use App\System\Project\Sftp;
use Illuminate\Support\Facades\Log;

class Project
{
    private Runtime $runtime;

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

    public function reloadCron(): void
    {
        if ($this->runtime instanceof PhpHosting) {
            $this->runtime->reloadCron();

            return;
        }

        $this->system->exec(
            "sudo docker compose -f {$this->composeFilePath()} exec -T php service cron restart"
        );
    }

    /**
     * @param list<string> $args
     *
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    public function runWpCli(array $args): array
    {
        if (!$this->runtime instanceof PhpHosting) {
            throw new \RuntimeException('WP-CLI is only supported for PHP hosting projects.');
        }

        return $this->runtime->phpRuntime()->runWpCli($args);
    }

    public function syncPhpHandlersScripts(): void
    {
        if ($this->runtime instanceof PhpHosting) {
            $this->runtime->phpRuntime()->syncPhpHandlersScripts();
        }
    }

    public function runEntrypointScriptsSync(): void
    {
        if ($this->runtime instanceof PhpHosting) {
            $this->runtime->runEntrypointScriptsSync();
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
        $user = $this->model;
        if ($user->stagingUser()->exists()) {
            throw new \Exception(
                "Cannot delete project '{$user->username}' while staging '{$user->stagingUser->username}' exists. Delete the staging project first."
            );
        }

        foreach ($user->backups()->with('container')->get() as $record) {
            $backupId = $record->id;
            $this->backup($record)->delete();

            if (BackupRecord::query()->find($backupId) !== null) {
                throw new \RuntimeException("Backup {$backupId} storage delete failed");
            }
        }

        $username = $user->username;

        // Tear down Cloudflare tunnel/DNS while the API token and domains still exist.
        try {
            Cloudflare::teardownProject($user);
        } catch (\Throwable $e) {
            Log::warning(
                "Cloudflare project teardown failed for {$username}: " . $e->getMessage()
            );
        }

        // Drop proxy rules before heavy teardown so a failed mid-step cannot leave
        // them behind if the user row is removed later (FK cascade needs username set).
        ProxyRule::forUser($username)->delete();
        ProxyRule::pruneOrphanUserRules();

        $domainNames = $user->domains->pluck('domain')->all();
        $user->domains()->delete();
        $this->system->webserver()->deleteDomainsConfigs($domainNames);
        if (!config('env.KEEP_WEBSERVER_LOGS_FOR_DELETED_DOMAINS')) {
            $this->system->webserver()->deleteDomainsLogsDirs($domainNames);
        }

        $this->tearDownHosting();

        /** @var string[] $ftpAccounts */
        $ftpAccounts = $user->ftpAccounts->pluck('user')->all();
        $this->ftp()->deleteMany($ftpAccounts);

        // SFTP logins live in one host-wide logins.conf, so dropping the rows
        // is only half of it -- the file has to be rebuilt without them. Left
        // undone, the deleted account's credential stayed valid against a uid
        // and home directory the next account gets handed straight back.
        // Rebuilding is best-effort: the sftp service is behind a compose
        // profile, and a host that does not run it must still delete projects.
        $hadSftpAccounts = $user->sftpAccounts()->exists();
        $user->sftpAccounts()->delete();
        if ($hadSftpAccounts) {
            try {
                $this->sftp()->rebuild();
            } catch (\Throwable $e) {
                Log::warning(
                    "SFTP logins rebuild failed after deleting {$username}: " . $e->getMessage()
                );
            }
        }

        $mysql = $this->system->mysql();
        /** @var string[] $mysqlDatabases */
        $mysqlDatabases = $user->mysqlDatabases->pluck('database')->all();
        foreach ($mysqlDatabases as $database) {
            if ($mysql->databases()->databaseExists($database)) {
                $mysql->databases()->deleteDatabase($database);
            }
        }

        /** @var string[] $mysqlUsers */
        $mysqlUsers = $user->mysqlUsers->pluck('user')->all();
        foreach ($mysqlUsers as $mysqlUser) {
            if ($mysql->users()->userExists($mysqlUser)) {
                $mysql->users()->deleteUser($mysqlUser);
            }
        }

        $user->mysqlUsers()->delete();
        $user->mysqlDatabases()->delete();
        $user->ftpAccounts()->delete();
        // hook_deliveries cascades from deploy_hooks at the DB level.
        $user->deployHooks()->delete();
        DeployLogger::deleteUserLogs($username);
        $this->system->webserver()->rebuildDomains();
        $user->delete();
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
                "Could not deprovision project for user '{$this->username()}': " . $e->getMessage(),
                ['exception' => $e],
            );
        }

        if ($this->runtime instanceof Dind) {
            try {
                $this->runtime->remove();
            } catch (\Exception $e) {
                Log::warning(
                    "Could not remove DinD runtime for user '{$this->username()}': " . $e->getMessage(),
                    ['exception' => $e],
                );
            }
        }

        $this->tearDownLinuxIsolation();
    }

    public function deployment(): ?DeploymentWorkflow
    {
        return $this->runtime instanceof Dind ? $this->runtime->deployment() : null;
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
        if (!$this->runtime instanceof Dind) {
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
     * @param list<string> $domainNames
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
     * @return array{stdout: string, stderr: string, exit_code: ?int}
     */
    public function startUserApp(): array
    {
        if (!$this->runtime instanceof Dind) {
            throw new \RuntimeException('startUserApp is not available for this project type.');
        }

        $app = $this->runtime->app();
        if ($app === null) {
            throw new \RuntimeException('Cannot start application before deploy strategy is set.');
        }

        return $app->start();
    }

    public function syncGeneratedProxyRules(): void
    {
        if (!$this->runtime instanceof Dind) {
            return;
        }
        $this->runtime->networking()->detectAndCreateProxyRules($this->model);
    }

    public function abortRunningDeploy(bool $stopInnerDocker = true): void
    {
        if ($this->runtime instanceof Dind) {
            $this->runtime->abortRunningDeploy($stopInnerDocker);
        }
    }

    public function copyVolumeDataFrom(self $source): int
    {
        $volumes = $this->copyVolumesForClone();
        if ($volumes === null || $this->runtime()->kind() !== 'dind') {
            return 0;
        }

        return $volumes->copyVolumeDataFrom($source->homeDirPath());
    }

    public function copyVolumeDataToIncoming(self $source): int
    {
        $volumes = $this->copyVolumesForClone();
        if ($volumes === null || $this->runtime()->kind() !== 'dind') {
            return 0;
        }

        return $volumes->copyVolumeDataToIncoming($source->homeDirPath());
    }

    public function copyVolumesForClone(): ?CopyVolumes
    {
        if ($this->runtime()->kind() !== 'dind') {
            return null;
        }

        if (!$this->runtime instanceof Dind) {
            return null;
        }
        $app = $this->runtime->app();
        if ($app === null) {
            return null;
        }

        return $app->copyVolumes();
    }

    public function copyHomeDirFrom(self $source): void
    {
        $src = rtrim($source->homeDirPath(), '/') . '/';
        $dest = rtrim($this->homeDirPath(), '/') . '/';

        if ($this->model->getTemplate() === 'dind') {
            $this->runCopyProcess([
                'sudo', 'rsync', '-a', '--delete',
                '--exclude=docker/',
                $src,
                $dest,
            ], 'Failed to rsync home directory from source');
        } else {
            $this->runCopyProcess([
                'sudo', 'rsync', '-a', '--delete',
                $src,
                $dest,
            ], 'Failed to rsync home directory from source');
        }

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
            if (!is_dir($srcDir)) {
                continue;
            }
            $this->runCopyProcess([
                'sudo', 'rsync', '-a', '--delete',
                rtrim($srcDir, '/') . '/',
                rtrim($destDir, '/') . '/',
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

    public function configureQuota(): void
    {
        $limitMb = $this->model->getDiskSpaceLimit();
        $limitBlocks = 0;
        if (is_int($limitMb) && $limitMb > 0) {
            $limitBlocks = $limitMb * 1024;
        }
        $userLimitInodes = $this->model->getInodesLimit();
        $limitInodes = 0;
        if (is_int($userLimitInodes) && $userLimitInodes > 0) {
            $limitInodes = $userLimitInodes;
        }

        $this->system->runProcessOnHost([
            'setquota',
            '-u',
            $this->username(),
            (string) $limitBlocks,
            (string) $limitBlocks,
            (string) $limitInodes,
            (string) $limitInodes,
            $this->system->filesystem()->getHomeFilesystemMountPoint(),
        ]);
    }

    public function fixPermissions(): void
    {
        $uid = $this->model->getUid();
        $gid = $this->model->getGid();
        if (!$uid || !$gid) {
            Log::warning("Cannot fix file permissions for user {$this->username()}, UID/GID is missing");

            return;
        }
        $home = rtrim($this->homeDirPath(), '/');

        $dir = $home;
        $toCheck = [];
        while ($dir !== '/' && $dir !== '' && !in_array($dir, $toCheck, true)) {
            $toCheck[] = $dir;
            $dir = dirname($dir);
        }
        $toCheck = array_reverse($toCheck);

        foreach ($toCheck as $d) {
            $info = @stat($d);
            if ($info === false) {
                throw new \RuntimeException("Directory does not exist: $d");
            }
            $owner = $info['uid'];
            $group = $info['gid'];
            $mode = $info['mode'] & 0777;
            if ($owner !== 0 || $group !== 0) {
                $this->system->runProcess(sprintf(
                    'sudo chown root:root %s',
                    escapeshellarg($d)
                ));
            }
            if (($mode & 022) !== 0) {
                $this->system->runProcess(sprintf(
                    'sudo chmod 755 %s',
                    escapeshellarg($d)
                ));
            }
        }

        $dockerDir = "{$home}/docker";
        $excludeArgs = '';
        if ($this->model->getTemplate() === 'dind') {
            $excludeArgs = sprintf(' -not \( -path %s -prune \)', escapeshellarg("{$dockerDir}/*"));
        }

        $this->system->runProcess(sprintf(
            'sudo find %s -mindepth 1%s -exec chown -h %d:%d {} +',
            escapeshellarg($home),
            $excludeArgs,
            $uid,
            $gid
        ));

        if ($this->model->getTemplate() === 'dind' && $this->system->filesystem()->directoryExists($dockerDir)) {
            $this->system->runProcess("sudo chown root:root {$dockerDir}");
        }

        foreach ($this->model->getDomains() as $domain) {
            $lscacheDir = $home . '/' . $domain->domain . '/.lscache';
            if ($this->system->filesystem()->directoryExists($lscacheDir)) {
                $this->system->runProcess(['sudo', 'chown', '-R', 'nobody:' . $uid, $lscacheDir]);
            }
        }
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

    private function createHomeDirectory(): void
    {
        $home = $this->homeDirPath();
        $this->system->exec("sudo mkdir -p {$home}");
        $this->system->exec("sudo chown root:root {$home}");
        $this->system->exec("sudo mkdir -p {$home}/.panelalpha");
        $this->system->exec("sudo mkdir -p {$home}/.wp-cli");
    }

    private function createEngineDirectory(): void
    {
        $dir = $this->projectDirPath();
        $this->system->exec("sudo mkdir -p {$dir}");
        $this->system->exec("sudo chown -R www-data:www-data {$dir}");
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
        $this->system->exec("sudo rm -rf {$this->homeDirPath()}");
    }

    private function deleteEngineDirectory(): void
    {
        $this->system->exec("sudo rm -rf {$this->projectDirPath()}");
    }

    private function requireDindRuntime(): Dind
    {
        if (!$this->runtime instanceof Dind) {
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
                . $cleanupFailure->getMessage(),
                ['exception' => $cleanupFailure],
            );
            throw $failure;
        }

        throw $failure;
    }

    /**
     * @param list<string> $cmd
     */
    private function runCopyProcess(array $cmd, string $context): void
    {
        $process = $this->system->runProcess($cmd);
        if ($process->isSuccessful()) {
            return;
        }
        $output = trim($process->getErrorOutput() . ' ' . $process->getOutput());
        throw new \RuntimeException($output !== '' ? "{$context}: {$output}" : $context);
    }
}
