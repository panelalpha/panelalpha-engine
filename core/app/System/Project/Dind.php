<?php

namespace App\System\Project;

use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Engine\EngineFactory;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Project\ApiAccess;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project as ProjectAggregate;
use App\System\Project\Deployment\DeployableDindProject;
use App\System\Project\Deployment\DeploymentWorkflow;
use App\System\Project\Dind\AbstractApplication as DindApplication;
use App\System\Project\Dind\AccountMemory;
use App\System\Project\Dind\AccountTeardown;
use App\System\Project\Dind\AccountTemplate;
use App\System\Project\Dind\AppCertificate;
use App\System\Project\Dind\AppCredentialDelivery;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\AppManager;
use App\System\Project\Dind\AppPortAlignment;
use App\System\Project\Dind\ComposeWriter;
use App\System\Project\Dind\ContainerOperations;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\HostDiskGuard;
use App\System\Project\Dind\InnerBuildGuard;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\Networking;
use App\System\Project\Dind\OuterLifecycle;
use App\System\Project\Dind\Paths;
use App\System\Project\Dind\PrepareFromSource;
use App\System\Project\Dind\ProjectEnvironment;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\RegistryLogin;
use App\System\Project\Dind\Services\S6ServiceManager;
use App\System\Project\Dind\Services\ServiceManager;
use App\System\Project\Dind\Services\SupervisordServiceManager;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Dind\Source\Files as SourceFiles;
use App\System\Project\Dind\Source\GitRepository;
use App\System\Project\Dind\SystemAppConfigSource;
use App\System\Project\Dind\TenantEgressGuard;
use App\System\Project\Dind\WordPressCli;
use Symfony\Component\Yaml\Yaml;

/**
 * DinD Runtime (outer account container; Application as inner containers).
 */
class Dind implements DeployableDindProject, Runtime
{
    /** Long enough for a busy daemon to answer, short enough to retry soon. */
    private const DAEMON_PROBE_TIMEOUT_SECONDS = 20;

    private ?AccountTemplate $accountTemplate = null;

    private ?OuterLifecycle $outerLifecycle = null;

    private ?Paths $paths = null;

    private ?DindApplication $application = null;

    private ?DeploymentWorkflow $deployment = null;

    private ?ContainerEngine $engine = null;

    private ?EngineAccount $engineAccount = null;

    private ?ShellOperations $shell = null;

    private ?ProjectFiles $projectTree = null;

    private ?HostCompile $hostCompile = null;

    private ?InnerDocker $innerDocker = null;

    private ?Networking $networking = null;

    private ?ComposeWriter $composeWriter = null;

    private ?DeployStrategy $deployStrategy = null;

    private ?AppHealth $appHealth = null;

    private ?AppManager $apps = null;

    private ?ContainerOperations $containerOperations = null;

    private ?AccountTeardown $accountTeardown = null;

    private ?AppCertificate $appCertificate = null;

    private ?PrepareFromSource $prepareFromSource = null;

    private ?ProjectEnvironment $projectEnvironment = null;

    private ?AppCredentialDelivery $appCredentials = null;

    private ?RegistryLogin $registryLogin = null;

    private ?InnerBuildGuard $innerBuildGuard = null;

    public function __construct(
        private readonly ProjectAggregate $project,
    ) {}

    public function kind(): string
    {
        return 'dind';
    }

    public function project(): ProjectAggregate
    {
        return $this->project;
    }

    public function system(): System
    {
        return $this->project->system();
    }

    public function username(): string
    {
        return $this->project->username();
    }

    public function userModel(): ModelsUser
    {
        return $this->requireUserModel();
    }

    public function domain(DomainModel $domainModel): Domain
    {
        return $this->project->domain($domainModel);
    }

    public function composeFilePath(): string
    {
        return $this->project->projectDirPath().'/docker-compose.yml';
    }

    public function exists(): bool
    {
        return file_exists($this->composeFilePath());
    }

    public function defaultServiceName(): string
    {
        return 'dind';
    }

    public function homeDirPath(): string
    {
        return $this->project->homeDirPath();
    }

    public function projectDirPath(): string
    {
        return $this->project->projectDirPath();
    }

    public function app(): ?DindApplication
    {
        if (! $this->hasApplication()) {
            return null;
        }

        return $this->application ??= new DindApplication($this);
    }

