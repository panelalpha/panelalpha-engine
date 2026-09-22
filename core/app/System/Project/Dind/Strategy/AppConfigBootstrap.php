<?php

namespace App\System\Project\Dind\Strategy;

use App\System\Project\Dind as DindProject;
use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\HostScript;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\StageResolver;

/**
 * Everything the project's app config does to the checkout, before anything is
 * detected.
 *
 * An app config is a bootstrap, not a strategy: it writes its file snippets, its
 * compose file if it ships one, and runs its after-clone script. What that
 * leaves behind is what detection reads — a compose file an app config wrote is
 * read ahead of the repository's own, and an app config that writes none
 * leaves the repository's own as the only one detection sees.
 */
class AppConfigBootstrap
{
    private const TIMEOUT_SECONDS = 3600;

    private DindProject $dind;

    public function __construct(DindProject $dind)
    {
        $this->dind = $dind;
    }

    public function run(?AppConfig $appConfig, string $projectDir, ?string $chown): void
    {
        // A deploy request may speak for the prepare stage of a project that
        // has no app config at all, so the scripts run on their own terms rather
        // than as something an app config brought with it.
        if ($appConfig === null) {
            // A config that is gone -- or has nothing left to say, which reads
            // the same -- stopped shipping its compose file too. Left behind,
            // that file would still be read ahead of the repository's own.
            $this->removeCompose($projectDir);
            $this->runScripts($projectDir, null);

            return;
        }

        $this->dind->installFileSnippets();
        $this->writeCompose($appConfig, $projectDir, $chown);
        $this->writeEntrypoint($appConfig, $projectDir, $chown);
        $this->runScripts($projectDir, $appConfig);
    }

    /**
     * An entrypoint the app config supplies, written where a repository would
     * have put its own — {@see EntrypointWriter} reads one path, whoever
     * wrote it.
     */
    private function writeEntrypoint(AppConfig $appConfig, string $projectDir, ?string $chown): void
    {
        $script = $appConfig->entrypoint();
        if ($script === null) {
            return;
        }

        $this->dind->noteAppConfigOverwritesTracked([EntrypointWriter::PROJECT_OVERRIDE]);
        $path = rtrim($projectDir, '/') . '/' . EntrypointWriter::PROJECT_OVERRIDE;
        $this->dind->shell()->execAsUser(['mkdir', '-p', dirname($path)]);
        $this->dind->system()->filesystem()->filePutContents($path, $script, $chown, '755');
    }

    /**
     * The compose file the app config ships, written under the engine's
     * reserved names (ADR-0001) rather than one the client's own compose
     * file might use.
     *
     * `override` is layered over the run file whenever present (D8).
     * `replace` goes to {@see EngineArtifacts::APP_CONFIG_COMPOSE}, which
     * detection ({@see \App\Lib\Deploy\Platform\Probes\ComposeUsableProbe})
     * reads ahead of the repository's own compose file — writing it to the
     * run file directly would run before detection ever sees it, since the
     * run file is not one of detection's own candidate names.
     *
     * An app config that stops shipping a compose file -- or stops existing --
     * removes whichever of these it had written; they survive `clean -fd`
     * (excluded), so a stale one would otherwise outlive the app config that
     * wrote it.
     */
    private function writeCompose(AppConfig $appConfig, string $projectDir, ?string $chown): void
    {
        $system = $this->dind->system();
        $fs = $system->filesystem();
        $appConfigCompose = rtrim($projectDir, '/') . '/' . EngineArtifacts::APP_CONFIG_COMPOSE;
        $engineOverride = $this->dind->userAppComposeOverridePath();

        $content = $appConfig->compose();
        if ($content === null) {
            $this->removeCompose($projectDir);

            return;
        }

        if ($appConfig->composeMode() === AppConfig::COMPOSE_OVERRIDE) {
            $fs->filePutContents($engineOverride, $content, $chown, '644');
            $this->removeIfExists($appConfigCompose, $system, $fs);

            return;
        }

        $fs->filePutContents($appConfigCompose, $content, $chown, '644');
        $this->removeIfExists($engineOverride, $system, $fs);
    }

    private function removeCompose(string $projectDir): void
    {
        $system = $this->dind->system();
        $fs = $system->filesystem();
        $this->removeIfExists(rtrim($projectDir, '/') . '/' . EngineArtifacts::APP_CONFIG_COMPOSE, $system, $fs);
        $this->removeIfExists($this->dind->userAppComposeOverridePath(), $system, $fs);
    }

    private function removeIfExists(string $path, \App\System $system, \App\System\Filesystem $fs): void
    {
        if ($fs->fileExists($path)) {
            $system->exec(['sudo', 'rm', '-f', $path]);
        }
    }

    /**
     * The app config's own prepare-stage work: its staged commands, then its
     * after-clone script.
     *
     * No platform is passed, because none has been chosen yet — that is the
     * point of running here. The platform's own prepare commands run later,
     * once detection has said which platform it is.
     */
    private function runScripts(string $projectDir, ?AppConfig $appConfig): void
    {
        $plan = app(DeployPlanContext::class)->get();
        $staged = $appConfig?->commands(PlatformStage::PREPARE) ?? [];
        $extra = [];
        $setup = $appConfig?->setupCommands();
        if (is_string($setup) && $setup !== '') {
            $extra[AppConfig::SETUP_SCRIPT] = $setup;
        }
        if (HostScript::isEmpty(null, PlatformStage::PREPARE, null, $extra, $staged, $plan)) {
            return;
        }

        $logger = $this->dind->shell()->logger();
        $logger?->info(StageResolver::isOverridden(PlatformStage::PREPARE, $plan)
            ? 'Running prepare commands (replaced by the deploy request)'
            : 'Running setup commands (' . AppConfig::SETUP_SCRIPT . ')');
        $this->dind->strategy()->prepare()->execute(
            $projectDir,
            HostScript::render(null, PlatformStage::PREPARE, null, [], $extra, $staged, $plan),
            self::TIMEOUT_SECONDS
        );
        $logger?->ok('Setup commands finished');
    }
}
