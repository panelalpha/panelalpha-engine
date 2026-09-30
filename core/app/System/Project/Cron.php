<?php

namespace App\System\Project;

use App\Exceptions\DockerErrorException;
use App\System\Project as UserProject;
use Dsc\Cron\Crontab;
use Dsc\Cron\Job;
use Exception;

class Cron
{
    public function __construct(
        private readonly UserProject $project,
    ) {
    }

    public function crontabPath(): string
    {
        return $this->project->projectDirPath() . '/crontabs/www-data';
    }

    /**
     * @throws DockerErrorException
     */
    public function ensureCrontabFile(?string $chown = null): void
    {
        $file = $this->crontabPath();
        $system = $this->project->system();
        if (!is_file($file)) {
            $system->exec(['sudo', 'touch', $file]);
        }
        $chown = $chown ?? '33:33';
        $system->exec(['sudo', 'chown', $chown, $file]);
        $system->exec(['sudo', 'chmod', '600', $file]);
    }

    /**
     * @return array<array{
     *   hash: string,
     *   command: string,
     *   minute: string,
     *   hour: string,
     *   day_of_month: string,
     *   month: string,
     *   day_of_week: string
     * }>
     */
    public function list(): array
    {
        $crontab = $this->loadCrontab();

        $jobs = [];
        foreach ($crontab->getJobs() as $job) {
            $jobs[] = $this->jobToArray($job);
        }

        return $jobs;
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
     *   minute: string,
     *   hour: string,
     *   day_of_month: string,
     *   month: string,
     *   day_of_week: string
     * } $params
     *
     * @return array{
     *   hash: string,
     *   command: string,
     *   minute: string,
     *   hour: string,
     *   day_of_month: string,
     *   month: string,
     *   day_of_week: string
     * }
     */
    public function create(array $params): array
    {
        $crontab = $this->loadCrontab();

        $job = new Job();
        $job->setCommand($params['command']);
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
     *   minute: string,
     *   hour: string,
     *   day_of_month: string,
     *   month: string,
     *   day_of_week: string
     * }
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
     *   minute: string,
     *   hour: string,
     *   day_of_month: string,
     *   month: string,
     *   day_of_week: string
     * } $params
     *
     * @return array{
     *   hash: string,
     *   command: string,
     *   minute: string,
     *   hour: string,
     *   day_of_month: string,
     *   month: string,
     *   day_of_week: string
     * }
     * @throws Exception
     */
    public function update(string $hash, array $params): array
    {
        $crontab = $this->loadCrontab();

        /** @var Job $job */
        $job = $crontab->getJobByHash($hash);

        $job->setCommand($params['command']);
        $job->setMinute($params['minute']);
        $job->setHour($params['hour']);
        $job->setDayOfMonth($params['day_of_month']);
        $job->setMonth($params['month']);
        $job->setDayOfWeek($params['day_of_week']);
        $job->setActive();

        $this->persistCrontab($crontab);

        return $this->jobToArray($job);
    }

    private function loadCrontab(): Crontab
    {
        $crontab = new Crontab();
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
     * Five schedule fields, then the command exactly as written. Job::parse()
     * cuts `>>`, `2>>` and `#` out of the command, so a job read back was not
     * the job created: shorter command, different hash, and `2>&1` appended
     * again on every write.
     */
    private static function parseLine(string $line): Job
    {
        $parts = preg_split('/\s+/', trim($line), 6);
        // `@daily` and the like: never written by the API, left to the library.
        if ($parts === false || count($parts) < 6 || str_starts_with($parts[0], '@')) {
            return Job::parse($line);
        }

        $job = new Job();
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
            $crontab->render() . PHP_EOL,
        );
    }

    /**
     * @return array{
     *   hash: string,
     *   command: string,
     *   minute: string,
     *   hour: string,
     *   day_of_month: string,
     *   month: string,
     *   day_of_week: string
     * }
     */
    private function jobToArray(Job $job): array
    {
        return [
            'hash' => $job->getHash(),
            'command' => $job->getCommand(),
            'minute' => $job->getMinute(),
            'hour' => $job->getHour(),
            'day_of_month' => $job->getDayOfMonth(),
            'month' => $job->getMonth(),
            'day_of_week' => $job->getDayOfWeek(),
        ];
    }
}
