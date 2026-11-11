<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Health\CheckResult;
use App\Lib\Deploy\Health\HealthCheck;
use App\Lib\Deploy\Port\ListeningSockets;
use App\Lib\Deploy\Port\SilentPort;
use App\System\Project\Dind as DindProject;
use Illuminate\Support\Facades\Log;

/**
 * Nothing answered and nothing is restarting: per container publishing a probed port, what it listens on
 * instead. {@see SilentPort}.
 */
final class SilentPortCheck
{
    public const ID = 'app-port-silent';

    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private DindProject $project,
    ) {}

    /**
     * The failed check, its evidence naming the services up but bound to nothing yet, which the deploy waits on.
     *
     * @param  list<array<string, mixed>>  $results  the probe's per-port results
     * @return array<string, mixed>|null
     */
    public function check(array $results): ?array
    {
        $silent = self::silentPorts($results);
        if ($silent === null || $silent === []) {
            return null;
        }

        try {
            [$findings, $starting] = $this->diagnose($silent);
        } catch (\Throwable $e) {
            Log::debug('Silent-port check could not run: '.AppHealth::trimReason($e->getMessage()));

            return null;
        }
        if ($findings === []) {
            return null;
        }

        return [
            'id' => self::ID,
            'group' => 'runtime',
            'status' => CheckResult::STATUS_FAIL,
            'severity' => HealthCheck::SEVERITY_ERROR,
            'title' => 'The application is running but does not answer on the port it publishes.',
            'detail' => implode(' ', $findings),
            'fix' => 'Its output is in the deploy log and in the container output.',
            'evidence' => ['silent' => $findings, 'starting' => array_values(array_unique($starting))],
        ];
    }

    /**
     * The ports nobody answered on; null once one answered. One that answered a 5xx is not silent: that is an
     * application error, which the response checks report.
     *
     * @param  list<array<string, mixed>>  $results
     * @return ?list<int>
     */
    private static function silentPorts(array $results): ?array
    {
        $silent = [];
        foreach ($results as $result) {
            if (($result['status'] ?? null) === AppHealth::STATUS_OK) {
                return null;
            }
            if (($result['http_code'] ?? null) !== null) {
                continue;
            }
            $silent[] = (int) ($result['port'] ?? 0);
        }

        return $silent;
    }

    /**
     * @param  list<int>  $silent
     * @return array{0: list<string>, 1: list<string>} the findings, and the services bound to nothing
     */
    private function diagnose(array $silent): array
    {
        $ps = $this->project->accountDaemon()->stackPs(false, self::TIMEOUT_SECONDS);
        $findings = [];
        $starting = [];
        foreach (SilentPort::publishers($ps, $silent) as $publisher) {
            $sockets = $this->socketsOf($publisher['name']);
            $findings[] = SilentPort::diagnose(
                $publisher['service'],
                $publisher['published'],
                $publisher['target'],
                $sockets
            );
            if (SilentPort::listensOnNothing($sockets, $publisher['target'])) {
                $starting[] = $publisher['service'];
            }
        }

        return [$findings, $starting];
    }

    /**
     * The services a {@see check()} result found running with nothing bound.
     *
     * @param  array<string, mixed>|null  $check
     * @return list<string>
     */
    public static function starting(?array $check): array
    {
        $starting = $check['evidence']['starting'] ?? [];

        return is_array($check) && ($check['id'] ?? null) === self::ID && is_array($starting)
            ? array_values(array_filter($starting, is_string(...)))
            : [];
    }

    /**
     * What the container listens on, read from its process: the image may ship
     * neither `ss` nor a shell.
     *
     * @return list<array{addr: string, port: int}>
     */
    private function socketsOf(string $container): array
    {
        return ListeningSockets::fromProcNet(
            $this->project->accountDaemon()->listeningSockets($container, self::TIMEOUT_SECONDS)
        );
    }
}
