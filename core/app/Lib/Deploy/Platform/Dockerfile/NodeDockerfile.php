<?php

namespace App\Lib\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use App\Lib\Deploy\Template\Template;

/**
 * A JS server: install, build, then hand the process to the entrypoint.
 */
final class NodeDockerfile implements DockerfileWriter
{
    private const DEFAULT_PORT = 3000;

    private const DEFAULT_INSTALL = 'npm install';

    private readonly string $image;

    private readonly int $port;

    public function __construct(private readonly BuildRecipe $recipe)
    {
        $this->image = NodeBaseImage::for($recipe);
        $this->port = $recipe->port(self::DEFAULT_PORT);
    }

    public function render(): string
    {
        return Template::named('dockerfile/node')->render([
            'image' => $this->image,
            // A build arg: the install is a Node process too and can exhaust the
            // default heap, but the number must not survive into the runtime image.
            'heap_env' => EnvironmentLines::buildArgs($this->recipe->nodeHeapBuildArg()),
            'git_install' => GitInstall::command($this->image),
            'install_layer' => $this->installLayer(),
            'env' => EnvironmentLines::of($this->environment()),
            'build_command' => NodeRuntime::withLegacyOpenssl($this->recipe->buildCommand, $this->recipe->projectDir),
            'port' => $this->port,
            'entrypoint' => EntrypointInstall::lines(),
        ]);
    }

    private function installLayer(): string
    {
        $install = $this->recipe->installCommandOr(self::DEFAULT_INSTALL);

        return rtrim((new NodeInstallLayer($this->recipe, $install))->render(), "\n");
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        return array_merge([
            'NODE_ENV' => 'production',
            'HOST' => '0.0.0.0',
            'HOSTNAME' => '0.0.0.0',
            'PORT' => (string) $this->port,
        ], $this->recipe->env);
    }
}