    /**
     * DinD-from-source Deploy orchestration (distinct from {@see materialize()}).
     */
    public function deployment(): DeploymentWorkflow
    {
        return $this->deployment ??= new DeploymentWorkflow($this);
    }

    public function engine(): ContainerEngine
    {
        return $this->engine ??= EngineFactory::default();
    }

    public function engineAccount(): EngineAccount
    {
        $model = $this->requireUserModel();

        return $this->engineAccount ??= new EngineAccount(
            $model->username,
            $this->homeDirPath(),
            implode(':', $model->linuxIds()),
            $this->composeFilePath(),
        );
    }

    public function shell(): ShellOperations
    {
        return $this->shell ??= new ShellOperations($this);
    }

    /** Put the project's memory limit on its account container, live. */
    public function applyMemoryLimit(): void
    {
        (new AccountMemory($this))->apply();
    }

    /**
     * What keeps the account's processes up: s6, or supervisord on an account
     * rendered before s6. Not cached: rendering the template changes it.
     */
    public function services(): ServiceManager
    {
        return S6ServiceManager::manages($this) ? new S6ServiceManager($this) : new SupervisordServiceManager($this);
    }

    /**
     * Read paths under ~/project via the host System API.
     * Home-scoped file operations live on the Project aggregate ({@see Project::fileManager()}).
     */
    public function projectTree(): ProjectFiles
    {
        return $this->projectTree ??= new ProjectFiles($this);
    }

    public function hostCompile(): HostCompile
    {
        return $this->hostCompile ??= new HostCompile($this);
    }

    public function innerDocker(): InnerDocker
    {
        return $this->innerDocker ??= new InnerDocker($this);
    }

    public function appHealth(): AppHealth
    {
        return $this->appHealth ??= new AppHealth($this);
    }

    public function apps(): AppManager
    {
        return $this->apps ??= new AppManager($this);
    }

    public function wordPressCli(): WordPressCli
    {
        return new WordPressCli($this);
    }

    /**
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    public function runSshCommand(string $command, ?string $cwd = null, int $timeout = 300): array
    {
        return $this->shell()->runShellCommand($command, $cwd, $timeout);
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getContainers(): array
    {
        return $this->containerOperations()->getContainers();
    }

    /**
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    public function projectAction(string $action): array
    {
        return $this->containerOperations()->projectAction($action);
    }

    /**
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    public function serviceAction(string $service, string $action): array
    {
        return $this->containerOperations()->serviceAction($service, $action);
    }

    public function getServiceLogs(string $service, int $lines = 200, ?string $since = null, ?string $until = null): string
    {
        return $this->containerOperations()->getServiceLogs($service, $lines, $since, $until);
    }

    public function assertServiceExists(string $service): void
    {
        $this->containerOperations()->assertServiceExists($service);
    }

    /**
     * @param  callable(string, ?string): void  $onLine
     * @param  ?callable(): void  $onIdle
     */
    public function followServiceLogs(string $service, int $lines, ?string $since, callable $onLine, ?callable $onIdle = null): void
    {
        $this->containerOperations()->followServiceLogs($service, $lines, $since, $onLine, $onIdle);
    }

    public function appCertificate(): AppCertificate
    {
        return $this->appCertificate ??= new AppCertificate($this);
    }

    public function abortRunningDeploy(bool $stopInnerDocker = true, bool $removeVolumes = false): void
    {
        $this->accountTeardown()->abortRunningDeploy($stopInnerDocker, $removeVolumes);
    }

    public function cancelRunningDeploy(): void
    {
        $this->accountTeardown()->cancelRunningDeploy();
    }

    public function preCheckFromSources(): void
    {
        $this->assertHostHasRoomToDeploy();
        $this->prepareFromSource()->preCheck();
    }

    public function prepareFromSources(): void
    {
        $this->assertHostHasRoomToDeploy();
        $this->registryLogin()->during(fn () => $this->prepareFromSource()->prepare());
    }

    /**
     * Every deploy path passes through prepareFromSources(), and a git deploy
     * through preCheckFromSources() before its clone. A full host is not the
     * app's failure, so it is recorded as a refusal, not a broken deploy.
     */
    private function assertHostHasRoomToDeploy(): void
    {
        $refusal = (new HostDiskGuard(
            $this->system(),
            HostDiskGuard::minimumFrom(config('deploy.host_min_free'))
        ))->refusal();
        if ($refusal === null) {
            return;
        }

        $this->shell()->logger()?->markPreCheckRejected();
        throw new \RuntimeException($refusal);
    }

