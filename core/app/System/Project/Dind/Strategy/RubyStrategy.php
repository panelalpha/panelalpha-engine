<?php

namespace App\System\Project\Dind\Strategy;

use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\HostRunProject;
use App\Lib\Deploy\Platform\Runtime\Ruby\RailsProxy;
use App\Lib\Deploy\Platform\Runtime\Ruby\RailsSecret;
use App\Lib\Deploy\Platform\Runtime\Ruby\RubyApp;
use App\Lib\Deploy\Platform\Runtime\Ruby\RubyDockerfile;
use App\Lib\Deploy\Platform\Runtime\Ruby\RubyEnvironment;
use App\Lib\Deploy\Platform\Runtime\Ruby\RubyServer;
use App\Lib\Deploy\Platform\Runtime\Ruby\SystemPackages;
use App\Lib\Deploy\Platform\Runtime\RubyRuntime;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\DeployStrategy;

/**
 * Rails and plain Rack applications. Rails' boot contract (secret_key_base, force_ssl, Host Authorization)
 * is what is special here, so everything Rails-shaped is gated on {@see RubyApp::isRails()}.
 */
class RubyStrategy
{
    private DindProject $dind;

    public function __construct(DindProject $dind)
    {
        $this->dind = $dind;
    }

    public function app(string $projectDir): RubyApp
    {
        return RubyApp::at($projectDir, ProjectContext::listRootFiles($projectDir));
    }

    /**
     * @param  array{port_hint: ?int, ...}  $decision
     */
    public function apply(
        array $decision,
        string $projectDir,
        ?string $chown,
        ?AppConfig $appConfig = null
    ): void {
        $strategy = $this->dind->strategy();
        $app = $this->app($projectDir);
        $port = $this->port($decision);

        $strategy->entrypoint()->write($decision, $projectDir, $chown, [], $appConfig);

        $extra = $this->mountedOrBuilt($decision, $app, $port, $chown);
        $decision = $strategy->sidecars()->mergeRuntimeSidecars(
            $extra + [
                'strategy' => $decision['strategy'] ?? null,
                'runtime' => PlatformManifest::RUNTIME_NODE,
                'env' => $this->serviceEnvironment($strategy, $app, $projectDir, $chown),
            ],
            $strategy->sidecars()->runtimeSidecarsFromProject($projectDir)
        );
        $this->writeCompose($strategy, $decision, $projectDir, $chown, $port);
    }

    /**
     * A mounted project runs the shared Ruby base with gems in ~/project/vendor/bundle and nothing built;
     * any other gets a per-project Dockerfile and no extra keys.
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, string>
     */
    private function mountedOrBuilt(array $decision, RubyApp $app, int $port, ?string $chown): array
    {
        $mounted = HostRunProject::isStrategy($decision['strategy'] ?? null);
        $extra = [];
        if ($mounted) {
            $base = $this->dind->innerDocker()->ensureRubyBaseImage(
                RubyRuntime::imageFor($app->project),
                SystemPackages::for($app->gemfile())
            );
            $extra = [
                'image' => $base ?: RubyRuntime::imageFor($app->project),
                'start_command' => RubyServer::command($app, $port),
            ];
        } else {
            $this->writeDockerfile($app, $port, $chown);
        }

        return $extra;
    }

    /**
     * @return array<string, string>
     */
    private function serviceEnvironment(DeployStrategy $strategy, RubyApp $app, string $projectDir, ?string $chown): array
    {
        return array_merge(
            $this->environment($app, $projectDir, $chown),
            ComposeHarden::urlEnvironment($this->dind->publicAppUrl(), $strategy->projectEnvFiles($projectDir)),
            $strategy->entrypoint()->deployPhaseEnvironment()
        );
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function writeCompose(DeployStrategy $strategy, array $decision, string $projectDir, ?string $chown, int $port): void
    {
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
     * @param  array{port_hint: ?int, ...}  $decision
     */
    private function port(array $decision): int
    {
        $port = (int) ($decision['port_hint'] ?? RubyServer::PORT);

        return $port > 0 ? $port : RubyServer::PORT;
    }

    /**
     * On the prebuilt Ruby base carrying the apt packages native gems need, so the build starts at `bundle install`.
     */
    private function writeDockerfile(RubyApp $app, int $port, ?string $chown): void
    {
        $base = $this->dind->innerDocker()->ensureRubyBaseImage(
            RubyRuntime::imageFor($app->project),
            SystemPackages::for($app->gemfile())
        );
        $this->dind->system()->filesystem()->writeFileReplacingPath(
            $app->project->projectDir.'/'.RubyDockerfile::FILENAME,
            (new RubyDockerfile($app, $port, $base))->render(),
            $chown,
            '644'
        );
        $this->dind->strategy()->contextIgnore()
            ->write($app->project->projectDir, RubyDockerfile::FILENAME, true, $chown);
    }

    /**
     * A Sinatra or Rack app has no config/, credentials or host whitelist, so everything Rails-shaped stops here.
     *
     * @return array<string, string>
     */
    private function environment(RubyApp $app, string $projectDir, ?string $chown): array
    {
        if (! $app->isRails()) {
            return RubyEnvironment::for($app);
        }
        $this->installHostInitializer($projectDir, $chown);

        return array_merge(RubyEnvironment::for($app), $this->proxyEnvironment($projectDir));
    }

    /**
     * Rails' boot contract behind the hosting proxy: its TLS settings, and a secret_key_base derived per account
     * when the clone brought no master.key, so sessions survive restarts and rebuilds.
     *
     * @return array<string, string>
     */
    public function proxyEnvironment(string $projectDir): array
    {
        $secrets = $this->dind->strategy()->secrets();
        $env = RailsProxy::sslEnvironment($this->dind->publicAppUrl());
        if (RailsSecret::mustBeGenerated($projectDir, $secrets->userEnvVars())) {
            $env['SECRET_KEY_BASE'] = $secrets->generatedSecretKeyBase();
        }

        return $env;
    }

    /**
     * An initializer adding the public vhost, which Rails 6+ Host Authorization blocks when the app keeps a whitelist.
     */
    public function installHostInitializer(string $projectDir, ?string $chown): void
    {
        if (! is_file($projectDir.'/config/application.rb')) {
            return;
        }
        $dir = $projectDir.'/config/initializers';
        if (! is_dir($dir)) {
            return;
        }
        $this->dind->system()->filesystem()->writeFileReplacingPath(
            $dir.'/zz_panelalpha_hosts.rb',
            RailsProxy::hostAuthorizationInitializer($this->dind->publicAppUrl()),
            $chown,
            '644'
        );
    }
}
