<?php

namespace App\Lib\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use App\Lib\Deploy\Template\Template;

/**
 * The same JS build, with the toolchain thrown away: only the build output reaches the nginx that serves it,
 * which is why a manifest tells build commands from runtime ones.
 */
final class StaticSiteDockerfile implements DockerfileWriter
{
    private const DEFAULT_INSTALL = 'npm install';

    private const DEFAULT_BUILD = 'npm run build';

    private const DEFAULT_OUTPUT = 'dist';

    private readonly string $image;

    public function __construct(private readonly BuildRecipe $recipe)
    {
        $this->image = NodeBaseImage::for($recipe);
    }

    /** The heap declaration belongs to the builder stage alone: the nginx stage copies files and inherits nothing. */
    public function render(): string
    {
        return Template::named('dockerfile/static-nginx')->render([
            'image' => $this->image,
            'git_install' => GitInstall::command($this->image),
            'install_layer' => $this->installLayer(),
            'env' => EnvironmentLines::buildArgs($this->recipe->nodeHeapBuildArg()),
            'build_command' => NodeRuntime::withLegacyOpenssl(
                $this->recipe->buildCommandOr(self::DEFAULT_BUILD),
                $this->recipe->projectDir
            ),
            'nginx_image' => Images::NGINX_IMAGE,
            'nginx_conf' => NginxConfig::FILENAME,
            'output_directory' => $this->outputDirectory(),
        ]);
    }

    private function installLayer(): string
    {
        $install = $this->recipe->installCommandOr(self::DEFAULT_INSTALL);

        return rtrim((new NodeInstallLayer($this->recipe, $install))->render(), "\n");
    }

    private function outputDirectory(): string
    {
        return NodeRuntime::safeOutputDir($this->recipe->outputDirectory ?: self::DEFAULT_OUTPUT);
    }
}
