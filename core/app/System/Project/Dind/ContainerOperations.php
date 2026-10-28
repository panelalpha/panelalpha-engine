<?php

namespace App\System\Project\Dind;

use App\Exceptions\ProblemException;
use App\Lib\Deploy\Platform\Strategies;
use App\System\Project\Dind as DindProject;

/**
 * Container/service-level operations on the user's inner compose project.
 */
final class ContainerOperations
{
    private const COMPOSE_TIMEOUT = 7200;

    /** The most lines one logs read returns; rotation keeps a container's log to ~30 MB. */
    public const MAX_LOG_LINES = 5000;

    /** How long one log follow runs before it ends itself. */
    public const FOLLOW_SECONDS = 600;

    /** Silence after which a follow asks whether its reader is still there. */
    public const FOLLOW_IDLE_SECONDS = 2;

    /** The longest partial line a follow buffers before flushing it as its own line. */
    public const MAX_LINE_BYTES = 65536;

    /** How long a stream waits for compose to know the service; a slow answer refuses nothing. */
    public const SERVICE_CHECK_SECONDS = 10;

    /**
     * A service name as a request may give it; a leading '-' would reach compose as a flag.
     * \z, since $ also matches before a final newline.
     */
    public const SERVICE_NAME = '/^[a-zA-Z0-9_][a-zA-Z0-9_-]*\z/';

    /** Actions that start the app again. */
    private const STARTING_ACTIONS = ['up', 'start', 'restart', 'pull'];

    /** Service actions that start the app again. */
    private const STARTING_SERVICE_ACTIONS = ['start', 'restart'];

    /** Actions whose `up` builds an image the account lacks, held as a deploy's build is. */
    private const BUILDING_ACTIONS = ['up', 'pull'];

    public function __construct(
        private DindProject $project,
        private ShellOperations $shell,
    ) {}

