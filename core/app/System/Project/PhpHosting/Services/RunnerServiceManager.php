<?php

namespace App\System\Project\PhpHosting\Services;

use App\System\Privileged;
use App\System\Privileged\Arguments;
use App\System\Project\PhpHosting;

/**
 * The pre-s6 entrypoint runner: `entrypoint-runner.sh` starts each `entrypoint.d/<name>.sh` and tracks the PID it wrote.
 * The template ships s6; an account still on this layout moves to s6 when next rendered ({@see removeLayout()}).
 */
final class RunnerServiceManager implements ServiceManager
{
    private const RUNNER = '/entrypoint-runner.sh';

    /** The layout, relative to the project dir. */
    private const LAYOUT = ['entrypoint.d', 'entrypoint-runner.sh'];

    public function __construct(private readonly PhpHosting $project) {}

    /** Drop the runner's files from a project dir that is on s6 now. */
    public static function removeLayout(PhpHosting $project): void
    {
        foreach (self::LAYOUT as $path) {
            $project->system()->exec(Privileged::files()->removeTree($project->project()->projectDirPath().'/'.$path));
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
        $dir = $system->projectDirPath($this->project->username()).'/entrypoint.d';
        if ($system->runProcess(Privileged::files()->makeDirectories($dir))->isSuccessful()) {
            $system->runProcess(Privileged::fileLines()->removeScriptsIfAny($dir));
        }
        foreach ($services as $service) {
            if ($service->daemonStart !== []) {
                continue;
            }
            $system->filesystem()->filePutContents("{$dir}/{$service->name}.sh", $service->command);
        }
    }

    public function sync(): void
    {
        $this->project->system()->runProcess($this->project->execArgv(['bash', self::RUNNER, 'sync', '--all']));
    }

    public function reload(string $service): void
    {
        $this->project->system()->exec($this->project->execArgv(['service', Arguments::name($service), 'restart']));
    }

    public function restartArgv(string $service): array
    {
        return ['bash', self::RUNNER, 'restart', $service];
    }

    /**
     * The runner kills only the PID it wrote; an FPM master still alive after its stop (one a `service phpX-fpm restart`
     * started) is stopped by title, as there is no FPM pid file.
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
