<?php

namespace Tests\Unit\System\Project\Deployment;

use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System\Project\Deployment\DeployMechanics;
use App\System\Project\Deployment\FailureDisposition;

/** Records the order the workflow drives its mechanics in. */
final class RecordingMechanics implements DeployMechanics
{
    public ?\Throwable $prepareException = null;

    public ?\Throwable $ingestArchiveException = null;

    /** @var array{exit_code: int, stdout: string, stderr: string} */
    public array $startResult = ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];

    public bool $dindWithoutGit = false;
    public bool $hasGit = false;
    public ?string $envHint = null;

    /** @var list<string> */
    public array $serving = [];

    /** @var list<string> */
    public array $publicUrl = [];

    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $partialWarnings = [];

    public function __construct(
        private ModelsUser $userModel,
        private DomainModel $domain,
    ) {
    }

    public function user(): ModelsUser
    {
        return $this->userModel;
    }

    public function requireMainDomain(): DomainModel
    {
        return $this->domain;
    }

    public function hasGitProject(): bool
    {
        return $this->hasGit;
    }

    public function isDindWithoutGit(): bool
    {
        return $this->dindWithoutGit;
    }

    public function prepareHostingEnvironment(): void
    {
        $this->calls[] = 'prepare';
        if ($this->prepareException !== null) {
            throw $this->prepareException;
        }
    }

    public function ingestApplicationSource(): void
    {
        $this->calls[] = 'ingest';
    }

    public function isApplicationEnvironmentRunning(): bool
    {
        return false;
    }

    public function syncHostingForCheckoutRebuild(bool $reuseRunning): void
    {
        $this->calls[] = 'syncCheckoutRebuild';
    }

    public function syncHostingForSourceRebuild(?string $zipPath): void
    {
        $this->calls[] = 'syncSourceRebuild';
    }

    public function ingestForWipeRebuild(?string $zipPath): void
    {
        $this->calls[] = 'ingestWipeRebuild';
    }

    public function reprepareApplicationFromCheckout(): void
    {
        $this->calls[] = 'reprepare';
    }

    public function ingestArchive(string $zipPath): void
    {
        $this->calls[] = 'ingestArchive';
        if ($this->ingestArchiveException !== null) {
            throw $this->ingestArchiveException;
        }
    }

    public function publishDomain(DomainModel $domain): void
    {
        $this->calls[] = 'publish';
    }

    public function startApplication(): array
    {
        $this->calls[] = 'start';

        return $this->startResult;
    }

    public function abortPartialDeploy(bool $removeVolumes = false): void
    {
        $this->calls[] = 'abort';
    }

    public function settleRedeploy(bool $succeeded): void
    {
        $this->calls[] = 'settle';
    }

    public function servingWarnings(): array
    {
        return $this->serving;
    }

    public function publicUrlWarnings(DomainModel $domain): array
    {
        return $this->publicUrl;
    }

    public function customEnvFailureHint(): ?string
    {
        return $this->envHint;
    }

    public function persistPartialSuccess(array $warnings): void
    {
        $this->calls[] = 'persistPartial';
        $this->partialWarnings = array_values($warnings);
    }

    public function persistSuccess(): void
    {
        $this->calls[] = 'persistSuccess';
    }
}

/** Records which disposition hook the workflow reached, without touching a DB. */
final class RecordingDisposition implements FailureDisposition
{
    /** @var list<string> */
    public array $calls = [];

    public ?string $lastMessage = null;

    public bool $hookRunsOnCancel = true;

    public function afterCancelled(ModelsUser $user, string $message): void
    {
        $this->calls[] = 'cancelled';
        $this->lastMessage = $message;
    }

    public function afterFailure(ModelsUser $user, string $message): void
    {
        $this->calls[] = 'failure';
        $this->lastMessage = $message;
    }

    public function hookRunsOnCancel(): bool
    {
        return $this->hookRunsOnCancel;
    }

    public function hookName(): string
    {
        return 'recording';
    }
}
