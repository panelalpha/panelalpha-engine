<?php

namespace App\Console\Commands\System;

use App\Console\Commands\Concerns\ConfirmsChanges;
use App\System;
use Illuminate\Console\Command;

class UpdateCommand extends Command
{
    use ConfirmsChanges;

    protected $signature = 'system:update
                            {--check : Say whether a newer release is available and change nothing}
                            {--refresh : With --check, ask the PanelAlpha Hub again instead of using the kept answer}
                            {--status : Say how the last update ended and change nothing}
                            {--json : With --check or --status, print the answer as JSON}
                            {--force : Update without asking}';

    protected $description = 'Update the engine to its newest release, or check whether one is available';

    public function handle(System $system): int
    {
        if ($this->option('status')) {
            return $this->status($system);
        }

        if (! $this->option('check') && $system->isUpdateScriptRunning()) {
            $this->getOutput()->getErrorStyle()->writeln('<error>An engine update is already running.</error>');

            return 1;
        }

        $check = $system->updateCheck()->check((bool) $this->option('refresh') || ! $this->option('check'));

        if ($this->option('check')) {
            return $this->report($check);
        }

        return $this->update($system, $check);
    }

    /** @param  array{running: array{version: ?string}, available: ?array{version: string, published_at: ?string, url: ?string, notes: ?string}, status: string, update_available: bool, reason: ?string, checked_at: int, cached: bool}  $check */
    private function report(array $check): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($check, JSON_UNESCAPED_SLASHES));

            return $check['status'] === 'unknown' ? 1 : 0;
        }

        $this->releases($check);
        $this->line('Checked:   '.gmdate('Y-m-d H:i:s', $check['checked_at']).' UTC'.($check['cached'] ? ' (kept answer; --refresh checks again)' : ''));

        if ($check['status'] === 'unknown') {
            $this->getOutput()->getErrorStyle()->writeln("<error>{$check['reason']}</error>");

            return 1;
        }

        $this->line(match ($check['status']) {
            'update_available' => 'An update is available.',
            'up_to_date' => 'Already up to date.',
            default => 'The available release is older than the running one; an update refuses it.',
        });

        return 0;
    }

    /** @param  array{running: array{version: ?string}, available: ?array{version: string, published_at: ?string, url: ?string, notes: ?string}, status: string, update_available: bool, reason: ?string, checked_at: int, cached: bool}  $check */
    private function update(System $system, array $check): int
    {
        $this->releases($check);
        if (! $check['update_available']) {
            $this->line($check['status'] === 'unknown' ? (string) $check['reason'] : 'Nothing to install.');

            return $check['status'] === 'unknown' ? 1 : 0;
        }

        if ($this->refusedWithoutConfirmation()) {
            return 1;
        }

        $target = $check['available']['version'] ?? 'the newest release';
        if (! $this->option('force')
            && ! $this->confirm("Update the engine to {$target}? The engine restarts, and the API and MCP disconnect for about a minute; sites keep running.")) {
            $this->info('Cancelled.');

            return 0;
        }

        $system->runUpdateScript();
        $this->info('The engine update has started. `pae system:update --status` says how it ended.');

        return 0;
    }

    private function status(System $system): int
    {
        $latest = $system->getLatestUpdateInfo();

        if ($this->option('json')) {
            $this->line((string) json_encode($latest, JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if ($latest === null) {
            $this->line('The engine has never been updated from here.');

            return 0;
        }

        $state = $latest['exit_code'] === null && ! $latest['failed'] ? 'running' : ($latest['result'] ?? ($latest['failed'] ? 'failed' : 'finished'));
        $this->line("Last update: {$state}");
        $this->line('From:        '.($latest['from_version'] ?? 'unknown').' to '.($latest['to_version'] ?? 'unknown'));
        $this->line('Started:     '.self::time($latest['started_at']));
        $this->line('Finished:    '.self::time($latest['finished_at']));
        if ($latest['reason'] !== null) {
            $this->line("Reason:      {$latest['reason']}");
        }
        $this->line("Log:         {$latest['logs_path']}");

        return 0;
    }

    /** @param  array{running: array{version: ?string}, available: ?array{version: string, published_at: ?string, url: ?string, notes: ?string}}  $check */
    private function releases(array $check): void
    {
        $available = $check['available'];
        $this->line('Running:   '.($check['running']['version'] ?? 'unknown'));
        $this->line('Available: '.($available === null
            ? 'unknown'
            : $available['version'].($available['published_at'] === null ? '' : ", published {$available['published_at']}")));
        if ($available !== null && $available['url'] !== null) {
            $this->line("Changes:   {$available['url']}");
        }
    }

    private static function time(?int $at): string
    {
        return $at === null ? '-' : gmdate('Y-m-d H:i:s', $at).' UTC';
    }
}
