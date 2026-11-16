<?php

namespace App\Console\Commands\Projects;

use App\Lib\Project\ProjectOrphans;
use Illuminate\Console\Command;

class ProjectOrphanListCommand extends Command
{
    protected $signature = 'project:orphan:list';

    protected $description = 'List names an account, home, project directory or container holds without a project';

    public function handle(): int
    {
        $orphans = app(ProjectOrphans::class);
        try {
            $found = $orphans->all();
        } catch (\Throwable $e) {
            $this->error('Could not list what holds a name on this server: '.$e->getMessage());

            return 1;
        }

        if ($found === []) {
            $this->info('No name is held without a project.');

            return 0;
        }

        $this->table(['Name', 'Held by', 'Free it with'], array_map(
            static function (string $name, array $holders) use ($orphans): array {
                $foreign = $orphans->foreignSteps($name, $holders);

                return [
                    $name,
                    implode(', ', $holders),
                    $foreign === []
                        ? "pae project:orphan:delete {$name} --force"
                        : 'run '.implode(' and ', array_map(static fn (string $step): string => "`{$step}`", $foreign)).' first',
                ];
            },
            array_keys($found),
            array_values($found)
        ));

        return 0;
    }
}
