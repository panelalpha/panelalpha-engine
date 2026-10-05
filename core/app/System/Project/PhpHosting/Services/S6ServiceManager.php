<?php

namespace App\System\Project\PhpHosting\Services;

use App\System\Project\PhpHosting;
use InvalidArgumentException;

/**
 * s6, as the PHP hosting template sets it up: `services/<name>/run` in the
 * project dir, mounted at {@see SOURCE_DIR} and copied to {@see SCAN_DIR} when
 * the account boots. The template ships redis and cron; the engine writes the
 * PHP handlers (and Apache) next to them, every one in the foreground.
 */
final class S6ServiceManager implements ServiceManager
{
    /** Relative to the project dir. */
    public const SERVICES_DIR = 'services';

    /** The project dir's services/, as the account sees it. */
    public const SOURCE_DIR = '/etc/s6/account';

    /** Where the account's s6-svscan scans. */
    public const SCAN_DIR = '/run/service';

    /**
     * s6 starts each service in a session of its own, and the run file notes
     * which. A master that dies hard (kill -9, a crash) leaves its workers
     * holding the port, so no restart could bind it: finish kills what is
     * left of the session. A session, not a process group: runuser (lsphp)
     * puts its child in a group of its own. The images may have no procps.
     */
    private const RUN_PREFIX = "#!/bin/bash\necho \$\$ > sid\n";

    private const FINISH = <<<'SH'
        #!/bin/sh
        [ -s sid ] || exit 0
        sid=$(cat sid)
        rm -f sid
        for p in /proc/[0-9]*; do
          s=$(cat "$p/stat" 2>/dev/null) || continue
          set -- ${s##*) }
          [ "$4" = "$sid" ] && kill -9 "${p#/proc/}" 2>/dev/null
        done
        exit 0

        SH;

    public function __construct(private readonly PhpHosting $project)
    {
    }

    /** Whether the account was rendered from the s6 template. */
    public static function manages(PhpHosting $project): bool
    {
        return is_dir($project->project()->projectDirPath() . '/' . self::SERVICES_DIR);
    }

    public function bootScripts(array $services): array
    {
        return [];
    }

    /**
     * Each service gets a run and a finish file. The template's own services
     * are left alone; the runner's files, if the account still has them, go.
     */
    public function write(array $services): void
    {
        $system = $this->project->system();
        $projectDir = $this->project->project()->projectDirPath();
        $dir = $projectDir . '/' . self::SERVICES_DIR;
        $templateDir = $system->projectFilesTemplateDirPath($this->project->userModel()->getTemplate()) . '/' . self::SERVICES_DIR;

        $wanted = [];
        foreach ($services as $service) {
            $name = self::name($service->name);
            $wanted[] = $name;
            $system->filesystem()->filePutContents("{$dir}/{$name}/run", self::RUN_PREFIX . "{$service->command}\n", null, '755');
            $system->filesystem()->filePutContents("{$dir}/{$name}/finish", self::FINISH, null, '755');
        }
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            $name = basename($path);
            if (!in_array($name, $wanted, true) && !is_dir("{$templateDir}/{$name}")) {
                $system->exec(['sudo', 'rm', '-rf', $path]);
            }
        }

        RunnerServiceManager::removeLayout($this->project);
    }

    public function sync(): void
    {
        $this->project->system()->runProcess($this->project->execArgv(['sh', '-c', self::syncScript()]));
    }

    public function reload(string $service): void
    {
        $this->project->system()->exec($this->project->execArgv(['s6-svc', '-r', self::SCAN_DIR . '/' . self::name($service)]));
    }

    public function restartArgv(string $service): array
    {
        return ['sh', '-c', self::restartScriptFor($service)];
    }

    /** s6 starts nothing it does not supervise, so there is no stray copy to stop. */
    public function restartScript(string $service, string $mainProcess): string
    {
        return self::restartScriptFor($service);
    }

    /**
     * Inside the account: bring the scan dir in line with services/. A new
     * service is started; one that is gone is stopped and retired (hidden
     * first, as s6-svscan ignores dot-names, then removed by a later sync once
     * its supervisor has let go). A changed run file takes effect at the
     * service's next restart, as with the runner's `sync`.
     */
    public static function syncScript(): string
    {
        $src = self::SOURCE_DIR;
        $scan = self::SCAN_DIR;

        return <<<SH
            set -e
            for d in {$src}/*/; do
              [ -d "\$d" ] || continue
              n=\$(basename "\$d")
              mkdir -p {$scan}/\$n
              cp -a "\$d." {$scan}/\$n/
            done
            for d in {$scan}/*/; do
              [ -d "\$d" ] || continue
              n=\$(basename "\$d")
              [ -d {$src}/\$n ] && continue
              s6-svc -wd -T 10000 -d "\$d" || s6-svc -k "\$d" || true
              mv "\$d" {$scan}/.retired-\$n-\$\$
            done
            s6-svscanctl -an {$scan}
            for d in {$src}/*/; do
              [ -d "\$d" ] || continue
              n=\$(basename "\$d")
              i=0; until s6-svok {$scan}/\$n || [ \$i -ge 50 ]; do sleep 0.1; i=\$((i+1)); done
              s6-svc -u {$scan}/\$n
            done
            for d in {$scan}/.retired-*/; do
              [ -d "\$d" ] && ! s6-svok "\$d" && rm -rf "\$d"
            done
            true
            SH;
    }

    /**
     * Inside the account: restart $service with its current run file, or
     * start it if it is not running yet. Exits EXIT_NOT_MANAGED when services/
     * has no such service. Killed if it has not stopped after 10s, as the
     * runner did.
     */
    public static function restartScriptFor(string $service): string
    {
        $name = self::name($service);
        $src = self::SOURCE_DIR . '/' . $name;
        $dst = self::SCAN_DIR . '/' . $name;
        $scan = self::SCAN_DIR;
        $notManaged = self::EXIT_NOT_MANAGED;

        return <<<SH
            [ -f {$src}/run ] || exit {$notManaged}
            set -e
            mkdir -p {$dst}
            cp -a {$src}/. {$dst}/
            s6-svscanctl -a {$scan}
            i=0; until s6-svok {$dst} || [ \$i -ge 50 ]; do sleep 0.1; i=\$((i+1)); done
            s6-svc -wd -T 10000 -d {$dst} || { s6-svc -k {$dst}; s6-svc -wd -T 5000 -d {$dst}; }
            s6-svc -wu -T 10000 -u {$dst}
            SH;
    }

    /** Service names go into paths and shell text unquoted. */
    private static function name(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Not a service name: {$name}");
        }

        return $name;
    }
}
