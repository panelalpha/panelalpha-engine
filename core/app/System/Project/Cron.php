<?php

namespace App\System\Project;

use App\Exceptions\DockerErrorException;
use App\Exceptions\ProblemException;
use App\System\Privileged;
use App\System\Project as UserProject;
use App\System\Project\Dind\AccountCron;
use Dsc\Cron\Crontab;
use Dsc\Cron\Job;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Cron
{
    /** cron answers a job's output to the account's bare name, which the MTA refuses: the mail is off and the log has it. */
    private const MAIL_OFF = 'MAILTO=""';

    public function __construct(
        private readonly UserProject $project,
    ) {}

    public function crontabPath(): string
    {
        return $this->project->projectDirPath().'/crontabs/www-data';
    }

    /**
     * @throws DockerErrorException
     */
    public function ensureCrontabFile(?string $chown = null): void
    {
        $file = $this->crontabPath();
        $system = $this->project->system();
        if (! is_file($file)) {
            $system->exec(Privileged::files()->touch($file));
        }
        $chown = $chown ?? '33:33';
        $system->exec(Privileged::files()->changeOwner($chown, $file));
        $system->exec(Privileged::files()->changeMode('600', $file));
    }

    /**
     * @return array<array{
     *   hash: string,
     *   command: string,
     *   service: ?string,
     *   minute: string,
     *   hour: string,
     *   day_of_month: string,
     *   month: string,
     *   day_of_week: string
     * }>
     */
    public function list(): array
    {
        if (! $this->hasCrontab()) {
            return [];
        }
        $crontab = $this->loadCrontab();

        $jobs = [];
        foreach ($crontab->getJobs() as $job) {
            $jobs[] = $this->jobToArray($job);
        }

        return $jobs;
    }

    /** The crontab as written, empty before the deploy that creates it. */
    public function contents(): string
    {
        return $this->hasCrontab() ? $this->project->system()->filesystem()->fileGetContents($this->crontabPath()) : '';
    }

    public function exists(string $hash): bool
    {
        foreach ($this->list() as $job) {
            if ($job['hash'] === $hash) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{
     *   command: string,
     *   service?: string,
     *   minute: string,
     *   hour: string,
     *   day_of_month: string,
     *   month: string,
     *   day_of_week: string
     * } $params
     * @return array{
     *   hash: string,
     *   command: string,
     *   service: ?string,
     *   minute: string,
     *   hour: string,
     *   day_of_month: string,
     *   month: string,
     *   day_of_week: string
     * }
     */
    public function create(array $params): array
    {
        $this->ensureWritableCrontab();
        $this->assertServiceExists($params);
        $crontab = $this->loadCrontab();

        $job = new Job;
        $job->setCommand($this->commandLine($params));
        $job->setMinute($params['minute']);
        $job->setHour($params['hour']);
        $job->setDayOfMonth($params['day_of_month']);
        $job->setMonth($params['month']);
        $job->setDayOfWeek($params['day_of_week']);
        $job->setActive();

        $crontab->addJob($job);

        $this->persistCrontab($crontab);

        return $this->jobToArray($job);
    }

    /**
     * @return array{
     *   hash: string,
     *   command: string,
     *   service: ?string,
     *   minute: string,
     *   hour: string,
     *   day_of_month: string,
     *   month: string,
     *   day_of_week: string
     * }
     *
     * @throws Exception
     */
    public function delete(string $hash): array
    {
        $crontab = $this->loadCrontab();

        /** @var Job $job */
        $job = $crontab->getJobByHash($hash);
        $crontab->removeJob($job);

        $this->persistCrontab($crontab);

        return $this->jobToArray($job);
    }

    /**
     * @param array{
     *   command: string,
     *   service?: string,
     *   minute: string,
     *   hour: string,
     *   day_of_month: string,
     *   month: string,
     *   day_of_week: string
     * } $params
     * @return array{
     *   hash: string,
     *   command: string,
     *   service: ?string,
     *   minute: string,
     *   hour: string,
     *   day_of_month: string,
     *   month: string,
     *   day_of_week: string
     * }
     *
     * @throws Exception
     */
    public function update(string $hash, array $params): array
    {
        $crontab = $this->loadCrontab();

        $this->assertServiceExists($params);

        /** @var Job $job */
        $job = $crontab->getJobByHash($hash);

        $job->setCommand($this->commandLine($params));
        $job->setMinute($params['minute']);
        $job->setHour($params['hour']);
        $job->setDayOfMonth($params['day_of_month']);
        $job->setMonth($params['month']);
        $job->setDayOfWeek($params['day_of_week']);
        $job->setActive();

        $this->persistCrontab($crontab);

        return $this->jobToArray($job);
    }

    /**
     * A job in a service names it on the line with the command in base64; a classic account's job calls the runner that
     * logs it the same way, and any other command is written as cron reads it.
     *
     * @param  array{command: string, service?: string}  $params
     */
    private function commandLine(array $params): string
    {
        if (isset($params['service'])) {
            return AccountCron::command($params['service'], $params['command']);
        }

        return $this->logsToFile()
            ? CronRunner::command($params['command'])
            : self::storedCommand($params['command']);
    }

    /** cron ends a command at its first unescaped `%`, so every one is escaped on the way in. */
    private static function storedCommand(string $command): string
    {
        return str_replace('%', '\\%', $command);
    }

    private static function listedCommand(string $stored): string
    {
        return str_replace('\\%', '%', $stored);
    }

    /**
     * @param  array{service?: string}  $params
     *
     * @throws ProblemException
     */
    private function assertServiceExists(array $params): void
    {
        $runtime = $this->project->runtime();
        if (isset($params['service']) && $runtime instanceof Dind) {
            $runtime->assertServiceExists($params['service']);
        }
    }

    /** The deploy that renders the account writes the crontab, so none before it means no jobs. */
    private function hasCrontab(): bool
    {
        return is_file($this->crontabPath());
    }

    /**
     * Before the first deploy there is no account to load a job into, and that
     * deploy creates the crontab; after it, a lost crontab is created as setup does.
     *
     * @throws ProblemException
     * @throws DockerErrorException
     */
    private function ensureWritableCrontab(): void
    {
        if ($this->hasCrontab()) {
            return;
        }
        if (! $this->project->exists()) {
            throw ProblemException::conflict(
                'project',
                'project_not_deployed',
                "Project '{$this->project->username()}' has not been deployed yet, and its first deploy creates the crontab. "
                    .'Add the cron job once that deploy has finished.',
            );
        }
        $this->ensureCrontabFile($this->project->model()->getChownString());
    }

    private function loadCrontab(): Crontab
    {
        $crontab = new Crontab;
        $contents = $this->project->system()->filesystem()->fileGetContents($this->crontabPath());
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || self::isSetting($line)) {
                continue;
            }
            $crontab->addJob(self::parseLine($line));
        }

        return $crontab;
    }

    /** `MAILTO=""` and the like are the crontab's settings, not one of its jobs. */
    private static function isSetting(string $line): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*=/', $line) === 1;
    }

    /**
     * Five schedule fields, then the command as written: Job::parse() cuts `>>`, `2>>` and `#` out, so a job read back
     * differed (shorter, new hash, `2>&1` re-added per write); `@daily` and the like, never API-written, are left to it.
     */
    private static function parseLine(string $line): Job
    {
        $parts = preg_split('/\s+/', trim($line), 6);
        if ($parts === false || count($parts) < 6 || str_starts_with($parts[0], '@')) {
            return Job::parse($line);
        }

        $job = new Job;
        $job->setMinute($parts[0]);
        $job->setHour($parts[1]);
        $job->setDayOfMonth($parts[2]);
        $job->setMonth($parts[3]);
        $job->setDayOfWeek($parts[4]);
        $job->setCommand($parts[5]);
        $job->setActive();

        return $job;
    }

    /** Write the crontab as this engine writes it now, so a project rendered again logs the jobs it already had. */
    public function rewrite(): void
    {
        if (! $this->hasCrontab()) {
            return;
        }
        $this->persistCrontab($this->loadCrontab());
    }

    private function persistCrontab(Crontab $crontab): void
    {
        $this->project->system()->filesystem()->filePutContents(
            $this->crontabPath(),
            $this->rendered($crontab),
        );
    }

    /** A classic account's jobs go through the runner that logs them, under the line that keeps cron from mailing them. */
    private function rendered(Crontab $crontab): string
    {
        if (! $this->logsToFile()) {
            return $crontab->render().PHP_EOL;
        }

        (new CronRunner($this->project))->install();
        foreach ($crontab->getJobs() as $job) {
            if (AccountCron::parse($job->getCommand()) === null) {
                $this->logThroughTheRunner($job);
            }
        }

        return self::MAIL_OFF.PHP_EOL.$crontab->render().PHP_EOL;
    }

    /**
     * A job written before this engine version gets the runner form too, unless its command is too long for cron to
     * read it in base64: that one keeps the line it ran on, unlogged, and the write says so.
     */
    private function logThroughTheRunner(Job $job): void
    {
        $stored = $job->getCommand();
        $command = CronRunner::parse($stored) ?? self::listedCommand($stored);
        if (CronRunner::fits($command)) {
            $job->setCommand(CronRunner::command($command));

            return;
        }
        Log::warning(
            "{$this->project->username()}: a cron job longer than ".CronRunner::MAX_COMMAND_BYTES
                .' bytes keeps its own line and its runs are not logged: '.Str::limit($command, 80)
        );
    }

    /** A classic account's cron has no mail transport its jobs could reach, so their output goes to the runner's log. */
    private function logsToFile(): bool
    {
        return ! $this->project->runsInDind();
    }

    /**
     * @return array{
     *   hash: string,
     *   command: string,
     *   service: ?string,
     *   minute: string,
     *   hour: string,
     *   day_of_month: string,
     *   month: string,
     *   day_of_week: string
     * }
     */
    private function jobToArray(Job $job): array
    {
        $stored = $job->getCommand();
        $inService = AccountCron::parse($stored);
        $logged = $inService === null ? CronRunner::parse($stored) : null;

        return [
            'hash' => $job->getHash(),
            'command' => $inService['command'] ?? $logged ?? self::listedCommand($stored),
            'service' => $inService['service'] ?? null,
            'minute' => $job->getMinute(),
            'hour' => $job->getHour(),
            'day_of_month' => $job->getDayOfMonth(),
            'month' => $job->getMonth(),
            'day_of_week' => $job->getDayOfWeek(),
        ];
    }
}