    public function importProjectArchive(string $zipPath): void
    {
        (new SourceFiles($this))->importProjectArchive($zipPath);
    }

    public function applyProjectEnvVars(?string $composePath = null): void
    {
        $this->environment()->apply($composePath);
    }

    /** The project's private registry logins, held only while a deploy pulls and builds. */
    public function registryLogin(): RegistryLogin
    {
        return $this->registryLogin ??= new RegistryLogin($this);
    }

    /** Image builds in the account's own daemon, held to the build memory while the engine builds. */
    public function innerBuildGuard(): InnerBuildGuard
    {
        return $this->innerBuildGuard ??= new InnerBuildGuard($this);
    }

    /** The login a manifest's `credentials:` declares, written into the account. */
    public function appCredentials(): AppCredentialDelivery
    {
        return $this->appCredentials ??= new AppCredentialDelivery($this);
    }

    private function prepareFromSource(): PrepareFromSource
    {
        return $this->prepareFromSource ??= new PrepareFromSource($this);
    }

    public function networking(): Networking
    {
        return $this->networking ??= new Networking($this);
    }

    public function composeWriter(): ComposeWriter
    {
        return $this->composeWriter ??= new ComposeWriter($this);
    }

    public function strategy(): DeployStrategy
    {
        return $this->deployStrategy ??= new DeployStrategy($this);
    }

    public function environment(): ProjectEnvironment
    {
        return $this->projectEnvironment ??= new ProjectEnvironment($this);
    }

    public function userAppDirPath(): string
    {
        return $this->paths()->appDir();
    }

    public function userAppComposeFilePath(): string
    {
        return $this->paths()->composeFile();
    }

    public function userAppComposeOverridePath(): string
    {
        return $this->paths()->composeOverrideFile();
    }

    /**
     * The files `docker compose` is run with, in layering order.
     *
     * @return list<string>
     */
    public function userAppComposeFiles(): array
    {
        return $this->paths()->composeFiles();
    }

    /**
     * @param  list<string>  $rest
     * @return list<string>
     */
    public function userAppComposeCommand(array $rest): array
    {
        return $this->paths()->composeCommand($rest);
    }

    /**
     * @return array<string, string>
     */
    public function userAppComposeEnv(): array
    {
        return $this->paths()->composeEnv();
    }

