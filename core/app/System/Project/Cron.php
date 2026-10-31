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

class Cron
{
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
        $job->setCommand(self::commandLine($params));
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

        $job->setCommand(self::commandLine($params));
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
     * A job in a service names it on the line with the command in base64;
     * any other command is written as cron reads it.
     *
     * @param  array{command: string, service?: string}  $params
     */
    private static function commandLine(array $params): string
    {
        return isset($params['service'])
            ? AccountCron::command($params['service'], $params['command'])
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
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $crontab->addJob(self::parseLine($line));
        }

        return $crontab;
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

    private function persistCrontab(Crontab $crontab): void
    {
        $this->project->system()->filesystem()->filePutContents(
            $this->crontabPath(),
            $crontab->render().PHP_EOL,
        );
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
        $inService = AccountCron::parse($job->getCommand());

        return [
            'hash' => $job->getHash(),
            'command' => $inService['command'] ?? self::listedCommand($job->getCommand()),
            'service' => $inService['service'] ?? null,
            'minute' => $job->getMinute(),
            'hour' => $job->getHour(),
            'day_of_month' => $job->getDayOfMonth(),
            'month' => $job->getMonth(),
            'day_of_week' => $job->getDayOfWeek(),
        ];
    }
}
