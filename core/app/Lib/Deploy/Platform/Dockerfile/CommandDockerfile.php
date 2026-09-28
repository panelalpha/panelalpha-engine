<?php

namespace App\Lib\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Template\Template;

/**
 * The generic recipe: an official language image, the source, whatever the
 * manifest said to install and build, and the staged entrypoint. Go, Rust,
 * Java and Python differ only in their manifest.
 */
final class CommandDockerfile implements DockerfileWriter
{
    private const DEFAULT_PORT = 8000;

    private readonly int $port;

    public function __construct(private readonly BuildRecipe $recipe)
    {
        $this->port = $recipe->port(self::DEFAULT_PORT);
    }

    public function render(): string
    {
        $values = [
            'image' => $this->recipe->image ?: NodeRuntime::defaultImage(),
            'env' => EnvironmentLines::of($this->environment()),
            'install_command' => $this->installCommand(),
            'build_command' => $this->recipe->buildCommand,
            'port' => $this->port,
            'entrypoint' => EntrypointInstall::lines(),
        ];

        if (!$this->isMultiStage()) {
            return Template::named('dockerfile/command')->render($values);
        }

        return Template::named('dockerfile/command-multistage')->render($values + [
            'runtime_image' => $this->recipe->runtimeImage,
            'output_directory' => trim($this->recipe->outputDirectory, '/'),
            // node, npm/npx, corepack and yarn 1, copied rather than installed:
            // the build stage is the SDK image, and apt there is a guess.
            'build_node_image' => $this->recipe->buildNodeImage ?: null,
        ]);
    }

    /**
     * Both halves are required: a runtime image with nothing to copy into it
     * ships an empty application, and an output directory alone is just where
     * the build wrote. .NET: the SDK compiles, the aspnet runtime runs, and the
     * single-stage image was the SDK plus the source tree -- 294s of layer
     * export against 63s of compile on Jellyfin.
     */
    private function isMultiStage(): bool
    {
        return $this->recipe->runtimeImage !== ''
            && trim($this->recipe->outputDirectory, '/') !== '';
    }

    /**
     * Recognised by the command, not a manifest field: this writer serves Go,
     * Rust, Java and Python alike and only pip has a cache worth mounting.
     *
     * `pip install --no-cache-dir` is left as written -- the flag disables the
     * cache, so the mount would cover a directory nothing writes to.
     */
    private function installCommand(): string
    {
        $command = $this->recipe->installCommand;
        if (!str_contains($command, 'pip install') || str_contains($command, '--no-cache-dir')) {
            return $command;
        }

        return PackageManagerCache::mountFor('pip') . $command;
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        return array_merge([
            'HOST' => '0.0.0.0',
            'PORT' => (string) $this->port,
        ], $this->recipe->env);
    }
}
