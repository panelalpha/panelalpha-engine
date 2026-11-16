<?php

namespace App\System\Project\Dind\Strategy;

use App\Lib\Deploy\Compose\AppRoot;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\Dockerfile\NginxConfig;
use App\Lib\Deploy\Platform\DockerfileBuilder;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\Lib\Deploy\Platform\Runtime\HostRunProject;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use App\Lib\Deploy\Platform\Runtime\StandaloneNodeServe;
use App\Lib\Deploy\Platform\StageScript;
use App\System;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\HostCompile;

/**
 * A framework a manifest recognised: host-compiled ({@see HostCompile}) and served from a stock image, or built
 * from a generated `panelalpha.Dockerfile`, a name the next detect pass does not take for the user's own.
 */
class FrameworkStrategy
{
    private const NGINX_PORT = 8080;

    private const NODE_PORT = 3000;

    private DindProject $dind;

    public function __construct(DindProject $dind)
    {
        $this->dind = $dind;
    }

    /**
     * The deploy phase goes into the env once here, as both compose-writing paths read it.
     *
     * @param  array<string, mixed>  $decision
     */
    public function apply(
        array $decision,
        string $projectDir,
        ?string $chown,
        ?AppConfig $appConfig = null
    ): void {
        $isNginx = ($decision['runtime'] ?? '') === PlatformManifest::RUNTIME_NGINX;
        $decision['env'] = array_merge(
            is_array($decision['env'] ?? null) ? $decision['env'] : [],
            $this->dind->strategy()->entrypoint()->deployPhaseEnvironment()
        );

        if ($isNginx || DeployCompose::isHostCompiled($decision)) {
            $this->applyHostCompiled($decision, $projectDir, $chown, $isNginx, $appConfig);

            return;
        }

        $this->applyGeneratedBuild($decision, $projectDir, $chown, $appConfig);
    }

    /**
     * nginx-static and standalone-Node recipes are compiled on the host and served from a stock image, which has
     * nothing baked in, so the staged entrypoint runs as the command or the recipe's migrate never runs.
     *
     * @param  array<string, mixed>  $decision
     */
    private function applyHostCompiled(
        array $decision,
        string $projectDir,
        ?string $chown,
        bool $isNginx,
        ?AppConfig $appConfig = null
    ): void {
        $system = $this->dind->system();

        if ($isNginx) {
            $system->filesystem()->writeFileReplacingPath(
                $projectDir.'/'.NginxConfig::FILENAME,
                NginxConfig::contents(),
                $chown,
                '644'
            );
        }
        $port = (int) ($decision['port_hint'] ?? ($isNginx ? self::NGINX_PORT : self::NODE_PORT));

        $decision['image'] = $this->runtimeImage($decision, $projectDir, $isNginx);
        if (DeployCompose::isStandaloneNodeOutput($decision)) {
            $this->writeStandaloneServe($system, $projectDir, $chown);
        }

        if ($this->dind->strategy()->entrypoint()->write($decision, $projectDir, $chown, [], $appConfig)) {
            $decision['entrypoint'] = StageScript::FILENAME;
        }

        $this->writeCompose($decision, $projectDir, $chown, $port);
    }

    /**
     * Only a Node project's image comes from its lockfile; a command runtime keeps its recipe's image,
     * which is also what the host compile used, apart from a Python one's shared base.
     *
     * @param  array<string, mixed>  $decision
     */
    private function runtimeImage(array $decision, string $projectDir, bool $isNginx): string
    {
        if ($isNginx || HostRunProject::isNode($decision['strategy'] ?? null)) {
            return $this->nodeImage($decision, $projectDir);
        }

        return PythonBase::imageFor(
            $this->dind,
            $projectDir,
            (string) ($decision['image'] ?? '')
        );
    }

    /**
     * The interpreter the lockfile named, read where the host compile read (a host-run project's app_root):
     * a tree installed by one runtime and imported by another fails on packages only the first resolves.
     *
     * @param  array<string, mixed>  $decision
     */
    private function nodeImage(array $decision, string $projectDir): string
    {
        $appDir = DeployCompose::isHostRunProject($decision) ? AppRoot::path($projectDir, $decision) : $projectDir;
        $files = ProjectContext::listRootFiles($appDir);
        $package = ProjectContext::readPackageJson($appDir) ?? [];

        return HostNodeBuild::runtimeImage(
            JsPackageManager::detectPackageManager($files, $package),
            Images::nodeImage($appDir, $package)
        );
    }

    /** Only a bundled server needs the probe script; a mounted project starts from its own `package.json`. */
    private function writeStandaloneServe(System $system, string $projectDir, ?string $chown): void
    {
        $system->filesystem()->writeFileReplacingPath(
            $projectDir.'/'.StandaloneNodeServe::FILENAME,
            StandaloneNodeServe::script(),
            $chown,
            '644'
        );
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function applyGeneratedBuild(
        array $decision,
        string $projectDir,
        ?string $chown,
        ?AppConfig $appConfig
    ): void {
        $system = $this->dind->system();
        $files = ProjectContext::listRootFiles($projectDir);

        $this->dind->strategy()->entrypoint()->write($decision, $projectDir, $chown, [], $appConfig);
        $system->filesystem()->writeFileReplacingPath(
            $projectDir.'/'.DockerfileBuilder::FILENAME,
            DockerfileBuilder::generate($decision, $files, $projectDir),
            $chown,
            '644'
        );
        $this->dind->strategy()->contextIgnore()
            ->write($projectDir, DockerfileBuilder::FILENAME, true, $chown);

        $this->writeCompose($decision, $projectDir, $chown, (int) ($decision['port_hint'] ?? self::NODE_PORT));
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function writeCompose(array $decision, string $projectDir, ?string $chown, int $port): void
    {
        $strategy = $this->dind->strategy();
        $sidecars = $strategy->sidecars()->runtimeSidecarsFromProject($projectDir);
        $database = $strategy->database()->forService($decision, $sidecars['services']);
        $decision = $this->withDatabase($decision, $database);
        $decision = $strategy->sidecars()->mergeRuntimeSidecars($decision, $sidecars);
        $this->dind->composeWriter()->writeGeneratedCompose(
            $projectDir,
            DeployCompose::framework(
                $strategy->composeDecision($strategy->withPathPrefixKeys($decision, $projectDir)),
                $port,
                $this->dind->publicAppUrl()
            ),
            $chown
        );
    }

    /**
     * A manifest's `database: mysql`, its DB_* under the manifest's own env so a recipe's own spelling wins.
     *
     * @param  array<string, mixed>  $decision
     * @param  array<string, mixed>  $database
     * @return array<string, mixed>
     */
    private function withDatabase(array $decision, array $database): array
    {
        if ($database !== []) {
            $decision['env'] = array_merge($database['env'] ?? [], is_array($decision['env'] ?? null) ? $decision['env'] : []);
            if (isset($database['extra_hosts'])) {
                $decision['extra_hosts'] = $database['extra_hosts'];
            }
        }

        return $decision;
    }
}
