<?php

namespace App\Lib\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\JsPackageManager;

/**
 * A detection decision, read once and typed.
 *
 * The decision travels the pipeline as an array because it crosses a process
 * boundary; this types it once for the writers.
 */
final class BuildRecipe
{
    public readonly string $runtime;

    public readonly string $packageManager;

    public readonly string $image;

    public readonly string $installCommand;

    public readonly string $buildCommand;

    public readonly string $runtimeImage;

    public readonly string $outputDirectory;

    /** A Node image whose toolchain the build stage copies in, or ''. */
    public readonly string $buildNodeImage;

    /** @var array<string, string> */
    public readonly array $env;

    /**
     * The V8 heap a build step may use, in MB, or null when none was resolved.
     *
     * A host build is told this through `--memory`; a build inside the
     * generated Dockerfile has no such flag, so the same number (70% of
     * `deploy.build_memory`) is handed to it here. Null leaves the image as it was.
     */
    public readonly ?int $nodeHeapMb;

    private readonly int $portHint;

    /**
     * @param array<string, mixed> $decision
     * @param array<string, true> $files lowercase basename => true
     */
    public function __construct(
        array $decision,
        public readonly array $files = [],
        public readonly string $projectDir = ''
    ) {
        $this->runtime = self::text($decision, 'runtime') ?: PlatformManifest::RUNTIME_NODE;
        $this->image = self::text($decision, 'image');
        $this->installCommand = self::text($decision, 'install_command');
        $this->buildCommand = self::text($decision, 'build_command');
        $this->runtimeImage = self::text($decision, 'runtime_image');
        $this->outputDirectory = self::text($decision, 'output_directory');
        $this->buildNodeImage = self::text($decision, 'build_node_image');
        $this->portHint = (int) ($decision['port_hint'] ?? 0);
        $this->env = self::environment($decision);
        $this->packageManager = self::text($decision, 'package_manager')
            ?: JsPackageManager::detectPackageManager($files);

        $heap = $decision['node_heap_mb'] ?? null;
        $this->nodeHeapMb = is_numeric($heap) && (int) $heap > 0 ? (int) $heap : null;
    }

    public function port(int $default): int
    {
        return $this->portHint > 0 ? $this->portHint : $default;
    }

    /**
     * Set as an `ARG`, not a shell prefix and not an `ENV`: a build script
     * forks its own Node (turbo, tsup, prisma), and an `ENV` would reach the
     * running application with a number sized for the engine's build container.
     */
    public function nodeHeapBuildArg(): array
    {
        return $this->nodeHeapMb === null
            ? []
            : ['NODE_OPTIONS' => '--max-old-space-size=' . $this->nodeHeapMb];
    }

    public function installCommandOr(string $default): string
    {
        return $this->installCommand ?: $default;
    }

    public function buildCommandOr(string $default): string
    {
        return $this->buildCommand ?: $default;
    }

    public function hasFile(string $name): bool
    {
        return isset($this->files[$name]);
    }

    /**
     * @return array<string, mixed>
     */
    public function package(): array
    {
        if ($this->projectDir === '') {
            return [];
        }

        return ProjectContext::readPackageJson($this->projectDir) ?? [];
    }

    public function isWorkspace(): bool
    {
        return JsPackageManager::isJsWorkspace($this->package(), $this->files);
    }

    /**
     * @param array<string, mixed> $decision
     */
    private static function text(array $decision, string $key): string
    {
        $value = $decision[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $decision
     * @return array<string, string>
     */
    private static function environment(array $decision): array
    {
        $declared = $decision['env'] ?? null;
        if (!is_array($declared)) {
            return [];
        }

        $env = [];
        foreach ($declared as $key => $value) {
            if (self::isEnvEntry($key, $value)) {
                $env[(string) $key] = (string) $value;
            }
        }

        return $env;
    }

    private static function isEnvEntry(mixed $key, mixed $value): bool
    {
        return is_string($key) && $key !== '' && (is_string($value) || is_int($value));
    }
}
