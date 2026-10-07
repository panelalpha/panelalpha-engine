<?php

namespace App\Console\Commands\Deploy;

use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use App\Lib\Deploy\Health\CheckRunner;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Ask every deployed application whether it is still serving itself, and
 * report the ones that are not.
 *
 * A deploy report is a photograph of the moment an install finished. This is
 * the only thing that ever says the site stopped working afterwards — the
 * database that went away, the certificate that lapsed, the index page
 * somebody deleted over SFTP — and none of that changes a deploy that already
 * succeeded. Until this ran, an application could be serving the engine's own
 * placeholder for a month and the only signal anywhere was a support ticket.
 *
 * Runs every six hours from {@see \App\Console\Kernel}. Six rather than one:
 * the probe is a `docker exec` per account, none of these failures is
 * minute-sensitive, and a sweep that costs a container round trip per project
 * per hour would be paid for by every customer on the host.
 *
 * Sends nothing when telemetry is off. That is not a courtesy — an install
 * with telemetry disabled has said it does not report, and running the sweep
 * anyway to write reports nothing will ever ship would spend every account's
 * CPU on a spool that only grows. The verdict is still worth having locally,
 * so `--local` runs the sweep and records it on the accounts without sending.
 */
class HealthReportCommand extends Command
{
    /**
     * One probe, briefly.
     *
     * The deploy's budget is fifteen attempts over a minute, because
     * `compose up` returning is not an application serving and a slow first
     * boot must not be called broken. Nothing here is booting: these
     * applications have been up for hours, so a port that does not answer
     * twice in a row is a port that is not answering.
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

        if (!$local && !Telemetry::enabled()) {
            $this->line('Telemetry is disabled, so there is nowhere to report. Use --local to record verdicts anyway.');

            return self::SUCCESS;
        }

        $timeout = max(1, (int) ($this->option('timeout') ?: self::SWEEP_TIMEOUT));
        $attempts = max(1, (int) ($this->option('attempts') ?: self::SWEEP_ATTEMPTS));
        $limit = max(0, (int) $this->option('limit'));

        $swept = 0;
        $degraded = 0;
        $reported = 0;
        $rows = [];

        foreach ($this->projects() as $user) {
            if ($limit > 0 && $swept >= $limit) {
                break;
            }

            $project = $this->projectFor($user);
            if ($project === null) {
                continue;
            }

            $swept++;

            // One account's wedged container is not the next account's. A
            // sweep that stops at the first failure is a sweep that reports
            // nothing about the ninety projects behind it.
            try {
                $report = $project->appHealth()->observe($timeout, $attempts, self::SWEEP_DELAY);
            } catch (\Throwable $e) {
                Log::debug("Health sweep skipped {$user->username}: " . $e->getMessage());
                continue;
            }

            if ($report === null) {
                continue;
            }

            $details = $user->fresh()?->getDetails() ?? $user->getDetails();
            if (!self::notServing($details)) {
                continue;
            }
            $failures = AppHealth::failedChecks($details);

            $degraded++;
            $rows[] = [
                'project' => $user->username,
                'serving' => (string) ($report['serving'] ?? CheckRunner::SERVING_UNKNOWN),
                'checks' => $failures === [] ? 'nothing answered' : implode(', ', array_map(
                    static fn (array $f): string => $f['id'] . ' (' . $f['severity'] . ')',
                    $failures
                )),
            ];

            if (!$local) {
                Telemetry::captureHealth($user->username, $user->fresh()?->getDetails() ?? $user->getDetails());
                $reported++;
            }
        }

        $this->present($rows, $swept, $degraded, $reported, $local);

        return self::SUCCESS;
    }

    /**
     * A failed check, or ports that gave no answer at all: silence nothing
     * could explain is not "fine" either.
     *
     * @param array<string, mixed> $details
     */
    public static function notServing(array $details): bool
    {
        return AppHealth::failedChecks($details) !== [] || AppHealth::nothingAnswered($details);
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function present(array $rows, int $swept, int $degraded, int $reported, bool $local): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(
                ['swept' => $swept, 'degraded' => $degraded, 'reported' => $reported, 'projects' => $rows],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));

            return;
        }

        if ($rows !== []) {
            $this->table(['project', 'serving', 'failed checks'], $rows);
        }

        $sent = $local ? 'not sent (--local)' : "{$reported} reported";
        $this->info("Swept {$swept} project(s): {$degraded} not serving themselves, {$sent}.");
    }

    /**
     * @return iterable<User>
     */
    private function projects(): iterable
    {
        $one = $this->argument('project');
        if (is_string($one) && $one !== '') {
            $user = User::findByUsername($one);
            if (!$user) {
                $this->error("No such project: '{$one}'");

                return [];
            }

            return [$user];
        }

        return User::query()->cursor();
    }

    /**
     * The container project behind an account, or null when there is nothing
     * deployed to ask.
     *
     * A suspended account is skipped on purpose: its application is stopped
     * because somebody stopped it, and reporting a stopped site as a fault
     * would fill the sweep with the one failure that is not one. The same for
     * an app stopped through the API's stop/down action.
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

        // project() is the System\Project wrapper; the DinD runtime sits behind it.
        return $runtime instanceof Dind ? $runtime : null;
    }
}
