<?php

namespace App\System\Project\Deployment;

use App\Lib\Findings\Finding;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;

/**
 * DinD deploy mechanics on the App\System path; default implementation is {@see DindDeployMechanics}.
 */
interface DeployMechanics
{
    public const CUSTOM_ENV_SUSPECTED = 'custom_env_vars_suspected';

    public function user(): ModelsUser;

    public function requireMainDomain(): DomainModel;

    public function hasGitProject(): bool;

    public function isDindWithoutGit(): bool;

    public function prepareHostingEnvironment(): void;

    /** Whether a failed prepare removed the account again or never made it, leaving no project for a failed deploy to keep. */
    public function removedTheAccount(): bool;

    public function ingestApplicationSource(): void;

    public function isApplicationEnvironmentRunning(): bool;

    /** Before anything a redeploy does: note what serves, so a cancel cannot read it as a first deploy. */
    public function recordRunningVersion(): void;

    /** A running account sysbox forgot refuses every exec, git's included: it is recreated before anything runs in it. */
    public function repairAccountMapping(): void;

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

    /** Keeps the app's volumes unless $removeVolumes: only a first deploy has nothing to lose. */
    public function abortPartialDeploy(bool $removeVolumes = false): void;

    /** After a redeploy, however it ended: what it kept beside the running app is put back or removed. */
    public function settleRedeploy(bool $succeeded): void;

    /**
     * @return list<Finding>
     */
    public function servingWarnings(): array;

    /**
     * @return list<Finding>
     */
    public function publicUrlWarnings(DomainModel $domain): array;

    public function customEnvFailureHint(): ?string;

    /**
     * @param  list<Finding>  $warnings
     */
    public function persistPartialSuccess(array $warnings): void;

    public function persistSuccess(): void;
}
