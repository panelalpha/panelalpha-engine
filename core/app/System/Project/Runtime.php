<?php

namespace App\System\Project;

use App\Models\Domain;

/**
 * Stack runtime (outer compose / DinD account container).
 */
interface Runtime
{
    public function kind(): string;

    public function exists(): bool;

    public function composeFilePath(): string;

    public function defaultServiceName(): string;

    /** createFromTemplate + buildIfMissing as needed */
    public function materialize(): void;

    /** compose / outer stack up */
    public function start(): void;

    /** compose / outer stack down */
    public function stop(): void;

    /** Tear down compose/stack ONLY — not the Linux user */
    public function remove(): void;

    public function awaitReady(int $tries = 12, int $intervalSeconds = 5): void;

    public function isRunning(): bool;

    public function build(): void;

    public function buildIfMissing(): void;

    public function createDomainConfig(Domain $domain): void;

    public function reloadWebserver(): void;

    public function rebuildDomain(Domain $domain): void;

    public function deleteDomainConfig(string $domainName): void;

    /** Have the project's cron pick up its crontab. */
    public function reloadCron(): void;
}
