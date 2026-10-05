<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Platform\Strategies;
use App\System\Project\Dind as DindProject;

/**
 * Container/service-level operations on the user's inner compose project.
 */
final class ContainerOperations
{
    public function __construct(
        private DindProject $project,
        private ShellOperations $shell,
    ) {
    }

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
            $containers = [];
            foreach (explode("\n", trim($output)) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                /** @var mixed */
                $item = json_decode($line, true);
                if (is_array($item)) {
                    $containers[] = $item;
                }
            }

            return $containers;
        } catch (\Exception) {
            return [];
        }
    }

    private const COMPOSE_TIMEOUT = 7200;

    /** The most lines one logs read returns; rotation keeps a container's log to ~30 MB. */
    public const MAX_LOG_LINES = 5000;

    /** How long one log follow runs before it ends itself. */
    public const FOLLOW_SECONDS = 600;

    /** Silence after which a follow asks whether its reader is still there. */
    public const FOLLOW_IDLE_SECONDS = 2;

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
            throw new \RuntimeException('compose stop failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Inner compose start (resume, no rebuild) after backup/restore.
     *
     * An account made from the template and never deployed has no inner compose
     * project, so there is nothing to resume.
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
        if (!in_array($action, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid project action: {$action}");
        }

        try {
            $this->refreshRunFileBefore($action);

            if ($action === 'pull') {
                $pullOut = $this->shell->execAsUser($this->project->userAppComposeCommand(['pull']), [], 600);
                $upOut = $this->shell->execAsUser(
                    $this->project->userAppComposeCommand(['up', '-d', '--remove-orphans']),
                    [],
                    600
                );

                return ['stdout' => $pullOut . "\n" . $upOut, 'stderr' => '', 'exit_code' => 0];
            }

            $cmd = $action === 'up'
                ? $this->project->userAppComposeCommand(['up', '-d', '--remove-orphans'])
                : $this->project->userAppComposeCommand([$action]);

            $stdout = $this->shell->execAsUser($cmd, [], 600);
            $this->clearStoppedByRequest($action);
            $cleanup = self::cleanupArgvAfter($action);
            if ($cleanup !== null) {
                try {
                    $this->shell->execAsUser($cleanup, [], 120);
                } catch (\Exception) {
                    // Disk left for the next down to reclaim; the stop itself worked.
                }
            }

            return ['stdout' => $stdout, 'stderr' => '', 'exit_code' => 0];
        } catch (\Exception $e) {
            return ['stdout' => '', 'stderr' => $e->getMessage(), 'exit_code' => 1];
        }
    }

    /**
     * What runs after `$action` succeeded. `down` leaves the anonymous volumes
     * of images that declare VOLUME behind, and the next `up` makes new ones,
     * so every rebuild leaked one (330 MB per Shinobi rebuild). Only volumes
     * labelled anonymous: named ones are the app's data, and a daemon older
     * than 23 (no label) prunes nothing instead of everything unused.
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
     * Whether `$action` must regenerate the run file from the client's
     * compose file before compose runs (ADR-0001 D7), so an edit the client
     * made over SSH or the file tools takes effect, with the hosting
     * hardening applied to it.
     *
     * Only where the run file is derived from the client's compose: the
     * compose strategies, and a welcome account (no git, no strategy) whose
     * client has created a compose file. A recipe's run file is generated,
     * not derived, so `up` leaves it alone.
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

        return $strategy === null && !$hasGitRepo && $hasClientCompose;
    }

    /**
     * Rewrites the run file only; no clone, no image build, no prepare.
     */
    private function refreshRunFileBefore(string $action): void
    {
        $user = $this->project->userModel();
        if (!self::regeneratesRunFile(
            $action,
            $user->getDeployStrategy(),
            $user->getGitRepo() !== null,
            $this->project->userAppExistingComposeFilePath() !== null,
        )) {
            return;
        }

        $this->project->strategy()->refreshComposeRunFile(
            $this->project->userAppDirPath(),
            $user->getChownString(),
        );
    }

    /**
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    public function serviceAction(string $service, string $action): array
    {
        $allowed = ['start', 'stop', 'restart'];
        if (!in_array($action, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid service action: {$action}");
        }
        self::assertServiceName($service);

        try {
            $output = $this->shell->execAsUser(
                $this->project->userAppComposeCommand([$action, $service]),
                [],
                120
            );
            $this->clearStoppedByRequest($action);

            return ['stdout' => $output, 'stderr' => '', 'exit_code' => 0];
        } catch (\Exception $e) {
            return ['stdout' => '', 'stderr' => $e->getMessage(), 'exit_code' => 1];
        }
    }

    /** Whatever starts the app again ends a stop that was asked for. */
    private function clearStoppedByRequest(string $action): void
    {
        if (!in_array($action, ['up', 'start', 'restart', 'pull'], true)) {
            return;
        }
        try {
            $this->project->userModel()->markAppStoppedByRequest(false);
        } catch (\Throwable) {
            // Bookkeeping for the health sweep; the action itself worked.
        }
    }

    public function getServiceLogs(string $service, int $lines = 200, ?string $since = null, ?string $until = null): string
    {
        self::assertServiceName($service);

        try {
            return $this->shell->execAsUser(
                $this->project->userAppComposeCommand(self::logsArgs($service, $lines, $since, $until, false)),
                [],
                60
            );
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Follow a service's log, handing each line and its timestamp to $onLine,
     * until the stream has run FOLLOW_SECONDS. Stopping the outer exec does not
     * stop the follow inside the account, so `timeout` bounds it there, and when
     * $onLine or $onIdle throws (the client went away) it is killed by its tag at once.
     * $onIdle runs after every FOLLOW_IDLE_SECONDS without a line.
     *
     * @param callable(string, ?string): void $onLine
     * @param ?callable(): void $onIdle
     */
    public function followServiceLogs(string $service, int $lines, ?string $since, callable $onLine, ?callable $onIdle = null): void
    {
        self::assertServiceName($service);

        $tag = 'PANELALPHA_LOG_FOLLOW=' . bin2hex(random_bytes(6));
        $compose = $this->project->userAppComposeCommand(self::logsArgs($service, $lines, $since, null, true));
        // composeCommand() starts with `env`; the tag rides along as one more variable.
        $command = ['timeout', (string) self::FOLLOW_SECONDS, 'env', $tag, ...array_slice($compose, 1)];

        $buffer = '';
        try {
            $this->shell->followAsUser(
                $command,
                self::FOLLOW_SECONDS + 10,
                function (string $type, string $data) use (&$buffer, $onLine): void {
                    $buffer .= $data;
                    while (($end = strpos($buffer, "\n")) !== false) {
                        $raw = rtrim(substr($buffer, 0, $end), "\r");
                        $buffer = (string) substr($buffer, $end + 1);
                        $onLine(...self::splitTimestamp($raw));
                    }
                },
                $onIdle,
                self::FOLLOW_IDLE_SECONDS,
            );
        } catch (\Throwable $e) {
            try {
                $this->shell->execAsUser(['pkill', '-TERM', '-f', $tag], [], 30);
            } catch (\Throwable) {
                // Nothing left to stop; `timeout` ends it otherwise.
            }
            throw $e;
        }
        if ($buffer !== '') {
            $onLine(...self::splitTimestamp(rtrim($buffer, "\r")));
        }
    }

    /**
     * @return list<string>
     */
    public static function logsArgs(string $service, int $lines, ?string $since, ?string $until, bool $follow): array
    {
        $args = ['logs', "--tail={$lines}", '--no-color'];
        if ($follow) {
            array_push($args, '--follow', '--timestamps', '--no-log-prefix');
        }
        if ($since !== null && $since !== '') {
            $args[] = '--since=' . $since;
        }
        if ($until !== null && $until !== '') {
            $args[] = '--until=' . $until;
        }
        $args[] = $service;

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
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $service)) {
            throw new \InvalidArgumentException("Invalid service name: {$service}");
        }
    }
}
