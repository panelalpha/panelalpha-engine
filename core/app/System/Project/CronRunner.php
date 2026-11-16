<?php

namespace App\System\Project;

use App\System\Privileged;
use App\System\Project as UserProject;

/**
 * A classic account's cron has no mail transport it can reach: a job's line calls this script, which runs the command
 * and appends its output, with the time and the exit status, to the log a container project's cron writes too.
 */
final class CronRunner
{
    public const RUNNER_FILE = 'cron-run';

    public const RUNNER = '/entrypoint-init.d/'.self::RUNNER_FILE;

    /** The directory in the account user's home the runs are logged in, as a container project's cron logs them too. */
    public const LOG_DIR = '.panelalpha/cron';

    public const LOG = self::LOG_DIR.'/cron.log';

    /** The command, base64 on the line, has to fit cron's 1000-character command with the runner in front. */
    public const MAX_COMMAND_BYTES = 640;

    private const MAX_LOG_BYTES = 1048576;

    /**
     * $1 the command in base64 (out of reach of cron's `%`): one run of a command at a time, the log rolled to cron.log.1
     * past MAX_LOG_BYTES, the command run as cron itself would run it and everything it printed appended to the log.
     * Every note names the command, since the jobs of one project land in one log and may run together.
     */
    public const RUNNER_SCRIPT = <<<'SH'
        set -u
        encoded=$1
        command=$(printf '%s' "$encoded" | base64 -d 2>/dev/null) || exit 0
        log=__LOG__
        dir=${log%/*}
        mkdir -p "$dir" || exit 0
        note() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" >> "$log"; }
        [ "$(stat -c %s "$log" 2>/dev/null || echo 0)" -lt __MAX_LOG_BYTES__ ] || mv -f "$log" "$log.1"
        exec 9> "$dir/$(printf '%s' "$encoded" | md5sum | cut -c1-32).lock"
        flock -n 9 || { note "skipped: the previous run has not finished: $command"; exit 0; }
        note "start: $command"
        sh -c "$command" >> "$log" 2>&1
        note "exit $?: $command"
        SH;

    public function __construct(
        private readonly UserProject $project,
    ) {}

    /** The crontab's command for a job that runs $command. */
    public static function command(string $command): string
    {
        return '/bin/sh '.self::RUNNER.' '.base64_encode($command);
    }

    /** Whether cron reads the whole of {@see command()} for $command. */
    public static function fits(string $command): bool
    {
        return strlen($command) <= self::MAX_COMMAND_BYTES;
    }

    /** The command of a line {@see command()} wrote, or null for any other command. */
    public static function parse(string $stored): ?string
    {
        $pattern = '#^/bin/sh '.preg_quote(self::RUNNER, '#').' ([A-Za-z0-9+/]+={0,2})$#';
        if (preg_match($pattern, $stored, $match) !== 1) {
            return null;
        }
        $command = base64_decode($match[1], true);

        return $command === false ? null : $command;
    }

    public function runnerScript(): string
    {
        return strtr(self::RUNNER_SCRIPT, [
            '__LOG__' => $this->logPath(),
            '__MAX_LOG_BYTES__' => (string) self::MAX_LOG_BYTES,
        ]);
    }

    /** Puts the runner where the account's container mounts it and hands the log's directory to the account user. */
    public function install(): void
    {
        $this->project->system()->filesystem()->writeFileReplacingPath(
            $this->project->projectDirPath().'/entrypoint-init.d/'.self::RUNNER_FILE,
            $this->runnerScript(),
            null,
            '644',
        );
        $this->handLogDirectoryToTheAccount();
    }

    public function logPath(): string
    {
        return $this->project->homeDirPath().'/'.self::LOG;
    }

    /** The home is root's, so the account cannot make the directory its log goes in; the runner makes the log inside it. */
    private function handLogDirectoryToTheAccount(): void
    {
        $system = $this->project->system();
        $dir = dirname($this->logPath());
        $system->exec(Privileged::files()->makeDirectories($dir));

        $owner = $this->project->userModel()->getChownString();
        if ($owner !== null) {
            $system->exec(Privileged::files()->changeOwner($owner, $dir));
        }
        $system->exec(Privileged::files()->changeMode('755', $dir));
    }
}
