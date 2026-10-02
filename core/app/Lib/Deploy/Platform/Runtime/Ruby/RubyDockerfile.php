<?php

namespace App\Lib\Deploy\Platform\Runtime\Ruby;

use App\Lib\Deploy\Platform\Runtime\RubyRuntime;

use App\Lib\Deploy\Platform\Dockerfile\EnvironmentLines;
use App\Lib\Deploy\Platform\DockerfileBuilder;
use App\Lib\Deploy\Template\Template;

/**
 * Production Dockerfile for a Ruby app that ships none of its own.
 *
 * A Dockerfile the author wrote stays authoritative, exactly as it does over
 * railpack, so this is what a bare `rails new` gets rather than a replacement
 * for one somebody maintains. Gems are bundled in the Ruby stage and the
 * frontend in a Node one, so nothing boots Rails until the container starts.
 *
 * Named panelalpha.Dockerfile so the next detect pass still sees Rails, not a
 * user-owned Dockerfile.
 */
final class RubyDockerfile
{
    public const FILENAME = DockerfileBuilder::FILENAME;

    private readonly bool $prebuilt;

    public function __construct(
        private readonly RubyApp $app,
        private readonly int $port = RubyServer::PORT,
        private readonly ?string $baseImage = null
    ) {
        $this->prebuilt = $baseImage !== null && $baseImage !== '';
    }

    /**
     * @param array<string, true> $files lowercase basename => true
     */
    public static function generate(
        string $projectDir,
        array $files,
        ?int $portHint = null,
        ?string $baseImage = null
    ): string {
        $port = $portHint !== null && $portHint > 0 ? $portHint : RubyServer::PORT;

        return (new self(RubyApp::at($projectDir, $files), $port, $baseImage))->render();
    }

    public function render(): string
    {
        return Template::named('dockerfile/ruby')->render([
            'ruby_image' => $this->baseImage(),
            'prebuilt' => $this->prebuilt,
            'system_packages' => implode(' ', SystemPackages::for($this->app->gemfile())),
            'bundle_deployment' => $this->app->hasLockfile(),
            'environment' => EnvironmentLines::of(RubyEnvironment::for($this->app)),
            'frontend_stage' => $frontend = FrontendStage::render($this->app),
            'js_bundling' => $frontend !== '' && FrontendStage::bundlesIntoAssets($this->app),
            'assets_precompile' => $this->precompilesAssets(),
            'port' => $this->port,
            'start_command' => RubyServer::command($this->app, $this->port),
        ]);
    }

    /**
     * Production Rails serves only precompiled Sprockets/Propshaft assets, so
     * without this every asset tag raises AssetNotFound. SECRET_KEY_BASE_DUMMY
     * (Rails 7.1+) or a throwaway SECRET_KEY_BASE (older) boots the app
     * without the real key; a failure does not fail the build,
     * since some apps cannot boot without their database.
     *
     * jsbundling/cssbundling hook `javascript:build`/`css:build` into the
     * precompile, and the app stage has no Node: their output comes from the
     * Node stage, so the precompile skips them (SKIP_*_BUILD, and no-op
     * package manager shims for gem versions older than that switch).
     */
    private function precompilesAssets(): bool
    {
        if (!$this->app->isRails()) {
            return false;
        }
        $gemfile = $this->app->gemfile();

        return $gemfile->requiresAny(['sprockets-rails', 'sprockets', 'propshaft', 'sass-rails'])
            || $gemfile->locks('sprockets-rails')
            || $gemfile->locks('propshaft')
            || FrontendStage::bundlesIntoAssets($this->app);
    }

    /**
     * A host-built base already carries the apt packages, so the account's
     * build starts at `bundle install`.
     */
    private function baseImage(): string
    {
        return $this->prebuilt ? (string) $this->baseImage : RubyRuntime::imageFor($this->app->project);
    }
}
