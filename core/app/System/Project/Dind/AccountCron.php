<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind as DindProject;

/**
 * The account's cron calls {@see RUNNER}, which execs each job in its service's running container; {@see INSTALL_FILE}
 * puts the crontab from entrypoint.d into cron's spool at boot and on every change.
 */
final class AccountCron
{
    public const INSTALL_FILE = 'crontab.sh';

    public const RUNNER_FILE = 'cron-in-service';

    public const CRONTAB_FILE = 'crontab';

    public const RUNNER = '/entrypoint.d/'.self::RUNNER_FILE;

    public const SPOOL_DIR = '/var/spool/cron/crontabs';

    /** The command, base64 on the line, has to fit cron's 1000-character command with the runner in front. */
    public const MAX_COMMAND_BYTES = 640;

    /** Where the runner writes each run's output, in the account user's home. */
    public const LOG = '.panelalpha/cron/cron.log';

    private const MAX_LOG_BYTES = 1048576;

    /**
     * $1 the service, $2 the command in base64 (out of reach of cron's `%`): one run of a job at a time, a stopped
     * service skipped and logged, the log rolled to cron.log.1 past MAX_LOG_BYTES.
     */
    public const RUNNER_SCRIPT = <<<'SH'
        set -u
        service=$1; encoded=$2
        command=$(printf '%s' "$encoded" | base64 -d 2>/dev/null) || exit 0
        log=$HOME/__LOG__
        dir=${log%/*}
        mkdir -p "$dir" || exit 0
        note() { printf '%s [%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$service" "$1" >> "$log"; }
        [ "$(stat -c %s "$log" 2>/dev/null || echo 0)" -lt __MAX_LOG_BYTES__ ] || mv -f "$log" "$log.1"
        exec 9> "$dir/$(printf '%s %s' "$service" "$encoded" | md5sum | cut -c1-32).lock"
        flock -n 9 || { note 'skipped: the previous run has not finished'; exit 0; }
        id=$(docker ps -q --filter "label=com.docker.compose.service=$service" 2>/dev/null | head -n 1)
        [ -n "$id" ] || { note 'skipped: the service is not running'; exit 0; }
        note "start: $command"
        docker exec "$id" sh -c "$command" >> "$log" 2>&1
        note "exit $?"
        SH;

    public function __construct(
        private DindProject $dind,
    ) {}

    /** The crontab's command for a job that runs $command in $service. */
    public static function command(string $service, string $command): string
    {
        return '/bin/sh '.self::RUNNER.' '.$service.' '.base64_encode($command);
    }

    /**
     * The service and command of a line {@see command()} wrote, or null for any other command.
     *
     * @return array{service: string, command: string}|null
     */
    public static function parse(string $stored): ?array
    {
        $pattern = '#^/bin/sh '.preg_quote(self::RUNNER, '#').' (\S+) ([A-Za-z0-9+/]+={0,2})$#';
        if (preg_match($pattern, $stored, $match) !== 1) {
            return null;
        }
        $command = base64_decode($match[2], true);

        return $command === false ? null : ['service' => $match[1], 'command' => $command];
    }

    /** The install script and the runner, into entrypoint.d, readable by the account user cron runs each job as. */
    public function writeScripts(): void
    {
        $fs = $this->dind->system()->filesystem();
        $fs->writeFileReplacingPath($this->entrypointPath(self::INSTALL_FILE), $this->installScript());
        $fs->writeFileReplacingPath($this->entrypointPath(self::RUNNER_FILE), self::runnerScript(), null, '644');
    }

    /**
     * Hands $crontab to the account with the scripts and installs it into the spool when the account is running; a
     * stopped one installs it at boot.
     */
    public function deliver(string $crontab): void
    {
        $this->dind->system()->filesystem()->writeFileReplacingPath($this->entrypointPath(self::CRONTAB_FILE), $crontab);
        $this->writeScripts();
        if ($this->dind->isRunning()) {
            $this->dind->shell()->exec(['sh', '/entrypoint.d/'.self::INSTALL_FILE]);
        }
    }

    /**
     * Never fails, as entrypoint.sh stops the boot on the first script that does; no jobs leaves no spool file, and
     * cron rereads the spool once its mtime moves.
     */
    public function installScript(): string
    {
        [$uid, $gid] = $this->dind->userModel()->linuxIds();
        $username = $this->dind->userModel()->username;
        $source = '/entrypoint.d/'.self::CRONTAB_FILE;
        $spool = self::SPOOL_DIR;

        return <<<BASH
if grep -q '[^[:space:]]' {$source} 2>/dev/null; then
  install -o {$uid} -g {$gid} -m 0600 {$source} {$spool}/{$username} || echo "[init] could not install the crontab of {$username}"
else
  rm -f {$spool}/{$username}
fi
touch {$spool} 2>/dev/null || true
BASH;
    }

    public static function runnerScript(): string
    {
        return strtr(self::RUNNER_SCRIPT, [
            '__LOG__' => self::LOG,
            '__MAX_LOG_BYTES__' => (string) self::MAX_LOG_BYTES,
        ]);
    }

    private function entrypointPath(string $name): string
    {
        return $this->dind->projectDirPath().'/entrypoint.d/'.$name;
    }
}
