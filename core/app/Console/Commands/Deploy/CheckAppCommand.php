<?php

namespace App\Console\Commands\Deploy;

use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Ask a deployed application whether it is actually answering.
 *
 * Runs the same probe the deploy pipeline runs at the end of every deploy
 * ({@see AppHealth}), on demand — after a restart, while debugging a report
 * of "the site is down", or from a monitoring script.
 */
class CheckAppCommand extends Command
{
    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['deploy:check', 'deploy:check-app'];

    protected $signature = 'project:deploy:check
                            {project : Project username}
                            {--timeout=5 : Seconds to wait for each response}
                            {--attempts=3 : Probes per port before calling it down}
                            {--delay=2 : Seconds between attempts}
                            {--json : Print the raw result instead of a table}';

    protected $description = 'Check that a deployed application answers on the ports it publishes';

    public function handle(): int
    {
        $username = (string) $this->argument('project');

        $user = User::findByUsername($username);
        if (!$user) {
            $this->error("No such user: '{$username}'");
            return 1;
        }
        if ($user->getTemplate() !== 'dind') {
            $this->error("'{$username}' is not a dind account; there is no deployed application to check");
            return 1;
        }

        $project = self::dindOf($user);
        if ($project === null) {
            $this->error("'{$username}' does not run on the dind project driver");
            return 1;
        }

        $report = $project->appHealth()->check(
            max(1, (int) $this->option('timeout')),
            max(1, (int) $this->option('attempts')),
            max(0, (int) $this->option('delay')),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $report['healthy'] === false ? 1 : 0;
        }

        // The check could not be carried out — say that, rather than implying
        // the application is down when we never managed to ask it.
        if (isset($report['error'])) {
            $this->error("Could not check '{$username}': {$report['error']}");
            return 1;
        }

        if ($report['healthy'] === null) {
            $this->warn("'{$username}' publishes no application port to probe");
            return 0;
        }

        $this->table(
            ['Port', 'Scheme', 'Status', 'Response', 'Time'],
            array_map(static fn (array $result) => [
                $result['port'],
                $result['scheme'] ?? '-',
                $result['status'] === AppHealth::STATUS_OK ? '<info>ok</info>' : '<error>fail</error>',
                $result['detail'],
                $result['time'] !== null ? $result['time'] . 's' : '-',
            ], $report['ports'])
        );

        if ($report['healthy']) {
            $this->info("'{$username}' answered on every published port");
            return 0;
        }

        $this->error("'{$username}' did not answer on every published port");
        return 1;
    }

    /** User::project() is the System\Project wrapper; the DinD driver is its runtime(). */
    private static function dindOf(User $user): ?Dind
    {
        $runtime = $user->project()->runtime();

        return $runtime instanceof Dind ? $runtime : null;
    }
}
