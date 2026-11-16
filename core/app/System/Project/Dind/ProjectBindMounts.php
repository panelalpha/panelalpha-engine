<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind as DindProject;

/**
 * Which running containers bind-mount something under ~/project: they keep reading the old, unlinked tree once a
 * rebuild replaces it, so only they force the app down before the wipe.
 */
final class ProjectBindMounts
{
    private const TIMEOUT_SECONDS = 30;

    public function __construct(private DindProject $project) {}

    /**
     * "<service> mounts ./<path>" per bind under ~/project; null when it
     * could not be read, which the caller treats as "stop it".
     *
     * @return list<string>|null
     */
    public function running(): ?array
    {
        return $this->read()['mounts'] ?? null;
    }

    /**
     * The binds under ~/project, the containers holding them, and every
     * running container of the project.
     *
     * @return ?array{mounts: list<string>, containers: list<string>, running: list<string>}
     */
    public function read(): ?array
    {
        try {
            $daemon = $this->project->accountDaemon();
            $inspected = $daemon->containers($daemon->stackContainerIds(timeout: self::TIMEOUT_SECONDS), self::TIMEOUT_SECONDS);
        } catch (\Throwable) {
            return null;
        }

        return in_array(null, $inspected, true) ? null : self::parse($inspected, $this->project->userAppDirPath());
    }

    /**
     * {@see read()} from each container's inspect.
     *
     * @param  list<array<string, mixed>>  $inspected
     * @return array{mounts: list<string>, containers: list<string>, running: list<string>}
     */
    public static function parse(array $inspected, string $projectDir): array
    {
        $containers = [];
        $running = [];
        foreach ($inspected as $container) {
            $id = trim((string) ($container['Id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $running[] = $id;
            if (self::under([$container], $projectDir) !== []) {
                $containers[] = $id;
            }
        }

        return ['mounts' => self::under($inspected, $projectDir), 'containers' => $containers, 'running' => $running];
    }

    /**
     * "<service> mounts ./<path>" per bind under $projectDir, from the containers' inspects.
     *
     * @param  list<array<string, mixed>>  $inspected
     * @return list<string>
     */
    public static function under(array $inspected, string $projectDir): array
    {
        $root = rtrim($projectDir, '/');
        $found = [];
        foreach ($inspected as $container) {
            $service = trim((string) ($container['Config']['Labels']['com.docker.compose.service'] ?? ''));
            foreach (self::bindSources($container) as $source) {
                $source = rtrim(trim($source), '/');
                if ($source !== $root && ! str_starts_with($source, $root.'/')) {
                    continue;
                }
                $relative = $source === $root ? '.' : './'.substr($source, strlen($root) + 1);
                $found[] = ($service !== '' ? $service : 'a container')." mounts {$relative}";
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @param  array<string, mixed>  $container
     * @return list<string>
     */
    private static function bindSources(array $container): array
    {
        $sources = [];
        foreach (is_array($container['Mounts'] ?? null) ? $container['Mounts'] : [] as $mount) {
            if (is_array($mount) && ($mount['Type'] ?? null) === 'bind') {
                $sources[] = (string) ($mount['Source'] ?? '');
            }
        }

        return $sources;
    }
}