    public function publicAppUrl(): ?string
    {
        return $this->networking()->publicAppUrl();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function freezeDeploySnapshot(array $snapshot): void
    {
        $user = $this->requireUserModel();
        $user->setDetails($snapshot);
        $user->save();
    }

    public function appConfig(?string $gitUrl = null): ?AppConfig
    {
        return AppConfig::load(
            new SystemAppConfigSource($this->system()),
            $this->userAppDirPath(),
            $gitUrl
        );
    }

    /**
     * Copy the app config's file snippets into the project tree.
     */
    public function installFileSnippets(): void
    {
        $appConfig = $this->appConfig($this->userModel()->getGitRepo());
        if ($appConfig === null) {
            return;
        }

        $projectDir = $this->userAppDirPath();
        $chown = $this->userModel()->getChownString();
        $this->noteAppConfigOverwritesTracked(array_map(
            static fn (array $snippet): string => $snippet['path'],
            $appConfig->files()
        ));
        foreach ($appConfig->files() as $snippet) {
            $fullPath = "{$projectDir}/{$snippet['path']}";
            $this->system()->filesystem()->assertNoSymlinkAbove($fullPath, $projectDir);
            $this->shell()->execAsUser(['mkdir', '-p', dirname($fullPath)]);
            $this->system()->filesystem()->writeFileReplacingPath($fullPath, $snippet['contents'], $chown, self::snippetMode($snippet['path']));
        }
    }

    /**
     * A root `.env` is owner-only like every root `.env` the engine writes: the account's
     * Compose reads it, and ~/project is traversable by every uid on the host.
     */
    private static function snippetMode(string $relativePath): string
    {
        return preg_replace('#^(?:\./)+#', '', $relativePath) === '.env' ? '600' : '644';
    }

    /**
     * Logs each tracked file an app config overwrites (the ADR-0001 exception, D4),
     * so a client can tell why `git status` shows a change.
     *
     * @param  list<string>  $relativePaths  checkout-relative
     */
    public function noteAppConfigOverwritesTracked(array $relativePaths): void
    {
        $logger = $this->shell()->logger();
        if ($logger === null || $relativePaths === []) {
            return;
        }

        foreach ($this->trackedAmong($relativePaths) as $path) {
            $logger->info("App config overwrites tracked file {$path}");
        }
    }

    /**
     * Which of the paths the checkout tracks; none when there is no repository or git fails.
     *
     * @param  list<string>  $relativePaths
     * @return list<string>
     */
    private function trackedAmong(array $relativePaths): array
    {
        $git = new GitRepository($this);
        if (! $git->hasRepository()) {
            return [];
        }

        try {
            return $git->trackedAmong(array_values(array_unique(array_map(
                static fn (string $path): string => ltrim($path, '/'),
                $relativePaths
            ))));
        } catch (\Throwable) {
            return [];
        }
    }

    public function userAppComposeFileToRun(): string
    {
        return $this->paths()->composeFileToRun();
    }

    public function userAppComposeFileForPorts(): string
    {
        return $this->paths()->composeFileForPorts();
    }

    public function userAppExistingComposeFilePath(): ?string
    {
        return $this->paths()->existingComposeFile();
    }

    public function createFromTemplate(): void
    {
        $this->accountTemplate()->create();
    }

    public function alignAppPort(): void
    {
        (new AppPortAlignment($this))->alignIfNeeded();
    }

    public function materialize(): void
    {
        $this->createFromTemplate();
        $this->buildIfMissing();
    }

    public function start(): void
    {
        $this->outerLifecycle()->up();
    }

    public function stop(): void
    {
        $this->outerLifecycle()->down();
    }

    public function remove(): void
    {
        $this->accountTeardown()->delete();
        $this->outerLifecycle()->deleteOuterStack();
    }

    /**
     * Waits, silently and bounded, until the account's inner dockerd answers: its socket appears
     * seconds after the container starts. On giving up, the caller's own command reports the failure.
     */
    public function awaitReady(int $tries = 12, int $intervalSeconds = 5): void
    {
        for ($try = 0; $try < max(1, $tries); $try++) {
            if ($this->innerDockerIsUp()) {
                return;
            }

            if ($try < $tries - 1) {
                sleep(max(0, $intervalSeconds));
            }
        }
    }

    /**
     * Whether the inner daemon serves a request (`docker info`): its socket file exists before it can.
     * Never throws, even when the socket is absent.
     */
    private function innerDockerIsUp(): bool
    {
        try {
            $process = $this->shell()->runProcess(
                ['docker', 'info', '--format', '{{.ServerVersion}}'],
                [],
                self::DAEMON_PROBE_TIMEOUT_SECONDS
            );
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful();
    }

    /** False without an outer compose file: nothing to ask about until recreateOuterCompose() writes it back. */
    public function isRunning(): bool
    {
        if (! $this->exists()) {
            return false;
        }

        $command = "sudo docker compose -f {$this->composeFilePath()} ps --services --filter status=running";
        $result = trim($this->system()->exec($command));

        return $result !== '';
    }

    public function build(): void
    {
        $this->system()->exec("sudo docker compose -f {$this->composeFilePath()} build");
    }

    public function buildIfMissing(): void
    {
        $image = $this->outerImage();
        if (! $image) {
            $this->build();

            return;
        }

        $imageEscaped = escapeshellarg($image);
        $pathEscaped = escapeshellarg($this->composeFilePath());
        $this->system()->exec(
            "sudo docker image inspect {$imageEscaped} >/dev/null 2>&1"
                ." || sudo docker compose -f {$pathEscaped} pull"
                ." || sudo docker compose -f {$pathEscaped} build"
        );
    }

    /** The image the outer compose file names for the dind service, if it can be read. */
    private function outerImage(): ?string
    {
        try {
            $parsed = Yaml::parseFile($this->composeFilePath());
        } catch (\Exception) {
            return null;
        }

        $image = is_array($parsed) && is_array($parsed['services'] ?? null) && is_array($parsed['services']['dind'] ?? null)
            ? $parsed['services']['dind']['image'] ?? null
            : null;

        return is_string($image) ? $image : null;
    }

    public function createDomainConfig(DomainModel $domain): void {}

    public function reloadWebserver(): void {}

    public function rebuildDomain(DomainModel $domain): void {}

    public function deleteDomainConfig(string $domainName): void {}

    public function tearDown(): void
    {
        $this->outerLifecycle()->tearDown();
    }

    /**
     * Each script is replaced by renaming a temp file over it, so a tenant with entrypoint.d
     * mounted read-write cannot plant a symlink in a gap; the egress guard's service keeps it applied
     * after boot.
     */
    public function setupEntrypointInitScripts(): void
    {
        $scriptFiles = $this->accountTemplate()->entrypointInitScripts(ApiAccess::allows($this->userModel()));
        $dir = $this->projectDirPath().'/entrypoint.d';
        $fs = $this->system()->filesystem();
        $this->system()->runProcess("sudo mkdir -p {$dir}");
        foreach ($scriptFiles as $name => $script) {
            $fs->writeFileReplacingPath("{$dir}/{$name}", $script);
        }
        $this->unlinkUnusedInitScripts($dir, $scriptFiles);
        $this->services()->configure(TenantEgressGuard::SERVICE, isset($scriptFiles[TenantEgressGuard::FILE]));
    }

    /**
     * The account's egress guard alone, rewritten when its Engine API allowance
     * changes; its own service re-applies it within a few seconds. Nothing is
     * written for an account whose directory is not there yet: the create renders
     * the same file, from the same allowance.
     */
    public function rewriteEgressGuard(): void
    {
        if (! TenantEgressGuard::enabled()) {
            return;
        }
        $dir = $this->projectDirPath().'/entrypoint.d';
        if (! $this->system()->filesystem()->isDir($dir)) {
            return;
        }

        $script = $this->accountTemplate()->entrypointInitScripts(ApiAccess::allows($this->userModel()))[TenantEgressGuard::FILE] ?? null;
        if ($script !== null) {
            $this->system()->filesystem()->writeFileReplacingPath("{$dir}/".TenantEgressGuard::FILE, $script);
        }
    }

    /**
     * A plain unlink, which never opens whatever a tenant left at the name.
     *
     * @param  array<string, string>  $scriptFiles
     */
    private function unlinkUnusedInitScripts(string $dir, array $scriptFiles): void
    {
        foreach (['useradd.sh', TenantEgressGuard::FILE] as $name) {
            if (! isset($scriptFiles[$name])) {
                $this->system()->runProcess("sudo rm -f {$dir}/{$name}");
            }
        }
    }

    public function daemonJsonPath(): string
    {
        return $this->projectDirPath().'/daemon.json';
    }

    public function daemonJsonContents(): string
    {
        return $this->accountTemplate()->daemonJson();
    }

    /** Renders the current daemon.json to the host: a fresh account, or one without the bind mount yet. */
    public function setupDaemonJson(): void
    {
        $this->system()->filesystem()->writeFileReplacingPath($this->daemonJsonPath(), $this->daemonJsonContents());
    }

    /**
     * Writes daemon.json in place, as a bind mount would not see a renamed-in inode; refuses a symlink.
     *
     * @return bool whether it wrote
     */
    public function rewriteDaemonJsonInPlace(): bool
    {
        return $this->system()->filesystem()->overwriteFileUnlessSymlink($this->daemonJsonPath(), $this->daemonJsonContents());
    }

    protected function requireUserModel(): ModelsUser
    {
        return $this->project->model();
    }

    private function accountTemplate(): AccountTemplate
    {
        return $this->accountTemplate ??= new AccountTemplate($this);
    }

    private function outerLifecycle(): OuterLifecycle
    {
        return $this->outerLifecycle ??= new OuterLifecycle($this);
    }

    private function paths(): Paths
    {
        return $this->paths ??= new Paths($this);
    }

    private function accountTeardown(): AccountTeardown
    {
        return $this->accountTeardown ??= new AccountTeardown($this);
    }

    private function containerOperations(): ContainerOperations
    {
        return $this->containerOperations ??= new ContainerOperations($this, $this->shell());
    }

    private function hasApplication(): bool
    {
        return $this->project->model()->getDeployStrategy() !== null;
    }
}
