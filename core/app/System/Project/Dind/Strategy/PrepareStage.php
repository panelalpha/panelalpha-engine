<?php

namespace App\System\Project\Dind\Strategy;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\HostScript;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\ProjectContext;
use App\System\Project\Dind as DindProject;

/**
 * What has to happen on the account between the clone and the build, before any container exists:
 * an app config that generates a compose file decides here which container there will be.
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
     * @param  array<string, mixed>  $decision
     */
    public function manifestFor(array $decision, ?AppConfig $appConfig = null): ?PlatformManifest
    {
        return PlatformRegistry::forDecisionOrAppConfig($decision, $appConfig);
    }

    /**
     * The platform's own prepare commands. The app config's ran in bootstrap, before detection.
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

    /**
     * Runs as the account user, since the script may call `docker compose`, under an inner timeout shorter than
     * the host's, so bash and its children die inside the container before Symfony orphans them.
     */
    public function execute(string $projectDir, string $commands, int $timeout = self::TIMEOUT_SECONDS): void
    {
        $setupScriptPath = "{$projectDir}/".AppConfig::SETUP_SCRIPT;
        $chown = $this->dind->userModel()->getChownString();
        $this->dind->system()->filesystem()->writeFileReplacingPath($setupScriptPath, $commands, $chown, '644');
        $innerTimeout = max(60, $timeout - self::INNER_TIMEOUT_MARGIN);
        $script = 'cd '.escapeshellarg($projectDir)
            ." && timeout --foreground --kill-after=30 {$innerTimeout} bash "
            .escapeshellarg($setupScriptPath);
        $shell = $this->dind->shell();
        $shell->execAsUserWithProjectEnv(['bash', '-c', $script], $timeout);
        $shell->exec(['rm', '-f', $setupScriptPath]);
    }
}
