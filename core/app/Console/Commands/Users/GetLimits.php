<?php

namespace App\Console\Commands\Users;

use App\Lib\Limits\ResourceLimit;
use App\Models\User;
use App\Console\Commands\Concerns\ProjectOptions;
use App\Console\Commands\Concerns\SelectsProjects;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class GetLimits extends Command
{
    use SelectsProjects;

    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['projects:get-limits', 'users:get-limits'];

    protected $signature = 'project:limit:get' . ProjectOptions::SIGNATURE;

    protected $description = 'Show a project\'s resource limits (disk, memory, CPU, bandwidth, inodes, per-feature counts)';

    public function handle(): int
    {
        $projects = $this->selectedProjects();

        return $projects === null ? 1 : $this->getLimits($projects);
    }

    /**
     * @param array<User>|Collection<int, User> $users
     */
    private function getLimits($users): int
    {
        foreach ($users as $user) {
            $this->info("User `{$user->username}`:");
            foreach (ResourceLimit::all() as $limit) {
                $value = $user->limit($limit);
                // A limit that cannot be removed reads back as the default when it is unset.
                $text = $limit->alwaysApplies && ($value === null || $value <= 0)
                    ? 'not set, runs with the default (' . $limit->format($user->effectiveLimit($limit)) . ')'
                    : $limit->format($value);
                $this->info('  ' . self::label($limit->key) . $text);
            }
        }

        return 0;
    }

    /**
     * The label column exactly as it has always printed, since scripts read
     * this output: 18 wide, a single space after a longer key, and
     * inodes_limit one short.
     */
    private static function label(string $key): string
    {
        $width = $key === 'inodes_limit' ? 17 : 18;

        return str_pad($key . ':', max($width, strlen($key) + 2));
    }
}
