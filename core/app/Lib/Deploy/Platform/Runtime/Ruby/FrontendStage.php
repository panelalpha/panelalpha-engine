<?php

namespace App\Lib\Deploy\Platform\Runtime\Ruby;

use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use App\Lib\Deploy\Template\Template;

/**
 * The Node stage that compiles a Rails app's frontend.
 *
 * The package.json build script runs directly rather than through
 * `rails assets:precompile` or vite_ruby's `bin/vite`: both boot the full
 * application, and the application blocks on a database that does not exist
 * during an image build.
 *
 * Install with NODE_ENV unset so the build tooling in devDependencies is
 * present; production only for the build step itself.
 */
final class FrontendStage
{
    private const PNPM_INSTALL = 'pnpm install';

    private const IGNORE_SCRIPTS = ' --ignore-scripts';

    /** @var array<string, mixed> */
    private readonly array $package;

    private readonly string $packageManager;

    public function __construct(private readonly RubyApp $app)
    {
        $this->package = $app->project->package() ?? [];
        $this->packageManager = JsPackageManager::detectPackageManager($app->project->files, $this->package);
    }

    public static function render(RubyApp $app): string
    {
        return (new self($app))->build();
    }

    /**
     * jsbundling-rails / cssbundling-rails: the bundles go to app/assets/builds,
     * which the asset pipeline then serves.
     */
    public static function bundlesIntoAssets(RubyApp $app): bool
    {
        $gemfile = $app->gemfile();
        foreach (['jsbundling-rails', 'cssbundling-rails'] as $gem) {
            if ($gemfile->requires($gem) || $gemfile->locks($gem)) {
                return true;
            }
        }

        return false;
    }

    public function build(): string
    {
        // cssbundling's own script is `build:css`, beside jsbundling's `build`.
        $scripts = array_values(array_filter(
            ['build', 'build:css'],
            fn (string $script): bool => $this->app->project->script($script) !== ''
        ));
        if ($scripts === []) {
            return '';
        }
        $build = implode(' && ', array_map(
            fn (string $script): string => JsPackageManager::scriptCommand($this->packageManager, $script),
            $scripts
        ));
        if (self::bundlesIntoAssets($this->app)) {
            // The app stage copies it back whether or not a bundle was written.
            $build .= ' && mkdir -p app/assets/builds';
        }

        return Template::named('dockerfile/ruby-assets')->render([
            'node_image' => NodeRuntime::defaultImage(),
            'install_command' => $this->installCommand(),
            'build_command' => $build,
        ]);
    }

    /**
     * pnpm runs a project's own postinstall scripts, which for a Rails app
     * often shell out to `bundle` — absent from the Node stage.
     */
    private function installCommand(): string
    {
        $install = JsPackageManager::installCommand(
            $this->packageManager,
            $this->app->project->files,
            $this->package,
            $this->app->project->projectDir
        );

        return str_contains($install, self::PNPM_INSTALL) ? $install . self::IGNORE_SCRIPTS : $install;
    }
}
