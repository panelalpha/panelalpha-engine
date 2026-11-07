<?php

namespace App\Lib\Deploy\Platform\Runtime\Ruby;

use App\Lib\Deploy\Platform\Dockerfile\EnvironmentLines;
use App\Lib\Deploy\Platform\DockerfileBuilder;
use App\Lib\Deploy\Platform\Runtime\RubyRuntime;
use App\Lib\Deploy\Template\Template;

/**
 * Production Dockerfile for a Ruby app that ships none of its own, gems bundled in a Ruby stage and
 * the frontend in a Node one; named panelalpha.Dockerfile so the next detect pass still sees Rails.
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
     * @param  array<string, true>  $files  lowercase basename => true
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

    /**
     * With `js_bundling` the precompile skips the Node stage's jsbundling/cssbundling output, through
     * SKIP_*_BUILD and, for older gems that lack that switch, no-op yarn/npm/pnpm/bun shims.
     */
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
     * Production Rails serves only precompiled assets, else every asset tag raises AssetNotFound; a failed
     * precompile does not fail the build, as some apps cannot boot without their database.
     */
    private function precompilesAssets(): bool
    {
        if (! $this->app->isRails()) {
            return false;
        }
        $gemfile = $this->app->gemfile();

        return $gemfile->requiresAny(['sprockets-rails', 'sprockets', 'propshaft', 'sass-rails'])
            || $gemfile->locks('sprockets-rails')
            || $gemfile->locks('propshaft')
            || FrontendStage::bundlesIntoAssets($this->app);
    }

    /**
     * A host-built base already carries the apt packages, so the account's build starts at
     * `bundle install`.
     */
    private function baseImage(): string
    {
        return $this->prebuilt ? (string) $this->baseImage : RubyRuntime::imageFor($this->app->project);
    }
}
