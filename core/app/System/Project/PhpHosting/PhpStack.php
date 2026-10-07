<?php

namespace App\System\Project\PhpHosting;

use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\Services\Service;

/**
 * Private PHP handler stack inside the project container (fpm / lsphp / apache-in-container).
 */
interface PhpStack
{
    public function dockerfileTemplateName(): string;

    public function composeTemplateName(): string;

    public function applySettings(PhpHosting $project): void;

    /**
     * @return array<string, string>
     */
    public function entrypointInitScripts(PhpHosting $project): array;

    /**
     * The account's long-running processes.
     *
     * @return list<Service>
     */
    public function services(PhpHosting $project): array;

    public function waitForAllRunning(PhpHosting $project, int $tries = 12, int $intervalSeconds = 5): void;

    public function restartPhpHandler(PhpHosting $project, string $phpVersion): void;
}
