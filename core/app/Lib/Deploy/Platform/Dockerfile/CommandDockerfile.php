<?php

namespace App\Lib\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use App\Lib\Deploy\Template\Template;

/**
 * The generic recipe: an official language image, the source, the manifest's install and build, and the
 * staged entrypoint. Go, Rust, Java and Python differ only in their manifest.
 */
final class CommandDockerfile implements DockerfileWriter
{
    private const DEFAULT_PORT = 8000;

    private readonly int $port;

    public function __construct(private readonly BuildRecipe $recipe)
    {
        $this->port = $recipe->port(self::DEFAULT_PORT);
    }

    /** A multi-stage build copies node, npm, corepack and yarn 1 from `build_node_image`: apt in the SDK image is a guess. */
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

        if (! $this->isMultiStage()) {
            return Template::named('dockerfile/command')->render($values);
        }

        return Template::named('dockerfile/command-multistage')->render($values + [
            'runtime_image' => $this->recipe->runtimeImage,
            'output_directory' => trim($this->recipe->outputDirectory, '/'),
            'build_node_image' => $this->recipe->buildNodeImage ?: null,
        ]);
    }

    /**
     * Both halves are required: a runtime image with nothing to copy ships an empty application. A single-stage SDK
     * image takes far longer to export than to compile.
     */
    private function isMultiStage(): bool
    {
        return $this->recipe->runtimeImage !== ''
            && trim($this->recipe->outputDirectory, '/') !== '';
    }

    /**
     * Only pip, recognised by the command, has a cache worth mounting; `pip install --no-cache-dir` is left as
     * written, since nothing would write to the mount.
     */
    private function installCommand(): string
    {
        $command = $this->recipe->installCommand;
        if (! str_contains($command, 'pip install') || str_contains($command, '--no-cache-dir')) {
            return $command;
        }

        return PackageManagerCache::mountFor('pip').$command;
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
