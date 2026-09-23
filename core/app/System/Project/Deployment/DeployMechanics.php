<?php

namespace App\System\Project\Deployment;

use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;

/**
 * DinD deploy mechanics on the App\System path; default implementation is {@see DindDeployMechanics}.
 */
interface DeployMechanics
{
    public function user(): ModelsUser;

    public function requireMainDomain(): DomainModel;

    public function hasGitProject(): bool;

    public function isDindWithoutGit(): bool;

    public function prepareHostingEnvironment(): void;

    public function ingestApplicationSource(): void;

    public function isApplicationEnvironmentRunning(): bool;

    public function syncHostingForCheckoutRebuild(bool $reuseRunning): void;

    /**
     * Linux isolation, optional zip import, then outer compose recreate (wipe rebuild path).
     */
    public function syncHostingForSourceRebuild(?string $zipPath): void;

    /**
     * Wipe-and-reclone (or prepareFromSources when not git / when zip was imported).
     */
    public function ingestForWipeRebuild(?string $zipPath): void;

    public function reprepareApplicationFromCheckout(): void;

    /**
     * Import an uploaded archive into ~/project and prepare the app from it. No wipe.
     */
    public function ingestArchive(string $zipPath): void;

    public function publishDomain(DomainModel $domain): void;

    /**
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function startApplication(): array;

    public function abortPartialDeploy(): void;

    /**
     * @return list<string>
     */
    public function servingWarnings(): array;

    /**
     * @return list<string>
     */
    public function publicUrlWarnings(DomainModel $domain): array;

    public function customEnvFailureHint(): ?string;

    public function persistPartialSuccess(array $warnings): void;

    public function persistSuccess(): void;
}
