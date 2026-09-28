<?php

namespace App\System\Project\Dind\Strategy;

use App\System\Project\Dind as DindProject;
use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\Runtime\HostRunProject;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\Ruby\RailsProxy;
use App\Lib\Deploy\Platform\Runtime\Ruby\RailsSecret;
use App\Lib\Deploy\Platform\Runtime\Ruby\RubyApp;
use App\Lib\Deploy\Platform\Runtime\Ruby\RubyDockerfile;
use App\Lib\Deploy\Platform\Runtime\Ruby\RubyEnvironment;
use App\Lib\Deploy\Platform\Runtime\RubyRuntime;
use App\Lib\Deploy\Platform\Runtime\Ruby\RubyServer;
use App\Lib\Deploy\Platform\Runtime\Ruby\SystemPackages;

/**
 * Rails and plain Rack applications.
 *
 * Most of what is special here is Rails' own boot contract rather than
 * anything about hosting: it aborts without a secret_key_base, its
 * production defaults force_ssl behind a proxy that terminates TLS itself,
 * and Host Authorization rejects the vhost it is served on unless told about
 * it. A Sinatra or Rack app has none of that, so everything Rails-shaped is
 * gated on {@see RubyApp::isRails()}.
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
     * @param array{port_hint: ?int, ...} $decision
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

        // Mounted: our base image with the account's directory bind-mounted,
        // gems in ~/project/vendor/bundle, and nothing built. The base image
        // is still ours and still built on the host -- it carries the apt
        // packages native gems need -- but it is now shared by every Ruby
        // account instead of being the bottom layer of a per-project image.
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

        $decision = $strategy->sidecars()->mergeRuntimeSidecars(
            $extra + [
                'strategy' => $decision['strategy'] ?? null,
                'runtime' => PlatformManifest::RUNTIME_NODE,
                'env' => array_merge(
                    $this->environment($app, $projectDir, $chown),
                    ComposeHarden::urlEnvironment($this->dind->publicAppUrl()),
                    $strategy->entrypoint()->deployPhaseEnvironment()
                ),
            ],
            $strategy->sidecars()->runtimeSidecarsFromProject($projectDir)
        );
        $this->dind->composeWriter()->writeGeneratedCompose(
            $projectDir,
            DeployCompose::framework(
                $strategy->composeDecision($decision),
                $port,
                $this->dind->publicAppUrl()
            ),
            $chown
        );
    }

    /**
     * @param array{port_hint: ?int, ...} $decision
     */
    private function port(array $decision): int
    {
        $port = (int) ($decision['port_hint'] ?? RubyServer::PORT);

        return $port > 0 ? $port : RubyServer::PORT;
    }

    /**
     * Prebuilt ruby base with the apt packages native gems need, so this
     * build starts at `bundle install` instead of paying ~14s of apt-get
     * that every account would otherwise repeat.
     */
    private function writeDockerfile(RubyApp $app, int $port, ?string $chown): void
    {
        $base = $this->dind->innerDocker()->ensureRubyBaseImage(
            RubyRuntime::imageFor($app->project),
            SystemPackages::for($app->gemfile())
        );
        $this->dind->system()->filesystem()->filePutContents(
            $app->project->projectDir . '/' . RubyDockerfile::FILENAME,
            (new RubyDockerfile($app, $port, $base))->render(),
            $chown,
            '644'
        );
        $this->dind->strategy()->contextIgnore()
            ->write($app->project->projectDir, RubyDockerfile::FILENAME, true, $chown);
    }

    /**
     * A Sinatra or Rack app has no config/, no credentials and no host
     * whitelist to extend, so everything Rails-shaped stops here.
     *
     * @return array<string, string>
     */
    private function environment(RubyApp $app, string $projectDir, ?string $chown): array
    {
        if (!$app->isRails()) {
            return RubyEnvironment::for($app);
        }
        $this->installHostInitializer($projectDir, $chown);

        return array_merge(RubyEnvironment::for($app), $this->proxyEnvironment($projectDir));
    }

    /**
     * Rails' own boot contract behind the hosting proxy: how to treat TLS,
     * plus a secret_key_base when the clone brought no master.key. Rails
     * aborts on boot without one, and deriving it per account keeps it
     * across restarts and rebuilds instead of logging every session out.
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
     * Rails 6+ Host Authorization blocks the configured public vhost when the
     * app keeps a host whitelist. Drop a tiny initializer that adds it.
     */
    public function installHostInitializer(string $projectDir, ?string $chown): void
    {
        if (!is_file($projectDir . '/config/application.rb')) {
            return;
        }
        $dir = $projectDir . '/config/initializers';
        if (!is_dir($dir)) {
            return;
        }
        $this->dind->system()->filesystem()->filePutContents(
            $dir . '/zz_panelalpha_hosts.rb',
            RailsProxy::hostAuthorizationInitializer($this->dind->publicAppUrl()),
            $chown,
            '644'
        );
    }
}
