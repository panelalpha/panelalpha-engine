<?php

namespace App\Console\Commands\Users;

use App\Models\User;
use App\Console\Commands\Concerns\ResolvesProject;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class RebuildQuotas extends Command
{
    use ResolvesProject;

    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['projects:rebuild-quotas', 'users:rebuild-quotas'];

    protected $signature = 'project:quota:rebuild {--project= : Project username} {--username= : Deprecated alias for --project} {--all}';

    protected $description = 'Recreate filesystem quotas for a project';

    public function handle(): int
    {
        $this->foldProjectOption();

        /** @var string */
        $username = $this->option('username');
        /** @var bool */
        $all = $this->option('all');

        if (!$username && !$all) {
            $this->error('One of following options is required: `--project=NAME` or `--all`');
            return 1;
        }

        if ($username) {
            $user = User::findByUsername($username);
            if (!$user) {
                $this->error('Invalid username');
                return 1;
            }
            return $this->rebuildQuotas([$user]);
        }

        $users = User::all();
        return $this->rebuildQuotas($users);
    }

    /**
     * @param array<User>|Collection<int, User> $users
     */
    private function rebuildQuotas($users): int
    {
        $ok = true;
        foreach ($users as $user) {
            try {
                $this->output->write("Rebuilding quota for user '{$user->username}'...\n");
                if ($user->project()->configureQuota()) {
                    $this->info("  Finished.");
                } else {
                    $this->error("  Not enforced: setquota failed, is quota on for the /home filesystem? See the log.");
                    $ok = false;
                }
            } catch (\Exception $e) {
                $this->error($e->getMessage());
                $ok = false;
            }
        }

        return (int)!$ok;
    }
}