    /**
     * @return array<array<string, mixed>>
     */
    public function getContainers(): array
    {
        try {
            $output = $this->shell->execAsUser($this->project->userAppComposeCommand([
                'ps',
                '--format',
                'json',
                '--all',
            ]));

            return self::jsonLines($output);
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * The JSON objects of a one-object-per-line output; other lines are skipped.
     *
     * @return array<array<string, mixed>>
     */
    private static function jsonLines(string $output): array
    {
        $items = [];
        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $item = json_decode($line, true);
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Inner compose stop for backup downtime (throws on failure).
     */
    public function composeStop(): void
    {
        try {
            $this->shell->execAsUser(
                $this->project->userAppComposeCommand(['stop']),
                [],
                self::COMPOSE_TIMEOUT,
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('compose stop failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Inner compose start (resume, no rebuild) after backup/restore; an account never deployed
     * has no inner compose project to resume.
     */
    public function composeStart(): void
    {
        if ($this->project->app() === null) {
            return;
        }

        $this->shell->execAsUser(
            $this->project->userAppComposeCommand(['start']),
            [],
            self::COMPOSE_TIMEOUT,
        );
    }

    /**
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    public function projectAction(string $action): array
    {
        $allowed = ['up', 'start', 'stop', 'restart', 'down', 'pull'];
        if (! in_array($action, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid project action: {$action}");
        }

        try {
            if (in_array($action, self::STARTING_ACTIONS, true)) {
                (new AccountMapping($this->project))->ensureMapped();
            }
            $this->refreshRunFileBefore($action);
            $stdout = $this->runAction($action);
            $this->clearStoppedByRequest($action);

            return ['stdout' => $stdout, 'stderr' => '', 'exit_code' => 0];
        } catch (\Exception $e) {
            return ['stdout' => '', 'stderr' => $e->getMessage(), 'exit_code' => 1];
        }
    }

    private function runAction(string $action): string
    {
        $run = fn (): string => $action === 'pull' ? $this->pullAndUp() : $this->runProjectAction($action);

        return in_array($action, self::BUILDING_ACTIONS, true)
            ? $this->project->innerBuildGuard()->during($run)
            : $run();
    }

    private function pullAndUp(): string
    {
        $pullOut = $this->shell->execAsUser($this->project->userAppComposeCommand(['pull']), [], 600);
        $upOut = $this->shell->execAsUser(
            $this->project->userAppComposeCommand(['up', '-d', '--remove-orphans']),
            [],
            600
        );

        return $pullOut."\n".$upOut;
    }

    private function runProjectAction(string $action): string
    {
        $cmd = $action === 'up'
            ? $this->project->userAppComposeCommand(['up', '-d', '--remove-orphans'])
            : $this->project->userAppComposeCommand([$action]);

        $stdout = $this->shell->execAsUser($cmd, [], 600);
        $this->cleanUpAfter($action);

        return $stdout;
    }

    /** A failed cleanup leaves the disk for the next `down` to reclaim; the action itself worked. */
    private function cleanUpAfter(string $action): void
    {
        $cleanup = self::cleanupArgvAfter($action);
        if ($cleanup === null) {
            return;
        }
        try {
            $this->shell->execAsUser($cleanup, [], 120);
        } catch (\Exception) {
        }
    }

    /**
     * What runs after `$action` succeeded: `down` leaves the anonymous volumes of VOLUME images behind,
     * so prune those (by label: named ones are app data, and a pre-23 daemon then prunes nothing).
     *
     * @return list<string>|null
     */
    public static function cleanupArgvAfter(string $action): ?array
    {
        return $action === 'down'
            ? ['docker', 'volume', 'prune', '-f', '--filter', 'label=com.docker.volume.anonymous']
            : null;
    }

    /**
     * Whether `$action` must regenerate the hardened run file from the client's compose file first:
     * only where the run file derives from it (compose strategies, or a welcome account with one).
     */
    public static function regeneratesRunFile(
        string $action,
        ?string $strategy,
        bool $hasGitRepo,
        bool $hasClientCompose,
    ): bool {
        if ($action !== 'up' && $action !== 'pull') {
            return false;
        }
        if ($strategy === Strategies::COMPOSE || $strategy === Strategies::PAEMD) {
            return true;
        }

        return $strategy === null && ! $hasGitRepo && $hasClientCompose;
    }

    /**
     * Whether `$action` on this project rewrites the run file from the compose
     * file on disk before running it, so it starts on whatever that now holds.
     */
    public static function actionRegeneratesRunFile(DindProject $project, string $action): bool
    {
        $user = $project->userModel();

        return self::regeneratesRunFile(
            $action,
            $user->getDeployStrategy(),
            $user->getGitRepo() !== null,
            $project->userAppExistingComposeFilePath() !== null,
        );
    }

    /**
     * Rewrites the run file only; no clone, no image build, no prepare.
     */
    private function refreshRunFileBefore(string $action): void
    {
        if (! self::actionRegeneratesRunFile($this->project, $action)) {
            return;
        }

        $this->project->strategy()->refreshComposeRunFile(
            $this->project->userAppDirPath(),
            $this->project->userModel()->getChownString(),
        );
    }

    /**
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    public function serviceAction(string $service, string $action): array
    {
        $allowed = ['start', 'stop', 'restart'];
        if (! in_array($action, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid service action: {$action}");
        }
        self::assertServiceName($service);

        try {
            if (in_array($action, self::STARTING_SERVICE_ACTIONS, true)) {
                (new AccountMapping($this->project))->ensureMapped();
            }
            $output = $this->shell->execAsUser(
                $this->project->userAppComposeCommand([$action, '--', $service]),
                [],
                120
            );
            $this->clearStoppedByRequest($action);

            return ['stdout' => $output, 'stderr' => '', 'exit_code' => 0];
        } catch (\Exception $e) {
            $this->refuseUnknownService($service, $e->getMessage());

            return ['stdout' => '', 'stderr' => $e->getMessage(), 'exit_code' => 1];
        }
    }

    /**
     * Whatever starts the app again ends a stop that was asked for. A failure only loses
     * health-sweep bookkeeping; the action itself worked.
     */
    private function clearStoppedByRequest(string $action): void
    {
        if (! in_array($action, self::STARTING_ACTIONS, true)) {
            return;
        }
        try {
            $this->project->userModel()->markAppStoppedByRequest(false);
        } catch (\Throwable) {
        }
    }

    public function getServiceLogs(string $service, int $lines = 200, ?string $since = null, ?string $until = null): string
    {
        return $this->readServiceLogs($service, $lines, $since, $until, 60);
    }

    /**
     * Refuses a service the project does not have. Compose decides, as the
     * follow would: it takes a profiled service that `config --services` leaves out.
     */
    public function assertServiceExists(string $service): void
    {
        $this->readServiceLogs($service, 0, null, null, self::SERVICE_CHECK_SECONDS);
    }

    private function readServiceLogs(string $service, int $lines, ?string $since, ?string $until, int $timeout): string
    {
        self::assertServiceName($service);

        try {
            return $this->shell->execAsUser(
                $this->project->userAppComposeCommand(self::logsArgs($service, $lines, $since, $until, false)),
                [],
                $timeout
            );
        } catch (\Exception $e) {
            $this->refuseUnknownService($service, $e->getMessage());

            return $e->getMessage();
        }
    }

    /**
     * Whether compose refused this very name, quoted or not; not a longer name,
     * a dependency, or a profiled service it calls disabled.
     */
    private static function meansNoSuchService(string $error, string $service): bool
    {
        $name = preg_quote($service, '/');

        return preg_match('/no such service: "?'.$name.'(?![\w.-])(?!"?: disabled)/', $error) === 1;
    }

    /** Throws service_not_found when compose refused the service and does not list it either. */
    private function refuseUnknownService(string $service, string $error): void
    {
        if (! self::meansNoSuchService($error, $service)) {
            return;
        }

        $services = $this->listedServices();
        $listedAfterAll = in_array($service, $services, true);
        if ($listedAfterAll) {
            return;
        }

        throw self::serviceNotFound($service, $services);
    }

    /**
     * Every profile's services (logs and actions take a profiled one too), sorted because compose
     * orders them differently per call; empty when compose cannot list them.
     *
     * @return list<string>
     */
    private function listedServices(): array
    {
        try {
            $listed = $this->shell->execAsUser(
                $this->project->userAppComposeCommand(['--profile', '*', 'config', '--services']),
                [],
                60
            );
        } catch (\Exception) {
            return [];
        }
        $names = array_map('trim', explode("\n", $listed));
        $services = array_values(array_filter($names, static fn (string $s): bool => $s !== ''));
        sort($services, SORT_NATURAL);

        return $services;
    }

    /**
     * `expected` names every service; the message shows ten (as a missing git branch does)
     * and `examples` five (as the `in` rule does).
     *
     * @param  list<string>  $services
     */
    private static function serviceNotFound(string $service, array $services): ProblemException
    {
        $message = "The project has no service named '{$service}'.";
        if ($services === []) {
            return ProblemException::one('service', 'service_not_found', $message);
        }

        $more = count($services) - 10;
        $message .= ' Its services: '.implode(', ', array_slice($services, 0, 10))
            .($more > 0 ? " and {$more} more" : '').'.';

        return ProblemException::one('service', 'service_not_found', $message, [
            'expected' => 'one of: '.implode(', ', $services),
            'examples' => array_slice($services, 0, 5),
        ]);
    }

    /**
     * Follow a service's log for FOLLOW_SECONDS, handing each line and its timestamp to $onLine;
     * $onIdle runs after every FOLLOW_IDLE_SECONDS without a line.
     *
     * @param  callable(string, ?string): void  $onLine
     * @param  ?callable(): void  $onIdle
     */
    public function followServiceLogs(string $service, int $lines, ?string $since, callable $onLine, ?callable $onIdle = null): void
    {
        self::assertServiceName($service);

        $tag = 'PANELALPHA_LOG_FOLLOW='.bin2hex(random_bytes(6));
        $command = $this->followCommand($tag, $service, $lines, $since);

        $buffer = '';
        try {
            $this->shell->followAsUser(
                $command,
                self::FOLLOW_SECONDS + 10,
                function (string $type, string $data) use (&$buffer, $onLine): void {
                    $buffer = self::emitBufferedLines($buffer.$data, $onLine);
                },
                $onIdle,
                self::FOLLOW_IDLE_SECONDS,
            );
        } catch (\Throwable $e) {
            $this->stopFollow($tag);
            throw $e;
        }
        if ($buffer !== '') {
            $onLine(...self::splitTimestamp(rtrim(substr($buffer, 0, self::MAX_LINE_BYTES), "\r")));
        }
    }

    /**
     * The follow, tagged so it can be killed: the compose command starts with `env`, so the tag rides
     * along as one more variable, and `timeout` bounds it inside the account, which the outer exec cannot.
     *
     * @return list<string>
     */
    private function followCommand(string $tag, string $service, int $lines, ?string $since): array
    {
        $compose = $this->project->userAppComposeCommand(self::logsArgs($service, $lines, $since, null, true));

        return ['timeout', (string) self::FOLLOW_SECONDS, 'env', $tag, ...array_slice($compose, 1)];
    }

    /**
     * Hand every complete line to $onLine, and a partial one once it reaches MAX_LINE_BYTES so output
     * without newlines cannot grow the worker's memory; returns what is left over.
     *
     * @param  callable(string, ?string): void  $onLine
     */
    private static function emitBufferedLines(string $buffer, callable $onLine): string
    {
        while (($end = strpos($buffer, "\n")) !== false) {
            $raw = rtrim(substr($buffer, 0, $end), "\r");
            $buffer = (string) substr($buffer, $end + 1);
            $onLine(...self::splitTimestamp($raw));
        }
        while (strlen($buffer) >= self::MAX_LINE_BYTES) {
            $onLine(...self::splitTimestamp(substr($buffer, 0, self::MAX_LINE_BYTES)));
            $buffer = (string) substr($buffer, self::MAX_LINE_BYTES);
        }

        return $buffer;
    }

    /** Kill the follow inside the account by its tag; if nothing is left, `timeout` ends it anyway. */
    private function stopFollow(string $tag): void
    {
        try {
            $this->shell->execAsUser(['pkill', '-TERM', '-f', $tag], [], 30);
        } catch (\Throwable) {
        }
    }

    /**
     * Arguments of `compose logs`; `--` makes compose read any name as a service, never an option.
     *
     * @return list<string>
     */
    public static function logsArgs(string $service, int $lines, ?string $since, ?string $until, bool $follow): array
    {
        $args = ['logs', "--tail={$lines}", '--no-color'];
        if ($follow) {
            array_push($args, '--follow', '--timestamps', '--no-log-prefix');
        }
        if ($since !== null && $since !== '') {
            $args[] = '--since='.$since;
        }
        if ($until !== null && $until !== '') {
            $args[] = '--until='.$until;
        }
        array_push($args, '--', $service);

        return $args;
    }

    /**
     * `--timestamps` puts docker's RFC 3339 time first on every line.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function splitTimestamp(string $raw): array
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\S+) (.*)$/s', $raw, $m) === 1) {
            return [$m[2], $m[1]];
        }

        return [$raw, null];
    }

    private static function assertServiceName(string $service): void
    {
        if (! preg_match(self::SERVICE_NAME, $service)) {
            throw new \InvalidArgumentException("Invalid service name: {$service}");
        }
    }
}
