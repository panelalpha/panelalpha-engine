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
        $result = $this->system->runProcess(Privileged::docker()->containerNamedWithLabel(
            $username,
            self::WORKING_DIR_LABEL,
            $this->system->projectDirPath($username),
        ));

        return $result->isSuccessful() && trim($result->getOutput()) !== '';
    }

    /**
     * Running account containers, each with the runtime it runs under and when it started, as docker inspect
     * prints that time; only those Compose started from <projects dir>/<name>. Null when Docker could not be asked.
     *
     * @return ?array<string, array{runtime: string, startedAt: string}> container name => container
     */
    public function running(): ?array
    {
        $running = $this->inspectAccounts(Privileged::docker()->runningContainerIdsLabelled(self::WORKING_DIR_LABEL));

        return $running === null ? null : array_map(
            static fn (array $fields): array => ['runtime' => $fields[0], 'startedAt' => $fields[1]],
            $running,
        );
    }

    /**
     * Stopped account containers whose last start failed, with that start's
     * error; a container stopped on purpose has none. Null when Docker could not be asked.
     *
     * @return ?array<string, array{runtime: string, error: string}> container name => last start
     */
    public function failedStarts(): ?array
    {
        $stopped = $this->inspectAccounts(Privileged::docker()->stoppedContainerIdsLabelled(self::WORKING_DIR_LABEL));
        if ($stopped === null) {
            return null;
        }

        $failed = [];
        foreach ($stopped as $name => [$runtime, , $error]) {
            $error = json_decode($error);
            if (is_string($error) && $error !== '') {
                $failed[$name] = ['runtime' => $runtime, 'error' => $error];
            }
        }

        return $failed;
    }

    /**
     * @param  list<string>  $listIds  the root `docker ps` that prints the IDs
     * @return ?array<string, array{0: string, 1: string, 2: string}> container name => [runtime, start, last start's error]
     */
    private function inspectAccounts(array $listIds): ?array
    {
        $ids = $this->labelledContainerIds($listIds);
        if ($ids === null || $ids === []) {
            return $ids;
        }

        $output = $this->inspectOutput($ids);

        return $output === null ? null : $this->accountsIn($output);
    }

    /**
     * @param  list<string>  $listIds
     * @return ?list<string> IDs of the containers Compose labelled with a working directory
     */
    private function labelledContainerIds(array $listIds): ?array
    {
        $ps = $this->system->runProcess($listIds, [], 30);
        if (! $ps->isSuccessful()) {
            return null;
        }

        return array_values(array_filter(preg_split('/\s+/', trim($ps->getOutput())) ?: []));
    }

    /**
     * Name, runtime, working directory, start and last start's error per container, tab-separated; null only when
     * inspect prints nothing, since a container gone since listing fails it while the others still print.
     *
     * @param  list<string>  $ids
     */
    private function inspectOutput(array $ids): ?string
    {
        $inspect = $this->system->runProcess(Privileged::docker()->inspect(...$ids), [], 30);
        if (! $inspect->isSuccessful() && trim($inspect->getOutput()) === '') {
            return null;
        }

        return $inspect->getOutput();
    }

    /**
     * The containers whose working directory is their own account's directory.
     *
     * @return array<string, array{0: string, 1: string, 2: string}> container name => [runtime, start, last start's error]
     */
    private function accountsIn(string $inspectOutput): array
    {
        $containers = [];
        foreach (preg_split('/\R/', trim($inspectOutput)) ?: [] as $line) {
            $values = explode("\t", $line);
            if (count($values) !== 5) {
                continue;
            }
            $name = ltrim($values[0], '/');
            if ($name !== '' && $values[2] === $this->system->projectDirPath($name)) {
                $containers[$name] = [$values[1], $values[3], $values[4]];
            }
        }

        return $containers;
    }

    /**
     * Account containers no project owns, left by a create or delete that did
     * not finish. Reported for the operator; never removed here.
     *
     * @param  iterable<string>  $projects  usernames of every existing project
     * @param  ?array<int, string>  $within  the accounts whose orphans to report, or null for every account
     * @return array{count: int, containers: list<array{name: string, state: string, project: string, cleanup: string}>, warning: ?string}
     */
    public function orphanReport(iterable $projects, ?array $within = null): array
    {
        $result = $this->system->runProcess(Privileged::docker()->containersLabelled(self::WORKING_DIR_LABEL), [], 30);

        if (! $result->isSuccessful()) {
            return [
                'count' => 0,
                'containers' => [],
                'warning' => 'Host containers could not be listed, so leftover account containers were not checked: '
                    .trim($result->getErrorOutput() ?: $result->getOutput()),
            ];
        }

        $orphans = self::orphansIn($result->getOutput(), $this->system->projectsDirPath(), $projects);

        return self::report($within === null ? $orphans : self::orphansOf($orphans, $within));
    }

    /**
     * @param  list<array{name: string, state: string, project: string, cleanup: string}>  $orphans
     * @param  array<int, string>  $accounts
     * @return list<array{name: string, state: string, project: string, cleanup: string}>
     */
    private static function orphansOf(array $orphans, array $accounts): array
    {
        return array_values(array_filter($orphans, fn (array $orphan): bool => in_array($orphan['project'], $accounts, true)));
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
