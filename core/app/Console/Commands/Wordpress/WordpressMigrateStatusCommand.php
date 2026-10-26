<?php

namespace App\Console\Commands\Wordpress;

use App\Exceptions\NotFoundException;
use App\Models\WordpressMigration;
use Illuminate\Console\Command;

/**
 * Prints step names and statuses for a WordPress migration.
 */
class WordpressMigrateStatusCommand extends Command
{
    protected $signature = 'wordpress:migrate:status
                            {id : Migration record id}';

    protected $description = 'Show WordPress migration step names and statuses';

    public function handle(): int
    {
        $id = (string) $this->argument('id');
        $status = self::find($id)->toStatusArray();

        $this->line('Migration '.$status['id'].' status: '.$status['status'].($status['stale'] ? ' (stale: resume it)' : ''));
        $this->line(sprintf('Progress: %s%% (%s)', $status['progress']['percent'], $status['progress']['message'] ?? '-'));
        $this->printTarget($status);

        foreach ($status['steps'] as $step) {
            $this->line($step['name'].': '.$step['status']);
        }
        foreach ($status['notices'] as $notice) {
            $this->warn($notice);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{target_project: ?string, target_domain: ?string, created_project: bool, preview_url: ?string, dns: array{type: string, value: string}|null}  $status
     */
    private function printTarget(array $status): void
    {
        $this->line('Project: '.($status['target_project'] ?? '-').($status['created_project'] ? ' (created by the import)' : ''));
        $this->line('Domain: '.($status['target_domain'] ?? '-'));
        if ($status['preview_url'] !== null) {
            $this->line('Preview: '.$status['preview_url']);
        }
        if ($status['dns'] !== null) {
            $this->line('DNS: '.$status['dns']['type'].' '.$status['dns']['value']);
        }
    }

    public static function find(string $id): WordpressMigration
    {
        $migration = ctype_digit($id) ? WordpressMigration::query()->find((int) $id) : null;

        return $migration ?? throw new NotFoundException("WordPress migration {$id} not found.", WordpressMigration::class);
    }
}
