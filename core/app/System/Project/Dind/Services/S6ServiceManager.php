<?php

namespace App\System\Project\Dind\Services;

use App\System\Project\Dind;
use InvalidArgumentException;

/**
 * s6, as the account template sets it up: `services/<name>` in the project
 * dir holds each service's `run`, an `env/` dir (one file per variable, read
 * by s6-envdir) and a `down` file when it should not run. The dir is mounted
 * at {@see SOURCE_DIR} and copied to {@see SCAN_DIR} when the account boots.
 */
final class S6ServiceManager implements ServiceManager
{
    /** Relative to the project dir. */
    public const SERVICES_DIR = 'services';

    /** The project dir's services/, as the account sees it. */
    public const SOURCE_DIR = '/etc/s6/account';

    /** Where the account's s6-svscan scans. */
    public const SCAN_DIR = '/run/service';

    /** s6-svc's flag for each signal it can send. */
    private const SIGNAL_FLAGS = [
        'HUP' => '-h', 'TERM' => '-t', 'INT' => '-i', 'QUIT' => '-q', 'KILL' => '-k',
        'ALRM' => '-a', 'USR1' => '-1', 'USR2' => '-2',
    ];

    public function __construct(private readonly Dind $project)
    {
    }

    /** Whether the account was rendered from the s6 template. */
    public static function manages(Dind $project): bool
    {
        return $project->system()->filesystem()->directoryExists(
            $project->projectDirPath() . '/' . self::SERVICES_DIR
        );
    }

    public function configure(string $service, bool $enabled, array $env = []): void
    {
        $system = $this->project->system();
        $dir = $this->project->projectDirPath() . '/' . self::SERVICES_DIR . '/' . self::name($service);

        // s6-envdir needs the dir even when it is empty.
        $system->exec(['sudo', 'rm', '-rf', $dir . '/env']);
        $system->exec(['sudo', 'mkdir', '-p', $dir . '/env']);
        foreach ($env as $name => $value) {
            $system->filesystem()->filePutContents($dir . '/env/' . self::name($name), $value . "\n", null, '600');
        }
        $system->exec($enabled ? ['sudo', 'rm', '-f', $dir . '/down'] : ['sudo', 'touch', $dir . '/down']);
    }

    public function apply(string $service): void
    {
        $this->project->shell()->runProcess(self::applyArgv($service), [], 60);
    }

    /**
     * Copy the service from the project dir into the scan dir, then keep it
     * down or (re)start it so a changed environment is read.
     *
     * @return list<string>
     */
    public static function applyArgv(string $service): array
    {
        $name = self::name($service);
        $src = self::SOURCE_DIR . '/' . $name;
        $dst = self::SCAN_DIR . '/' . $name;
        $scan = self::SCAN_DIR;

        return ['sh', '-c', <<<SH
            set -e
            mkdir -p {$dst}
            cp {$src}/run {$dst}/run
            rm -rf {$dst}/env && cp -a {$src}/env {$dst}/env
            s6-svscanctl -a {$scan}
            i=0; until s6-svok {$dst} || [ \$i -ge 50 ]; do sleep 0.1; i=\$((i+1)); done
            if [ -f {$src}/down ]; then
              touch {$dst}/down; s6-svc -d {$dst}
            else
              rm -f {$dst}/down; s6-svc -wd -T 10000 -d {$dst} || true; s6-svc -u {$dst}
            fi
            SH];
    }

    public function stopArgv(string $service): array
    {
        $dir = self::SCAN_DIR . '/' . self::name($service);

        // Kill what has not stopped after 30s, as supervisord did after its stopwaitsecs.
        return ['sh', '-c', "s6-svc -wd -T 30000 -d {$dir} || { s6-svc -k {$dir}; s6-svc -wd -T 10000 -d {$dir}; }"];
    }

    public function startArgv(string $service): array
    {
        return ['s6-svc', '-u', self::SCAN_DIR . '/' . self::name($service)];
    }

    public function signalArgv(string $service, string $signal): array
    {
        $flag = self::SIGNAL_FLAGS[strtoupper($signal)] ?? null;
        if ($flag === null) {
            throw new InvalidArgumentException("s6-svc cannot send {$signal}.");
        }

        return ['s6-svc', $flag, self::SCAN_DIR . '/' . self::name($service)];
    }

    /** Service and variable names go into paths and shell text unquoted. */
    private static function name(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Not a service or variable name: {$name}");
        }

        return $name;
    }
}
