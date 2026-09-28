<?php

namespace App\Console\Commands\Users;

use App\Models\User;
use App\Console\Commands\Concerns\ResolvesProject;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class GetLimits extends Command
{
    use ResolvesProject;

    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['projects:get-limits', 'users:get-limits'];

    protected $signature = 'project:limit:get {--project= : Project username} {--username= : Deprecated alias for --project} {--all}';

    protected $description = 'Show a project\'s resource limits (disk, memory, CPU, bandwidth, inodes, per-feature counts)';

    public function handle(): int
    {
        $this->foldProjectOption();

        /**
         * @var array{
         *   username: ?string,
         *   all: bool,
         * }
         */
        $options = $this->options();

        if (!$options['username'] && !$options['all']) {
            $this->error('One of following options is required: `--project=NAME` or `--all`');
            return 1;
        }

        if ($options['username']) {
            $user = User::findByUsername($options['username']);
            if (!$user) {
                $this->error('Invalid username');
                return 1;
            }
            return $this->getLimits([$user]);
        }

        $users = User::all();
        return $this->getLimits($users);
    }

    /**
     * @param array<User>|Collection<int, User> $users
     */
    private function getLimits($users): int
    {
        foreach ($users as $user) {
            $this->info("User `{$user->username}`:");
            $limit = $user->getDiskSpaceLimit();
            $formatted = ($limit === -1 || $limit === null) ? "no limit" : ((string)$limit . " MB");
            $this->info("  disk_space_limit: " . $formatted);
            $limit = $user->getMemoryLimit();
            $formatted = ($limit === null || $limit <= 0)
                ? "not set, runs with the default ({$user->effectiveMemoryLimit()} MB)"
                : ((string)$limit . " MB");
            $this->info("  memory_limit:     " . $formatted);
            $limit = $user->getCpuLimit();
            $formatted = ($limit === null) ? "no limit" : ((string)$limit . " CPUs");
            $this->info("  cpu_limit:        " . $formatted);
            $limit = $user->getDeviceReadBps();
            $formatted = ($limit === null) ? "no limit" : ((string)$limit . " bps");
            $this->info("  device_read_bps:  " . $formatted);
            $limit = $user->getDeviceWriteBps();
            $formatted = ($limit === null) ? "no limit" : ((string)$limit . " bps");
            $this->info("  device_write_bps: " . $formatted);
            $limit = $user->getBandwidthLimit();
            $formatted = ($limit === null) ? "no limit" : ((string)$limit . " MB");
            $this->info("  bandwidth_limit:  " . $formatted);
            $limit = $user->getMysqlDatabasesLimit();
            $formatted = ($limit === null) ? "no limit" : (string)$limit;
            $this->info("  mysql_databases_limit: " . $formatted);
            $limit = $user->getFtpAccountsLimit();
            $formatted = ($limit === null) ? "no limit" : (string)$limit;
            $this->info("  ftp_accounts_limit: " . $formatted);
            $limit = $user->getSftpAccountsLimit();
            $formatted = ($limit === null) ? "no limit" : (string)$limit;
            $this->info("  sftp_accounts_limit: " . $formatted);
            $limit = $user->getAddonDomainsLimit();
            $formatted = ($limit === null) ? "no limit" : (string)$limit;
            $this->info("  addon_domains_limit: " . $formatted);
            $limit = $user->getSubdomainsLimit();
            $formatted = ($limit === null) ? "no limit" : (string)$limit;
            $this->info("  subdomains_limit: " . $formatted);
            $limit = $user->getInodesLimit();
            $formatted = ($limit === null) ? "no limit" : (string)$limit;
            $this->info("  inodes_limit:    " . $formatted);
        }
        return 0;
    }
}
