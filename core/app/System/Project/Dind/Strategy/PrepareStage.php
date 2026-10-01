<?php

namespace App\System\Project\Dind\Strategy;

use App\System\Project\Dind as DindProject;
use App\Lib\Deploy\Platform\HostScript;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Everything that has to happen on the account between the clone and the
 * build.
 *
 * An app config that generates a compose file has to run here rather than in the
 * container's install stage, because the compose file is what decides which
 * container there will be. The scripts run as the account user — they may
 * call `docker compose`, which needs the docker group membership `su` gives
 * them — and under a container-side timeout shorter than the host's, so bash
 * and its children are killed inside the container before Symfony kills the
 * exec client and leaves them orphaned.
 */
class PrepareStage
{
    private const TIMEOUT_SECONDS = 3600;

    /** Kill inside the container this many seconds before the host gives up. */
    private const INNER_TIMEOUT_MARGIN = 60;

    private DindProject $dind;

    public function __construct(DindProject $dind)
    {
        $this->dind = $dind;
    }

    /**
     * The manifest behind a decision, when one produced it.
     *
     * @param array<string, mixed> $decision
     */
    public function manifestFor(array $decision, ?AppConfig $appConfig = null): ?PlatformManifest
    {
        return PlatformRegistry::forDecisionOrAppConfig($decision, $appConfig);
    }

    /**
     * The platform's own prepare commands. The app config's ran in bootstrap,
     * before any of this was detected.
     *
     * @return bool whether anything ran
     */
    public function run(string $projectDir, ?PlatformManifest $manifest, ?AppConfig $appConfig): bool
    {
        $context = ProjectContext::make($projectDir, ProjectContext::listRootFiles($projectDir));

        if (HostScript::isEmpty($manifest, PlatformStage::PREPARE, $context)) {
            return false;
        }

        $logger = $this->dind->shell()->logger();
        $logger?->info('Running prepare commands');
        $this->execute(
            $projectDir,
            HostScript::render($manifest, PlatformStage::PREPARE, $context),
            self::TIMEOUT_SECONDS
        );
        $logger?->ok('Prepare commands finished');

        return true;
    }

    public function execute(string $projectDir, string $commands, int $timeout = self::TIMEOUT_SECONDS): void
    {
        $setupScriptPath = "{$projectDir}/" . AppConfig::SETUP_SCRIPT;
        $chown = $this->dind->userModel()->getChownString();
        // Write script as the user (644) so execAsUser can read and execute it.
        $this->dind->system()->filesystem()->filePutContents($setupScriptPath, $commands, $chown, '644');
        // Use a container-side timeout slightly shorter than the PHP-side timeout.
        // This ensures bash and all its child processes (docker compose run, etc.) are
        // killed by SIGTERM/SIGKILL *inside* the container before Symfony sends SIGKILL
        // to the host-side docker-compose-exec client — preventing orphaned containers
        // and zombie exec sessions in the dind environment.
        $innerTimeout = max(60, $timeout - self::INNER_TIMEOUT_MARGIN);
        // Run as the user: setup scripts may call `docker compose`, which requires the
        // docker group membership that execAsUser provides via su.
        $script = 'cd ' . escapeshellarg($projectDir)
            . " && timeout --foreground --kill-after=30 {$innerTimeout} bash "
            . escapeshellarg($setupScriptPath);
        $shell = $this->dind->shell();
        $shell->execAsUser(['bash', '-c', $script], [], $timeout);
        $shell->exec(['rm', '-f', $setupScriptPath]);
    }
}
