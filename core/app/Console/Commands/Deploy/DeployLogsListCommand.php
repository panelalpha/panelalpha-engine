<?php

namespace App\Console\Commands\Deploy;

use App\Lib\Deploy\DeployLog\DeployHistory;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User;
use Illuminate\Console\Command;

class DeployLogsListCommand extends Command
{
    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['deploy:log:list', 'deploy-logs:list'];

    protected $signature = 'project:deploy:list {project? : Project username; omit to list every project with logs}';

    protected $description = 'List projects that have deploy logs, or one project\'s deploy history';

    public function handle(): int
    {
        $username = $this->argument('project');
        if ($username !== null && $username !== '') {
            return $this->listDeploys((string) $username);
        }

        return $this->listUsers();
    }

    private function listUsers(): int
    {
        $rows = DeployLogger::listUsers();
        if ($rows === []) {
            $this->info('No deploy logs under '.DeployLogger::baseDir());

            return 0;
        }

        $this->table(
            ['Username', 'Status', 'Stage', 'Deploy ID', 'Logs', 'Size', 'Started', 'Finished'],
            array_map(static function (array $row): array {
                return [
                    $row['username'],
                    $row['status'] ?? '-',
                    $row['stage'] ?? '-',
                    $row['id'] ?? '-',
                    $row['log_count'],
                    self::formatBytes($row['total_bytes']),
                    self::formatTs($row['started_at']),
                    self::formatTs($row['finished_at']),
                ];
            }, $rows)
        );

        return 0;
    }

    private function listDeploys(string $username): int
    {
        $user = User::findByUsername($username);
        if ($user === null) {
            $this->error("Project '{$username}' not found.");

            return 1;
        }

        $rows = DeployHistory::of($user);
        if ($rows === []) {
            $this->info("No deploy logs for project '{$username}'");

            return 0;
        }

        $this->table(
            ['Deploy ID', 'Status', 'Kind', 'Source', 'Branch', 'Trigger', 'Commit', 'Started', 'Finished'],
            array_map(static function (array $row): array {
                return [
                    $row['id'],
                    $row['status'],
                    $row['kind'] ?? '-',
                    $row['source'] ?? '-',
                    $row['branch'] ?? '-',
                    $row['trigger'] ?? '-',
                    $row['commit'] !== null ? substr($row['commit'], 0, 12) : '-',
                    self::formatTs($row['started_at']),
                    self::formatTs($row['finished_at']),
                ];
            }, $rows)
        );

        return 0;
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }

    private static function formatTs(?int $ts): string
    {
        if ($ts === null || $ts <= 0) {
            return '-';
        }

        return date('Y-m-d H:i:s', $ts);
    }
}
