<?php

namespace App\System\Project;

use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Engine\EngineFactory;
use App\System\Project\Dind\AccountTeardown;
use App\System\Project\Dind\AccountTemplate;
use App\System\Project\Dind\AppCertificate;
use App\System\Project\Dind\AppManager;
use App\System\Project\Dind\ContainerOperations;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\AppPortAlignment;
use App\System\Project\Dind\AbstractApplication as DindApplication;
use App\System\Project\Deployment\DeployableDindProject;
use App\System\Project\Deployment\DeploymentWorkflow;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\System\Project\Dind\ComposeWriter;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\SystemAppConfigSource;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\Networking;
use App\System\Project\Dind\OuterLifecycle;
use App\System\Project\Dind\Paths;
use App\System\Project\Dind\PrepareFromSource;
use App\System\Project\Dind\ProjectEnvironment;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Dind\Source\Files as SourceFiles;
use Symfony\Component\Yaml\Yaml;

/**
 * DinD Runtime (outer account container; Application as inner containers).
 */
class Dind implements DeployableDindProject, Runtime
{
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

    public function __construct(
        private readonly ProjectAggregate $project,
    ) {
    }

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
        return $this->project->projectDirPath() . '/docker-compose.yml';
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
        if (!$this->hasApplication()) {
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
            ($model->getUid() ?? 33) . ':' . ($model->getGid() ?? 33),
            $this->composeFilePath(),
        );
    }

    public function shell(): ShellOperations
    {
        return $this->shell ??= new ShellOperations($this);
    }

    /**
     * Read paths under ~/project via the host System API (Lib Dind::files()).
     * Home-scoped file operations live on the Project aggregate ({@see \App\System\Project::fileManager()}).
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

    /**
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    public function runSshCommand(string $command, ?string $cwd = null, int $timeout = 300): array
    {
        $process = $this->shell()->runShellAsUser($command, $cwd, $timeout);
        $exitCode = $process->getExitCode();

        return [
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'exit_code' => $exitCode ?? 124,
        ];
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

    public function getServiceLogs(string $service, int $lines = 200): string
    {
        return $this->containerOperations()->getServiceLogs($service, $lines);
    }

    public function appCertificate(): AppCertificate
    {
        return $this->appCertificate ??= new AppCertificate($this);
    }

    public function abortRunningDeploy(bool $stopInnerDocker = true): void
    {
        $this->accountTeardown()->abortRunningDeploy($stopInnerDocker);
    }

    public function preCheckFromSources(): void
    {
        $this->prepareFromSource()->preCheck();
    }

    public function prepareFromSources(): void
    {
        $this->prepareFromSource()->prepare();
    }

    public function importProjectArchive(string $zipPath): void
    {
        (new SourceFiles($this))->importProjectArchive($zipPath);
    }

    public function applyProjectEnvVars(): void
    {
        $this->projectEnvironment()->apply();
    }

    private function prepareFromSource(): PrepareFromSource
    {
        return $this->prepareFromSource ??= new PrepareFromSource($this);
    }

    private function projectEnvironment(): ProjectEnvironment
    {
        return $this->projectEnvironment ??= new ProjectEnvironment($this);
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
     * @param list<string> $rest
     * @return list<string>
     */
    public function userAppComposeCommand(array $rest): array
    {
        return $this->paths()->composeCommand($rest);
    }

    public function publicAppUrl(): ?string
    {
        return $this->networking()->publicAppUrl();
    }

    /**
     * @param array<string, mixed> $snapshot
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
        foreach ($appConfig->files() as $snippet) {
            $fullPath = "{$projectDir}/{$snippet['path']}";
            $this->shell()->execAsUser(['mkdir', '-p', dirname($fullPath)]);
            $this->system()->filesystem()->filePutContents($fullPath, $snippet['contents'], $chown, '644');
        }
    }

    public function userAppComposeFileToRun(): string
    {
        return $this->paths()->composeFileToRun();
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
     * Wait until the account's inner Docker daemon answers.
     *
     * A DinD account has *two* things to wait for and the inherited no-op only
     * ever named the wrong one. The account container starting is what
     * `docker compose ps` reports, and it is up in about three seconds; the
     * nested `dockerd` behind it takes a moment longer, and in that moment
     * `/var/run/docker.sock` does not exist at all. Every command that needs
     * the inner daemon fails on the way through that window -- with
     * `failed to connect to the docker API at unix:///var/run/docker.sock`,
     * which reads as a broken daemon rather than as "too early".
     *
     * Measured on 10.10.10.25: the socket appears 5-6s after the container
     * starts, and the deploy path is slow enough (preparing, os user, dirs,
     * quota) not to notice. `Projects::copy()` is not: it copies the home dir
     * and prepares volumes five seconds in, inside the window. Issue #58 --
     * every staging copy of a DinD project failed there and the job deleted
     * the destination account, so the whole feature was unusable.
     *
     * Deliberately silent and deliberately bounded. It runs on paths that have
     * no deploy log to write into, and the caller's own error is a better
     * message than this one could invent; giving up after the tries are
     * exhausted leaves the command to fail with whatever it has to say.
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
     * Whether the account's inner daemon answers, asked in a way that is an
     * answer rather than an exception even when the socket is absent.
     *
     * `docker info` and not `test -S /var/run/docker.sock`: the socket file
     * exists before the daemon behind it can serve a request, and it is the
     * request that the failing commands actually make.
     */
    private function innerDockerIsUp(): bool
    {
        try {
            $process = $this->shell()->runProcess(
                ['docker', 'info', '--format', '{{.ServerVersion}}'],
                [],
                self::DAEMON_PROBE_TIMEOUT_SECONDS
            );
        } catch (\Throwable $e) {
            return false;
        }

        return $process->isSuccessful();
    }

    /** Long enough for a busy daemon to answer, short enough to retry soon. */
    private const DAEMON_PROBE_TIMEOUT_SECONDS = 20;

    public function isRunning(): bool
    {
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
        $image = null;
        try {
            /** @var mixed */
            $parsed = Yaml::parseFile($this->composeFilePath());
            if (
                is_array($parsed)
                && is_array($parsed['services'] ?? null)
                && is_array($parsed['services']['dind'] ?? null)
                && is_string($parsed['services']['dind']['image'] ?? null)
            ) {
                $image = $parsed['services']['dind']['image'];
            }
        } catch (\Exception $e) {
        }
        $imageEscaped = escapeshellarg($image);
        $pathEscaped = escapeshellarg($this->composeFilePath());
        if ($image) {
            $this->system()->exec(
                "sudo docker image inspect {$imageEscaped} >/dev/null 2>&1"
                    . " || sudo docker compose -f {$pathEscaped} pull"
                    . " || sudo docker compose -f {$pathEscaped} build"
            );

            return;
        }

        $this->build();
    }

    public function createDomainConfig(DomainModel $domain): void
    {
    }

    public function reloadWebserver(): void
    {
    }

    public function rebuildDomain(DomainModel $domain): void
    {
    }

    public function deleteDomainConfig(string $domainName): void
    {
    }

    public function tearDown(): void
    {
        $this->outerLifecycle()->tearDown();
    }

    public function setupEntrypointInitScripts(): void
    {
        $scriptFiles = $this->accountTemplate()->entrypointInitScripts();
        $dir = $this->projectDirPath() . '/entrypoint.d';
        $this->system()->runProcess("sudo mkdir -p {$dir} && sudo rm -f {$dir}/*.sh");
        foreach ($scriptFiles as $name => $script) {
            $this->system()->filesystem()->filePutContents("{$dir}/{$name}", $script);
        }
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
