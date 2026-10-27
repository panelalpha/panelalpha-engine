<?php

namespace App\System\Project\Dind;

use App\Models\Domain;
use App\System\Project\AbstractApplication as BaseApplication;
use App\System\Project\Dind as DindRuntime;

/**
 * DinD Application: inner compose project inside the account container.
 */
class AbstractApplication extends BaseApplication
{
    private ?ShellOperations $shell = null;

    private ?ContainerOperations $containers = null;

    private ?SourceAccess $source = null;

    private ?CopyVolumes $copyVolumes = null;

    private ?VolumeArchive $volumeArchive = null;

    private ?Paths $paths = null;

    public function __construct(
        private DindRuntime $project,
    ) {}

    public function id(): string
    {
        return 'dind:'.$this->project->username();
    }

    public function rootPath(): string
    {
        return $this->project->userAppDirPath();
    }

    /**
     * @return list<Domain>
     */
    public function domains(): array
    {
        return $this->project->userModel()->getDomains();
    }

    public function shell(): ShellOperations
    {
        return $this->shell ??= new ShellOperations($this->project);
    }

    public function containers(): ContainerOperations
    {
        return $this->containers ??= new ContainerOperations($this->project, $this->shell());
    }

    public function source(): SourceAccess
    {
        return $this->source ??= new SourceAccess($this->project);
    }

    public function copyVolumes(): CopyVolumes
    {
        return $this->copyVolumes ??= new CopyVolumes(
            $this->project,
            $this->containers(),
            $this->paths(),
        );
    }

    /**
     * Named-volume tar/restore for backup (not clone rsync).
     */
    public function volumeArchive(): VolumeArchive
    {
        return $this->volumeArchive ??= new VolumeArchive($this->project);
    }

    /**
     * Start the inner application (docker compose up). Distinct from outer {@see DindRuntime::start()}.
     *
     * @return array{stdout: string, stderr: string, exit_code: ?int}
     */
    public function start(bool $forceRecreate = false): array
    {
        return (new AppLauncher($this->project))->start($forceRecreate);
    }

    /**
     * Tear down a half-started app stack after cancel or failed compose up.
     */
    public function abortRunningDeploy(bool $stopInnerDocker = true): void
    {
        $this->project->abortRunningDeploy($stopInnerDocker);
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
        return $this->containers()->getContainers();
    }

    /**
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    public function projectAction(string $action): array
    {
        return $this->containers()->projectAction($action);
    }

    /**
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    public function serviceAction(string $service, string $action): array
    {
        return $this->containers()->serviceAction($service, $action);
    }

    public function getServiceLogs(string $service, int $lines = 200): string
    {
        return $this->containers()->getServiceLogs($service, $lines);
    }

    private function paths(): Paths
    {
        return $this->paths ??= new Paths($this->project);
    }
}
