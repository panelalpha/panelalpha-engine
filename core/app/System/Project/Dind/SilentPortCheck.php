<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Port\ListeningSockets;
use App\Lib\Deploy\Port\SilentPort;
use App\Lib\Deploy\Health\CheckResult;
use App\Lib\Deploy\Health\HealthCheck;
use App\System\Project\Dind as DindProject;
use Illuminate\Support\Facades\Log;

/**
 * Nothing answered, and nothing is restarting either: the containers are up
 * and silent. Says, per container that publishes a probed port, what it is
 * listening on instead (engine#90). {@see SilentPort}.
 */
final class SilentPortCheck
{
    public const ID = 'app-port-silent';

    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private DindProject $project,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $results the probe's per-port results
     * @return array<string, mixed>|null
     */
    public function check(array $results): ?array
    {
        $silent = [];
        foreach ($results as $result) {
            if (($result['status'] ?? null) === AppHealth::STATUS_OK) {
                return null;
            }
            $silent[] = (int) ($result['port'] ?? 0);
        }
        if ($silent === []) {
            return null;
        }

        try {
            $ps = $this->project->shell()->execAsUserQuiet(
                $this->project->userAppComposeCommand(['ps', '--format', 'json']),
                [],
                self::TIMEOUT_SECONDS
            );
            $findings = [];
            foreach (SilentPort::publishers($ps, $silent) as $publisher) {
                $findings[] = SilentPort::diagnose(
                    $publisher['service'],
                    $publisher['published'],
                    $publisher['target'],
                    $this->socketsOf($publisher['name'])
                );
            }
        } catch (\Throwable $e) {
            Log::debug('Silent-port check could not run: ' . AppHealth::trimReason($e->getMessage()));

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
            'fix' => 'Its output is in the deploy log, or read it with container_service_logs.',
            'evidence' => ['silent' => $findings],
        ];
    }

    /**
     * What the container listens on, from its own /proc: the image may ship
     * neither `ss` nor a shell.
     *
     * @return list<array{addr: string, port: int}>
     */
    private function socketsOf(string $container): array
    {
        $name = escapeshellarg($container);
        $script = "pid=\$(docker inspect -f '{{.State.Pid}}' {$name} 2>/dev/null); "
            . '[ -n "$pid" ] && [ "$pid" != "0" ] || exit 0; '
            . 'cat /proc/$pid/net/tcp /proc/$pid/net/tcp6 2>/dev/null';

        return ListeningSockets::fromProcNet(
            $this->project->shell()->execQuiet(['bash', '-c', $script], [], self::TIMEOUT_SECONDS)
        );
    }
}
