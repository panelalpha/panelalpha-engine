<?php

namespace App\System;

use App\System;

/**
 * Host containers of project accounts. Compose labels each with the account's
 * directory, which is what sets it apart from a foreign container of the same name.
 */
final class AccountContainers
{
    public const WORKING_DIR_LABEL = 'com.docker.compose.project.working_dir';

    public function __construct(
        private System $system,
    ) {
    }

    /** The account's own container of that name exists; one with only the name does not count. */
    public function ownsContainerNamed(string $username): bool
    {
        $result = $this->system->runProcess([
            'sudo', 'docker', 'ps', '-a',
            '--filter', 'name=^/' . preg_quote($username, null) . '$',
            '--filter', 'label=' . self::WORKING_DIR_LABEL . '=' . $this->system->projectDirPath($username),
            '--format', '{{.Names}}',
        ]);

        return $result->isSuccessful() && trim($result->getOutput()) !== '';
    }

    /**
     * Running account containers, each with the runtime it runs under; only
     * those Compose started from <projects dir>/<name>. Null when Docker could not be asked.
     *
     * @return ?array<string, string> container name => runtime
     */
    public function runningRuntimes(): ?array
    {
        $ps = $this->system->runProcess([
            'sudo', 'docker', 'ps', '--filter', 'label=' . self::WORKING_DIR_LABEL, '--format', '{{.ID}}',
        ], [], 30);
        if (!$ps->isSuccessful()) {
            return null;
        }
        $ids = array_values(array_filter(preg_split('/\s+/', trim($ps->getOutput())) ?: []));
        if ($ids === []) {
            return [];
        }
        $inspect = $this->system->runProcess([
            'sudo', 'docker', 'inspect', '--format',
            '{{.Name}}{{"\t"}}{{.HostConfig.Runtime}}{{"\t"}}{{index .Config.Labels "' . self::WORKING_DIR_LABEL . '"}}',
            ...$ids,
        ], [], 30);
        // A container gone between the two calls fails inspect but still prints the others.
        if (!$inspect->isSuccessful() && trim($inspect->getOutput()) === '') {
            return null;
        }

        $running = [];
        foreach (preg_split('/\R/', trim($inspect->getOutput())) ?: [] as $line) {
            $fields = explode("\t", $line);
            if (count($fields) !== 3) {
                continue;
            }
            $name = ltrim($fields[0], '/');
            if ($name !== '' && $fields[2] === $this->system->projectDirPath($name)) {
                $running[$name] = $fields[1];
            }
        }

        return $running;
    }

    /**
     * Account containers no project owns, left by a create or delete that did
     * not finish. Reported for the operator; never removed here.
     *
     * @param iterable<string> $projects usernames of every existing project
     * @return array{count: int, containers: list<array{name: string, state: string, project: string, cleanup: string}>, warning: ?string}
     */
    public function orphanReport(iterable $projects): array
    {
        $result = $this->system->runProcess([
            'sudo', 'docker', 'ps', '-a',
            '--filter', 'label=' . self::WORKING_DIR_LABEL,
            '--format', '{{.Names}}\t{{.State}}\t{{.Label "' . self::WORKING_DIR_LABEL . '"}}',
        ], [], 30);

        if (!$result->isSuccessful()) {
            return [
                'count' => 0,
                'containers' => [],
                'warning' => 'Host containers could not be listed, so leftover account containers were not checked: '
                    . trim($result->getErrorOutput() ?: $result->getOutput()),
            ];
        }

        return self::report(self::orphansIn($result->getOutput(), $this->system->projectsDirPath(), $projects));
    }

    /**
     * @param iterable<string> $projects
     * @return list<array{name: string, state: string, project: string, cleanup: string}>
     */
    public static function orphansIn(string $psOutput, string $projectsDir, iterable $projects): array
    {
        $owned = [];
        foreach ($projects as $project) {
            $owned[(string) $project] = true;
        }

        $orphans = [];
        foreach (preg_split('/\R/', trim($psOutput)) ?: [] as $line) {
            $fields = explode("\t", $line);
            if (count($fields) !== 3) {
                continue;
            }
            [$name, $state, $workingDir] = $fields;

            // Only a container Compose started from <projects dir>/<account>.
            if (dirname($workingDir) !== rtrim($projectsDir, '/')) {
                continue;
            }
            $project = basename($workingDir);
            if ($project === '' || isset($owned[$project])) {
                continue;
            }

            $orphans[] = [
                'name' => $name,
                'state' => $state,
                'project' => $project,
                'cleanup' => 'docker rm -f ' . $name,
            ];
        }

        return $orphans;
    }

    /**
     * @param list<array{name: string, state: string, project: string, cleanup: string}> $orphans
     * @return array{count: int, containers: list<array{name: string, state: string, project: string, cleanup: string}>, warning: ?string}
     */
    public static function report(array $orphans): array
    {
        $warning = null;
        if ($orphans !== []) {
            $listed = array_map(fn (array $c) => $c['name'] . ' (' . $c['state'] . ')', $orphans);
            $commands = array_map(fn (array $c) => $c['cleanup'], $orphans);
            $warning = count($orphans) . ' account container(s) on the host belong to no project: '
                . implode(', ', $listed) . '. A project create or delete that did not finish, or a reset engine '
                . 'database, left them behind, and a new project cannot take their name. The engine does not '
                . 'remove them. To remove them, run on the host: ' . implode('; ', $commands);
        }

        return ['count' => count($orphans), 'containers' => $orphans, 'warning' => $warning];
    }
}
