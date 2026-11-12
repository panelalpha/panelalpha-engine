<?php

namespace App\System\Project\Dind\Strategy;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\ComposeOverride;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\HostScript;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\Probes\ComposeUsableProbe;
use App\Lib\Deploy\Platform\StageResolver;
use App\System;
use App\System\Filesystem;
use App\System\Privileged;
use App\System\Project\Dind as DindProject;

/**
 * What the project's app config does to the checkout before detection: its file snippets,
 * its compose file and its after-clone script, which detection then reads.
 */
class AppConfigBootstrap
{
    private const TIMEOUT_SECONDS = 3600;

    private DindProject $dind;

    public function __construct(DindProject $dind)
    {
        $this->dind = $dind;
    }

    /**
     * Without an app config the scripts still run, since a deploy request may set the prepare stage,
     * and a compose file a gone config wrote is removed so it is not read ahead of the repository's own.
     */
    public function run(?AppConfig $appConfig, string $projectDir, ?string $chown): void
    {
        if ($appConfig === null) {
            $this->removeCompose($projectDir);
            $this->runScripts($projectDir, null);

            return;
        }

        $this->dind->installFileSnippets();
        $this->writeCompose($appConfig, $projectDir, $chown);
        $this->writeEntrypoint($appConfig, $projectDir, $chown);
        $this->runScripts($projectDir, $appConfig);
    }

    /** An entrypoint the app config supplies, written where {@see EntrypointWriter} reads a repository's own. */
    private function writeEntrypoint(AppConfig $appConfig, string $projectDir, ?string $chown): void
    {
        $script = $appConfig->entrypoint();
        if ($script === null) {
            return;
        }

        $this->dind->noteAppConfigOverwritesTracked([EntrypointWriter::PROJECT_OVERRIDE]);
        $path = rtrim($projectDir, '/').'/'.EntrypointWriter::PROJECT_OVERRIDE;
        $this->dind->shell()->execAsUser(['mkdir', '-p', dirname($path)]);
        $this->dind->system()->filesystem()->writeFileReplacingPath($path, $script, $chown, '755');
    }

    /**
     * The app config's compose file under the engine's reserved names (ADR-0001): `override` layers over the run file;
     * `replace` is what detection ({@see ComposeUsableProbe}) and inspect's RecipeFileOverlay read ahead of the repository's.
     */
    private function writeCompose(AppConfig $appConfig, string $projectDir, ?string $chown): void
    {
        $system = $this->dind->system();
        $fs = $system->filesystem();
        $appConfigCompose = rtrim($projectDir, '/').'/'.EngineArtifacts::APP_CONFIG_COMPOSE;
        $engineOverride = $this->dind->userAppComposeOverridePath();

        $content = $appConfig->compose();
        if ($content === null) {
            $this->removeCompose($projectDir);

            return;
        }

        if ($appConfig->composeMode() === AppConfig::COMPOSE_OVERRIDE) {
            $this->writeHardenedOverride($content, $engineOverride, $appConfigCompose, $chown, $system, $fs);

            return;
        }

        $fs->writeFileReplacingPath($appConfigCompose, (string) $appConfig->replacingCompose(), $chown, '644');
        $this->removeIfExists($engineOverride, $system, $fs);
    }

    /** Layered over the run file as written, so it gets the run file's isolation rules first. */
    private function writeHardenedOverride(
        string $content,
        string $engineOverride,
        string $appConfigCompose,
        ?string $chown,
        System $system,
        Filesystem $fs
    ): void {
        $hardened = ComposeOverride::harden($content, null, $this->dind->environment()->forInterpolation(), $this->dind->userModel()->username, $this->dind->userAppDirPath());
        if ($hardened['yaml'] === null) {
            throw new \InvalidArgumentException('The app config\'s compose override could not be read as YAML.');
        }
        foreach ($hardened['removed'] as $what) {
            $this->dind->shell()->logger()?->warn(
                "Removed from the app config's compose override, not allowed in hosting: {$what}"
            );
        }
        $fs->writeFileReplacingPath($engineOverride, $hardened['yaml'], $chown, '644');
        $this->removeIfExists($appConfigCompose, $system, $fs);
    }

    /** Both reserved files survive `clean -fd`, so one a config stopped shipping would outlive it. */
    private function removeCompose(string $projectDir): void
    {
        $system = $this->dind->system();
        $fs = $system->filesystem();
        $this->removeIfExists(rtrim($projectDir, '/').'/'.EngineArtifacts::APP_CONFIG_COMPOSE, $system, $fs);
        $this->removeIfExists($this->dind->userAppComposeOverridePath(), $system, $fs);
    }

    private function removeIfExists(string $path, System $system, Filesystem $fs): void
    {
        if ($fs->fileExists($path)) {
            $system->exec(Privileged::accountFiles(...$this->dind->userModel()->linuxIds())->removeIfExists($path));
        }
    }

    /**
     * The app config's staged prepare commands, then its after-clone script. No platform is passed:
     * none has been chosen yet, and the platform's own prepare commands run after detection.
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
            : 'Running setup commands ('.AppConfig::SETUP_SCRIPT.')');
        $this->dind->strategy()->prepare()->execute(
            $projectDir,
            HostScript::render(null, PlatformStage::PREPARE, null, [], $extra, $staged, $plan),
            self::TIMEOUT_SECONDS
        );
        $logger?->ok('Setup commands finished');
    }
}
