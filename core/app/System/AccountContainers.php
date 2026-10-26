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
    ) {}

    /** The account's own container of that name exists; one with only the name does not count. */
    public function ownsContainerNamed(string $username): bool
    {
        $result = $this->system->runProcess([
            'sudo', 'docker', 'ps', '-a',
            '--filter', 'name=^/'.preg_quote($username, null).'$',
            '--filter', 'label='.self::WORKING_DIR_LABEL.'='.$this->system->projectDirPath($username),
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
        $running = $this->inspectAccounts([]);

        return $running === null ? null : array_map(static fn (array $fields): string => $fields[0], $running);
    }

    /**
     * Stopped account containers whose last start failed, with that start's
     * error; a container stopped on purpose has none. Null when Docker could not be asked.
     *
     * @return ?array<string, array{runtime: string, error: string}> container name => last start
     */
    public function failedStarts(): ?array
    {
        $stopped = $this->inspectAccounts(
            ['-a', '--filter', 'status=exited', '--filter', 'status=created'],
            '{{json .State.Error}}',
        );
        if ($stopped === null) {
            return null;
        }

        $failed = [];
        foreach ($stopped as $name => [$runtime, $error]) {
            $error = json_decode($error);
            if (is_string($error) && $error !== '') {
                $failed[$name] = ['runtime' => $runtime, 'error' => $error];
            }
        }

        return $failed;
    }

    /**
     * @param  list<string>  $psArgs
     * @return ?array<string, list<string>> container name => [runtime, ...$fields]
     */
    private function inspectAccounts(array $psArgs, string ...$fields): ?array
    {
        $ids = $this->labelledContainerIds($psArgs);
        if ($ids === null || $ids === []) {
            return $ids;
        }

        $output = $this->inspectOutput($ids, $fields);

        return $output === null ? null : $this->accountsIn($output, count($fields));
    }

    /**
     * @param  list<string>  $psArgs
     * @return ?list<string> IDs of the containers Compose labelled with a working directory
     */
    private function labelledContainerIds(array $psArgs): ?array
    {
        $ps = $this->system->runProcess([
            'sudo', 'docker', 'ps', ...$psArgs, '--filter', 'label='.self::WORKING_DIR_LABEL, '--format', '{{.ID}}',
        ], [], 30);
        if (! $ps->isSuccessful()) {
            return null;
        }

        return array_values(array_filter(preg_split('/\s+/', trim($ps->getOutput())) ?: []));
    }

    /**
     * Name, runtime, working directory and $fields per container, tab-separated.
     * A container gone since it was listed fails inspect but the others still
     * print, so only a failure with no output at all is null.
     *
     * @param  list<string>  $ids
     * @param  list<string>  $fields
     */
    private function inspectOutput(array $ids, array $fields): ?string
    {
        $format = '{{.Name}}{{"\t"}}{{.HostConfig.Runtime}}{{"\t"}}{{index .Config.Labels "'.self::WORKING_DIR_LABEL.'"}}';
        foreach ($fields as $field) {
            $format .= '{{"\t"}}'.$field;
        }
        $inspect = $this->system->runProcess(['sudo', 'docker', 'inspect', '--format', $format, ...$ids], [], 30);
        if (! $inspect->isSuccessful() && trim($inspect->getOutput()) === '') {
            return null;
        }

        return $inspect->getOutput();
    }

    /**
     * The containers whose working directory is their own account's directory.
     *
     * @return array<string, list<string>> container name => [runtime, ...$fields]
     */
    private function accountsIn(string $inspectOutput, int $fieldCount): array
    {
        $containers = [];
        foreach (preg_split('/\R/', trim($inspectOutput)) ?: [] as $line) {
            $values = explode("\t", $line);
            if (count($values) !== 3 + $fieldCount) {
                continue;
            }
            $name = ltrim($values[0], '/');
            if ($name !== '' && $values[2] === $this->system->projectDirPath($name)) {
                $containers[$name] = [$values[1], ...array_slice($values, 3)];
            }
        }

        return $containers;
    }

    /**
     * Account containers no project owns, left by a create or delete that did
     * not finish. Reported for the operator; never removed here.
     *
     * @param  iterable<string>  $projects  usernames of every existing project
     * @return array{count: int, containers: list<array{name: string, state: string, project: string, cleanup: string}>, warning: ?string}
     */
    public function orphanReport(iterable $projects): array
    {
        $result = $this->system->runProcess([
            'sudo', 'docker', 'ps', '-a',
            '--filter', 'label='.self::WORKING_DIR_LABEL,
            '--format', '{{.Names}}\t{{.State}}\t{{.Label "'.self::WORKING_DIR_LABEL.'"}}',
        ], [], 30);

        if (! $result->isSuccessful()) {
            return [
                'count' => 0,
                'containers' => [],
                'warning' => 'Host containers could not be listed, so leftover account containers were not checked: '
                    .trim($result->getErrorOutput() ?: $result->getOutput()),
            ];
        }

        return self::report(self::orphansIn($result->getOutput(), $this->system->projectsDirPath(), $projects));
    }

    /**
     * @param  iterable<string>  $projects
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
            $orphan = self::orphanOn($line, $projectsDir, $owned);
            if ($orphan !== null) {
                $orphans[] = $orphan;
            }
        }

        return $orphans;
    }

    /**
     * The `docker ps` line as an orphan, when Compose started it from
     * <projects dir>/<account> and no project owns that account.
     *
     * @param  array<string, true>  $owned
     * @return ?array{name: string, state: string, project: string, cleanup: string}
     */
    private static function orphanOn(string $line, string $projectsDir, array $owned): ?array
    {
        $fields = explode("\t", $line);
        if (count($fields) !== 3) {
            return null;
        }
        [$name, $state, $workingDir] = $fields;

        if (dirname($workingDir) !== rtrim($projectsDir, '/')) {
            return null;
        }
        $project = basename($workingDir);
        if ($project === '' || isset($owned[$project])) {
            return null;
        }

        return [
            'name' => $name,
            'state' => $state,
            'project' => $project,
            'cleanup' => 'docker rm -f '.$name,
        ];
    }

    /**
     * @param  list<array{name: string, state: string, project: string, cleanup: string}>  $orphans
     * @return array{count: int, containers: list<array{name: string, state: string, project: string, cleanup: string}>, warning: ?string}
     */
    public static function report(array $orphans): array
    {
        $warning = null;
        if ($orphans !== []) {
            $listed = array_map(fn (array $c) => $c['name'].' ('.$c['state'].')', $orphans);
            $commands = array_map(fn (array $c) => $c['cleanup'], $orphans);
            $warning = count($orphans).' account container(s) on the host belong to no project: '
                .implode(', ', $listed).'. A project create or delete that did not finish, or a reset engine '
                .'database, left them behind, and a new project cannot take their name. The engine does not '
                .'remove them. To remove them, run on the host: '.implode('; ', $commands);
        }

        return ['count' => count($orphans), 'containers' => $orphans, 'warning' => $warning];
    }
}
