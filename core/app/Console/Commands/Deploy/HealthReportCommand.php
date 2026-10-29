<?php

namespace App\Console\Commands\Deploy;

use App\Console\Kernel;
use App\Lib\Deploy\Health\CheckRunner;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Models\User;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Ask every deployed application whether it is still serving itself, and
 * report the ones that are not.
 *
 * A deploy report only describes the moment an install finished; this sweep is
 * what notices a site that stopped working afterwards (a lost database, a lapsed
 * certificate, a deleted index page).
 *
 * Runs every six hours from {@see Kernel}: the probe is a
 * `docker exec` per account and none of these failures is minute-sensitive.
 *
 * Sends nothing when telemetry is off, because such an install has said it does
 * not report; `--local` still runs the sweep and records the verdicts on the accounts.
 */
class HealthReportCommand extends Command
{
    /**
     * One brief probe: these applications have been up for hours, so unlike a
     * deploy's boot budget, a port that does not answer twice is down.
     */
    private const SWEEP_TIMEOUT = 5;

    private const SWEEP_ATTEMPTS = 2;

    private const SWEEP_DELAY = 2;

    protected $signature = 'project:health:report
        {project? : Sweep one project instead of all of them}
        {--timeout=5 : Seconds to wait for each response}
        {--attempts=2 : Probes per port before calling it down}
        {--limit=0 : Stop after this many projects}
        {--local : Run and record the verdicts without sending anything}
        {--json : Print the raw findings instead of a table}';

    protected $description = 'Probe every deployed application and report the ones not serving themselves';

    public function handle(): int
    {
        $local = (bool) $this->option('local');

        if (! $local && ! Telemetry::enabled()) {
            $this->line('Telemetry is disabled, so there is nowhere to report. Use --local to record verdicts anyway.');

            return self::SUCCESS;
        }

        $projects = $this->projects();
        if ($projects === null) {
            return self::FAILURE;
        }

        $this->present($this->sweep($projects, $local), $local);

        return self::SUCCESS;
    }

    /**
     * A failed check, or ports that gave no answer at all: silence nothing
     * could explain is not "fine" either.
     *
     * @param  array<string, mixed>  $details
     */
    public static function notServing(array $details): bool
    {
        return AppHealth::failedChecks($details) !== [] || AppHealth::nothingAnswered($details);
    }

    /**
     * Probe every project up to --limit and report the ones not serving themselves.
     *
     * @param  iterable<User>  $projects
     * @return array{swept: int, degraded: int, reported: int, projects: list<array<string, string>>}
     */
    private function sweep(iterable $projects, bool $local): array
    {
        $limit = max(0, (int) $this->option('limit'));
        $sweep = ['swept' => 0, 'degraded' => 0, 'reported' => 0, 'projects' => []];

        foreach ($projects as $user) {
            if ($limit > 0 && $sweep['swept'] >= $limit) {
                break;
            }

            $project = $this->projectFor($user);
            if ($project === null) {
                continue;
            }

            $sweep['swept']++;

            $row = $this->degradedRow($user, $project);
            if ($row === null) {
                continue;
            }

            $sweep['degraded']++;
            $sweep['projects'][] = $row;

            if (! $local) {
                Telemetry::captureHealth($user->username, $this->currentDetails($user));
                $sweep['reported']++;
            }
        }

        return $sweep;
    }

    /**
     * The table row for a project that is not serving itself, or null when it
     * is serving or could not be asked.
     *
     * @return array{project: string, serving: string, checks: string}|null
     */
    private function degradedRow(User $user, Dind $project): ?array
    {
        $report = $this->probe($user, $project);
        if ($report === null) {
            return null;
        }

        $details = $this->currentDetails($user);
        if (! self::notServing($details)) {
            return null;
        }

        return [
            'project' => $user->username,
            'serving' => (string) ($report['serving'] ?? CheckRunner::SERVING_UNKNOWN),
            'checks' => self::describeFailures(AppHealth::failedChecks($details)),
        ];
    }

    /**
     * Observe one project's health. A probe that throws is logged and skipped,
     * so one wedged container never stops the sweep of the projects behind it.
     *
     * @return array<string, mixed>|null
     */
    private function probe(User $user, Dind $project): ?array
    {
        $timeout = max(1, (int) ($this->option('timeout') ?: self::SWEEP_TIMEOUT));
        $attempts = max(1, (int) ($this->option('attempts') ?: self::SWEEP_ATTEMPTS));

        try {
            return $project->appHealth()->observe($timeout, $attempts, self::SWEEP_DELAY);
        } catch (\Throwable $e) {
            Log::debug("Health sweep skipped {$user->username}: ".$e->getMessage());

            return null;
        }
    }

    /**
     * The account's details as the probe just recorded them.
     *
     * @return array<string, mixed>
     */
    private function currentDetails(User $user): array
    {
        return $user->fresh()?->getDetails() ?? $user->getDetails();
    }

    /**
     * @param  list<array{id: string, severity: string}>  $failures
     */
    private static function describeFailures(array $failures): string
    {
        if ($failures === []) {
            return 'nothing answered';
        }

        return implode(', ', array_map(
            static fn (array $f): string => $f['id'].' ('.$f['severity'].')',
            $failures
        ));
    }

    /**
     * @param  array{swept: int, degraded: int, reported: int, projects: list<array<string, string>>}  $sweep
     */
    private function present(array $sweep, bool $local): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($sweep, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        if ($sweep['projects'] !== []) {
            $this->table(['project', 'serving', 'failed checks'], $sweep['projects']);
        }

        $sent = $local ? 'not sent (--local)' : "{$sweep['reported']} reported";
        $this->info("Swept {$sweep['swept']} project(s): {$sweep['degraded']} not serving themselves, {$sent}.");
    }

    /**
     * Every project to sweep, or null when the one named does not exist. The
     * error goes to stderr so a --json caller still gets parseable stdout.
     *
     * @return iterable<User>|null
     */
    private function projects(): ?iterable
    {
        $one = $this->argument('project');
        if (! is_string($one) || $one === '') {
            return $this->allProjects();
        }

        $user = User::findByUsername($one);
        if (! $user) {
            $this->getOutput()->getErrorStyle()->error("No such project: '{$one}'");

            return null;
        }

        return [$user];
    }

    /**
     * Paged rather than a cursor: a cursor pins SQLite's read snapshot for the
     * whole sweep, and later writes then fail with SQLITE_BUSY_SNAPSHOT.
     *
     * @return iterable<User>
     */
    private function allProjects(): iterable
    {
        return User::query()->lazyById();
    }

    /**
     * The DinD runtime behind an account, or null when there is nothing
     * deployed to ask. Suspended accounts and apps stopped through the API are
     * skipped on purpose: a stopped site is not a fault.
     */
    private function projectFor(User $user): ?Dind
    {
        if ($user->getTemplate() !== 'dind' || $user->status === 'suspended' || $user->isAppStoppedByRequest()) {
            return null;
        }

        try {
            $runtime = $user->project()->runtime();
        } catch (\Throwable $e) {
            return null;
        }

        return $runtime instanceof Dind ? $runtime : null;
    }
}
