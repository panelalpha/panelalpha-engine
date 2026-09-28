<?php

namespace App\System\Project\Dind\Services;

use App\System\Project\Dind;
use App\System\Project\Dind\TenantEgressGuard;
use LogicException;

/**
 * supervisord, which accounts rendered before s6 still run: their project dir
 * has `supervisord.conf` and a `supervisord.conf.d/` of programs. The template
 * no longer ships these, so this class carries the programs the engine writes
 * at runtime. Such an account moves to s6 when it is next rendered from the
 * template, which removes this layout ({@see removeLayout()}).
 */
final class SupervisordServiceManager implements ServiceManager
{
    private const CONF = '/etc/supervisor/supervisord.conf';

    /** The layout, relative to the project dir. */
    private const LAYOUT = ['supervisord.conf', 'supervisord.conf.d'];

    /** Programs the engine writes into an account's supervisord.conf.d. */
    private const PROGRAMS = [
        'cloudflared' => ['command' => 'cloudflared --no-autoupdate tunnel run', 'priority' => 30],
        TenantEgressGuard::SERVICE => ['command' => "sh -c '" . TenantEgressGuard::LOOP . "'", 'priority' => 5],
    ];

    public function __construct(private readonly Dind $project)
    {
    }

    /** Drop the supervisord files from a project dir the s6 template was just rendered into. */
    public static function removeLayout(Dind $project): void
    {
        foreach (self::LAYOUT as $path) {
            $project->system()->exec(['sudo', 'rm', '-rf', $project->projectDirPath() . '/' . $path]);
        }
    }

    public function configure(string $service, bool $enabled, array $env = []): void
    {
        $system = $this->project->system();
        $dir = $this->project->projectDirPath() . '/supervisord.conf.d';
        $system->runProcess(['sudo', 'mkdir', '-p', $dir]);
        $system->filesystem()->filePutContents(
            $dir . '/' . $service . '.conf',
            self::programConf($service, $enabled, $env),
            null,
            '600'
        );
    }

    /**
     * The `[program:x]` block for $service. The environment is written even
     * when the program is disabled, so supervisord never sees a stale one.
     *
     * @param array<string, string> $env
     */
    public static function programConf(string $service, bool $enabled, array $env = []): string
    {
        $program = self::PROGRAMS[$service] ?? throw new LogicException("No supervisord program for {$service}.");

        $conf = "[program:{$service}]\n"
            . "command={$program['command']}\n"
            . "directory=/\n"
            . 'autostart=' . ($enabled ? 'true' : 'false') . "\n"
            . "autorestart=true\npriority={$program['priority']}\nstartsecs=3\nstartretries=10\n"
            . "stdout_logfile=/dev/stdout\nstdout_logfile_maxbytes=0\n"
            . "stderr_logfile=/dev/stderr\nstderr_logfile_maxbytes=0\n";
        if ($env !== []) {
            $pairs = [];
            foreach ($env as $name => $value) {
                $pairs[] = $name . '="' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
            }
            $conf .= 'environment=' . implode(',', $pairs) . "\n";
        }

        return $conf;
    }

    public function apply(string $service): void
    {
        $shell = $this->project->shell();
        $shell->runProcess(['supervisorctl', '-c', self::CONF, 'reread'], [], 30);
        $shell->runProcess(['supervisorctl', '-c', self::CONF, 'update', $service], [], 60);
    }

    public function stopArgv(string $service): array
    {
        return ['supervisorctl', '-c', self::CONF, 'stop', $service];
    }

    public function startArgv(string $service): array
    {
        return ['supervisorctl', '-c', self::CONF, 'start', $service];
    }

    public function signalArgv(string $service, string $signal): array
    {
        return ['supervisorctl', '-c', self::CONF, 'signal', strtoupper($signal), $service];
    }
}
