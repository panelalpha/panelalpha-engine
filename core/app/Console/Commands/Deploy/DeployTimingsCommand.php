<?php

namespace App\Console\Commands\Deploy;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployTimings;
use Illuminate\Console\Command;

/**
 * Where a deploy spent its time, without re-running it.
 *
 * The same breakdown the deploy-log API returns, for a terminal. Its reason to
 * exist is the cache: a repeat deploy of an unchanged commit that reports a low
 * hit ratio has lost its BuildKit cache, and the totals alone will not say so.
 */
class DeployTimingsCommand extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['deploy:timings'];

    protected $signature = 'project:deploy:timings {project : Project username}
        {--id= : Deploy id (defaults to the latest)}
        {--timeline : Every logged step with its duration, not just the phases}
        {--json : Print the raw summary}';

    protected $description = 'Show per-stage and per-build-layer timings for a deploy';

    public function handle(): int
    {
        $username = (string) $this->argument('project');
        $logger = DeployLogger::current($username);
        if ($logger === null) {
            $this->error("No deploy log for '{$username}'.");

            return self::FAILURE;
        }

        $summary = DeployTimings::summarize($logger->readLatest() ?? [], $logger->entries());

        if ($this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->line("Deploy timings for <info>{$username}</info>");
        $this->line('  total: '.($summary['total_seconds'] ?? '—').'s');

        $this->newLine();
        // Phases first: they are what an operator acts on. The three recorded
        // stages are kept below because they are what the API has always
        // returned, but `running` alone says nothing useful.
        $this->table(['Phase', 'Seconds'], array_map(
            static fn (array $p): array => [$p['name'], $p['seconds']],
            $summary['phases']
        ));
        $this->table(['Stage', 'Seconds'], array_map(
            static fn (array $s): array => [$s['name'], $s['seconds'] ?? 'running'],
            $summary['stages']
        ));

        if ($this->option('timeline')) {
            $this->newLine();
            $this->line('Every step, in order:');
            $this->table(['At', 'Took', 'Step'], array_map(
                static fn (array $t): array => [
                    $t['at'].'s',
                    $t['seconds'] === null ? '—' : $t['seconds'].'s',
                    mb_strimwidth($t['step'], 0, 92, '…'),
                ],
                $summary['timeline']
            ));
        }

        if ($summary['compose'] !== []) {
            $this->newLine();
            $this->line('What docker compose did:');
            $this->table(['Object', 'Action', 'Seconds'], array_map(
                static fn (array $c): array => [$c['object'], $c['action'], $c['seconds']],
                $summary['compose']
            ));
        }

        $build = $summary['build'];
        if ($build['step_count'] === 0) {
            $this->line('No build layers — this strategy builds no image.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Build: %ss across %d layers, %d cached (%s hit ratio)',
            $build['total_seconds'],
            $build['step_count'],
            $build['cached_steps'],
            $build['cache_hit_ratio'] === null ? '—' : (string) $build['cache_hit_ratio']
        ));
        // --timeline means every step, so show every layer too rather than
        // the ten that happened to be slowest in this particular run.
        $layers = $this->option('timeline') ? $build['steps'] : $build['slowest'];
        $this->table(['Layer', 'Seconds', 'Cached', 'Command'], array_map(
            static fn (array $s): array => [
                $s['step'],
                $s['cached'] ? '—' : $s['seconds'],
                $s['cached'] ? 'yes' : '',
                mb_strimwidth($s['command'], 0, 70, '…'),
            ],
            $layers
        ));

        return self::SUCCESS;
    }
}
