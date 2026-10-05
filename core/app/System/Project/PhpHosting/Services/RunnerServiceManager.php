<?php

namespace App\System\Project\PhpHosting\Services;

use App\System\Project\PhpHosting;

/**
 * The entrypoint runner, which accounts rendered before s6 still run: the
 * entrypoint starts redis and cron as system services, runs the init scripts,
 * then `entrypoint-runner.sh` starts each `entrypoint.d/<name>.sh` in the
 * background and tracks it by the PID it wrote. The template no longer ships
 * the runner; such an account moves to s6 when it is next rendered, which
 * removes this layout ({@see removeLayout()}).
 */
final class RunnerServiceManager implements ServiceManager
{
    private const RUNNER = '/entrypoint-runner.sh';

    /** The layout, relative to the project dir. */
    private const LAYOUT = ['entrypoint.d', 'entrypoint-runner.sh'];

    public function __construct(private readonly PhpHosting $project)
    {
    }

    /** Drop the runner's files from a project dir that is on s6 now. */
    public static function removeLayout(PhpHosting $project): void
    {
        foreach (self::LAYOUT as $path) {
            $project->system()->exec(['sudo', 'rm', '-rf', $project->project()->projectDirPath() . '/' . $path]);
        }
    }

    /** A service that can start as a daemon is started that way at boot, as these accounts always did. */
    public function bootScripts(array $services): array
    {
        $scripts = [];
        foreach ($services as $service) {
            $scripts += $service->daemonStart;
        }

        return $scripts;
    }

    public function write(array $services): void
    {
        $system = $this->project->system();
        $dir = $system->projectDirPath($this->project->username()) . '/entrypoint.d';
        $system->runProcess("sudo mkdir -p {$dir} && sudo rm -f {$dir}/*.sh");
        foreach ($services as $service) {
            if ($service->daemonStart !== []) {
                continue;
            }
            $system->filesystem()->filePutContents("{$dir}/{$service->name}.sh", $service->command);
        }
    }

    public function sync(): void
    {
        $this->project->system()->runProcess(
            "sudo docker compose -f {$this->project->composeFilePath()} exec -T php bash " . self::RUNNER . ' sync --all'
        );
    }

    public function reload(string $service): void
    {
        $this->project->system()->exec(
            "sudo docker compose -f {$this->project->composeFilePath()} exec -T php service {$service} restart"
        );
    }

    public function restartArgv(string $service): array
    {
        return ['bash', self::RUNNER, 'restart', $service];
    }

    /**
     * The runner only kills the PID it wrote itself. `service phpX-fpm restart`
     * left a daemonised master it never saw holding the socket, so the fresh
     * `-F` instance died on "Another FPM instance seems to already listen" and
     * the old master kept the old php.ini. Nothing starts one that way any more;
     * a master still running after the runner's stop is that leftover, so it is
     * stopped by process title (these images write no FPM pid file).
     */
    public function restartScript(string $service, string $mainProcess): string
    {
        $notManaged = self::EXIT_NOT_MANAGED;
        $runner = self::RUNNER;

        return <<<BASH
            [ -f /entrypoint.d/{$service}.sh ] || exit {$notManaged}
            bash {$runner} stop {$service} >/dev/null
            stray=\$(pgrep -d ' ' -f '{$mainProcess}')
            if [ -n "\$stray" ]; then
                echo "stopped a {$service} master the runner did not start: \$stray"
                pkill -QUIT -f '{$mainProcess}'
                for _ in \$(seq 20); do pgrep -f '{$mainProcess}' >/dev/null || break; sleep 0.5; done
                pkill -KILL -f '{$mainProcess}'
            fi
            bash {$runner} start {$service} >/dev/null
            BASH;
    }
}
