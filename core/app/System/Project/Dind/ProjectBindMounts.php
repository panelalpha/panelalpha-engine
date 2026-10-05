<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind as DindProject;

/**
 * Which running containers bind-mount something under ~/project. Those keep
 * reading the old, unlinked tree once a rebuild replaces it, so only they
 * force the app down before the wipe (engine#33).
 */
final class ProjectBindMounts
{
    private const TIMEOUT_SECONDS = 30;

    /** One line per container: its service, then each bind source, tab-separated. */
    private const INSPECT_FORMAT = '{{index .Config.Labels "com.docker.compose.service"}}'
        . '{{range .Mounts}}{{if eq .Type "bind"}}{{"\t"}}{{.Source}}{{end}}{{end}}';

    public function __construct(private DindProject $project)
    {
    }

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
        $ps = implode(' ', array_map(
            'escapeshellarg',
            $this->project->userAppComposeCommand(['ps', '--quiet'])
        ));
        $script = 'ids=$(' . $ps . ') || exit 1; [ -n "$ids" ] || exit 0; docker inspect --format '
            . escapeshellarg('{{.Id}}{{"\t"}}' . self::INSPECT_FORMAT) . ' $ids';

        try {
            $raw = $this->project->shell()->execAsUserQuiet(['bash', '-c', $script], [], self::TIMEOUT_SECONDS);
        } catch (\Throwable) {
            return null;
        }

        return self::parse($raw, $this->project->userAppDirPath());
    }

    /**
     * {@see read()} from `docker inspect` lines: id, service, bind sources.
     *
     * @return array{mounts: list<string>, containers: list<string>, running: list<string>}
     */
    public static function parse(string $inspect, string $projectDir): array
    {
        $withoutIds = [];
        $containers = [];
        $running = [];
        foreach (preg_split('/\R/', trim($inspect)) ?: [] as $line) {
            [$id, $rest] = array_pad(explode("\t", $line, 2), 2, '');
            $withoutIds[] = $rest;
            if (trim($id) === '') {
                continue;
            }
            $running[] = trim($id);
            if (self::under($rest, $projectDir) !== []) {
                $containers[] = trim($id);
            }
        }

        return ['mounts' => self::under(implode("\n", $withoutIds), $projectDir), 'containers' => $containers, 'running' => $running];
    }

    /**
     * @return list<string>
     */
    public static function under(string $inspect, string $projectDir): array
    {
        $root = rtrim($projectDir, '/');
        $found = [];
        foreach (preg_split('/\R/', trim($inspect)) ?: [] as $line) {
            $fields = explode("\t", $line);
            $service = trim((string) array_shift($fields));
            foreach ($fields as $source) {
                $source = rtrim(trim($source), '/');
                if ($source !== $root && !str_starts_with($source, $root . '/')) {
                    continue;
                }
                $relative = $source === $root ? '.' : './' . substr($source, strlen($root) + 1);
                $found[] = ($service !== '' ? $service : 'a container') . " mounts {$relative}";
            }
        }

        return array_values(array_unique($found));
    }
}
