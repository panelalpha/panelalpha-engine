<?php

namespace App\Console\Commands\Users;

use App\Lib\Host\ProjectMemory;
use App\Models\User;
use App\System\Project\Dind;
use App\Console\Commands\Concerns\ResolvesProject;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class SetLimits extends Command
{
    use ResolvesProject;

    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['projects:set-limits', 'users:set-limits'];

    protected $signature = 'project:limit:set {--project= : Project username} {--username= : Deprecated alias for --project} {--all}'
            . ' {--disk-space-limit=}'
            . ' {--memory-limit=}'
            . ' {--cpu-limit=}'
            . ' {--device-read-bps=}'
            . ' {--device-write-bps=}'
            . ' {--bandwidth-limit=}'
            . ' {--mysql-databases-limit=}'
            . ' {--ftp-accounts-limit=}'
            . ' {--sftp-accounts-limit=}'
            . ' {--addon-domains-limit=}'
            . ' {--subdomains-limit=}'
            . ' {--inodes-limit=}';
    protected $description = 'Set a project\'s resource limits (disk, memory, CPU, bandwidth, inodes, per-feature counts)';

    private bool $shouldRebuild = false;

    public function handle(): int
    {
        $this->foldProjectOption();

        /**
         * @var array{
         *   username: ?string,
         *   all: bool,
         *   disk-space-limit: ?string,
         *   memory-limit: ?string,
         *   cpu-limit: ?string,
         *   device-read-bps: ?string,
         *   device-write-bps: ?string,
         *   bandwidth-limit: ?string,
         *   mysql-databases-limit: ?string,
         *   ftp-accounts-limit: ?string,
         *   sftp-accounts-limit: ?string,
         *   addon-domains-limit: ?string,
         *   subdomains-limit: ?string,
         *   inodes-limit: ?string,
         * }
         */
        $options = $this->options();

        if (!$options['username'] && !$options['all']) {
            $this->error('One of following options is required: `--project=NAME` or `--all`');
            return 1;
        }

        if (
            $options['disk-space-limit'] === null
            && $options['memory-limit'] === null
            && $options['cpu-limit'] === null
            && $options['device-read-bps'] === null
            && $options['device-write-bps'] === null
            && $options['bandwidth-limit'] === null
            && $options['mysql-databases-limit'] === null
            && $options['ftp-accounts-limit'] === null
            && $options['sftp-accounts-limit'] === null
            && $options['addon-domains-limit'] === null
            && $options['subdomains-limit'] === null
            && $options['inodes-limit'] === null
        ) {
            $msg = 'At least one of following options is required: ';
            $msg .= '`--disk-space-limit=LIMIT_IN_MB`';
            $msg .= ' or `--memory-limit=LIMIT_IN_MB`';
            $msg .= ' or `--cpu-limit=LIMIT_IN_CPUS`';
            $msg .= ' or `--device-read-bps=LIMIT_IN_BPS`';
            $msg .= ' or `--device-write-bps=LIMIT_IN_BPS`';
            $msg .= ' or `--bandwidth-limit=LIMIT_IN_MB`';
            $msg .= ' or `--mysql-databases-limit=LIMIT`';
            $msg .= ' or `--ftp-accounts-limit=LIMIT`';
            $msg .= ' or `--sftp-accounts-limit=LIMIT`';
            $msg .= ' or `--addon-domains-limit=LIMIT`';
            $msg .= ' or `--subdomains-limit=LIMIT`';
            $msg .= ' or `--inodes-limit=LIMIT`';
            $this->error($msg);
            return 1;
        }

        $newLimits = [];
        if ($options['disk-space-limit'] !== null) {
            $value = (int)$options['disk-space-limit'];
            $value = max(-1, $value);
            $newLimits['disk_space_limit'] = [
                'value' => $value,
                'formatted' => $value === -1 ? "no limit" : ((string)$value . " MB"),
            ];
        }
        if ($options['memory-limit'] !== null) {
            // Every project has a memory limit: it can be changed, not removed.
            $value = (int)$options['memory-limit'];
            if ($value < 1) {
                $this->error('The memory limit is in MB and must be a positive number.');
                return 1;
            }
            $problem = ProjectMemory::changeProblem($value);
            if ($problem !== null) {
                $this->error($problem['message']);
                return 1;
            }
            $newLimits['memory_limit'] = [
                'value' => $value,
                'formatted' => (string)$value . " MB",
            ];
        }
        if ($options['cpu-limit'] !== null) {
            $value = (float)$options['cpu-limit'];
            $value = max(-1.0, $value);
            if ($value === -1.0) {
                $value = null;
            }
            $newLimits['cpu_limit'] = [
                'value' => $value,
                'formatted' => $value === null ? "no limit" : ((string)$value . " CPUs"),
            ];
        }
        if ($options['device-read-bps'] !== null) {
            $value = (int)$options['device-read-bps'];
            $value = max(-1, $value);
            if ($value === -1) {
                $value = null;
            }
            $newLimits['device_read_bps'] = [
                'value' => $value,
                'formatted' => $value === null ? "no limit" : ((string)$value . " bps"),
            ];
        }
        if ($options['device-write-bps'] !== null) {
            $value = (int)$options['device-write-bps'];
            $value = max(-1, $value);
            if ($value === -1) {
                $value = null;
            }
            $newLimits['device_write_bps'] = [
                'value' => $value,
                'formatted' => $value === null ? "no limit" : ((string)$value . " bps"),
            ];
        }
        if ($options['bandwidth-limit'] !== null) {
            $value = (int)$options['bandwidth-limit'];
            $value = max(-1, $value);
            if ($value === -1) {
                $value = null;
            }
            $newLimits['bandwidth_limit'] = [
                'value' => $value,
                'formatted' => $value === null ? "no limit" : ((string)$value . " MB"),
            ];
        }
        if ($options['mysql-databases-limit'] !== null) {
            $value = (int)$options['mysql-databases-limit'];
            $value = max(-1, $value);
            if ($value === -1) {
                $value = null;
            }
            $newLimits['mysql_databases_limit'] = [
                'value' => $value,
                'formatted' => $value === null ? "no limit" : (string)$value,
            ];
        }
        if ($options['ftp-accounts-limit'] !== null) {
            $value = (int)$options['ftp-accounts-limit'];
            $value = max(-1, $value);
            if ($value === -1) {
                $value = null;
            }
            $newLimits['ftp_accounts_limit'] = [
                'value' => $value,
                'formatted' => $value === null ? "no limit" : (string)$value,
            ];
        }
        if ($options['sftp-accounts-limit'] !== null) {
            $value = (int)$options['sftp-accounts-limit'];
            $value = max(-1, $value);
            if ($value === -1) {
                $value = null;
            }
            $newLimits['sftp_accounts_limit'] = [
                'value' => $value,
                'formatted' => $value === null ? "no limit" : (string)$value,
            ];
        }
        if ($options['addon-domains-limit'] !== null) {
            $value = (int)$options['addon-domains-limit'];
            $value = max(-1, $value);
            if ($value === -1) {
                $value = null;
            }
            $newLimits['addon_domains_limit'] = [
                'value' => $value,
                'formatted' => $value === null ? "no limit" : (string)$value,
            ];
        }
        if ($options['subdomains-limit'] !== null) {
            $value = (int)$options['subdomains-limit'];
            $value = max(-1, $value);
            if ($value === -1) {
                $value = null;
            }
            $newLimits['subdomains_limit'] = [
                'value' => $value,
                'formatted' => $value === null ? "no limit" : (string)$value,
            ];
        }
        if ($options['inodes-limit'] !== null) {
            $value = (int)$options['inodes-limit'];
            $value = max(-1, $value);
            if ($value === -1) {
                $value = null;
            }
            $newLimits['inodes_limit'] = [
                'value' => $value,
                'formatted' => $value === null ? "no limit" : (string)$value,
            ];
        }

        if ($options['username']) {
            $user = User::findByUsername($options['username']);
            if (!$user) {
                $this->error('Invalid username');
                return 1;
            }
            $this->warn("Following limits will be set for user `{$user->username}`:");
            foreach ($newLimits as $type => $limit) {
                $this->info(str_pad("  " . $type . ": ", 20) . $limit['formatted']);
            }
            if (!$this->confirm('Do you wish to continue?')) {
                return 0;
            }
            return $this->setLimits([$user], $newLimits);
        }

        $users = User::all();
        $count = count($users);
        $this->warn("Following limits will be set for all ({$count}) users:");
        foreach ($newLimits as $type => $limit) {
            $this->info(str_pad("  " . $type . ": ", 16) . $limit['formatted']);
        }
        if (!$this->confirm('Do you wish to continue?')) {
            return 0;
        }
        return $this->setLimits($users, $newLimits);
    }

    /**
     * @param array<User>|Collection<int, User> $users
     * @param array{
     *   disk_space_limit?: array{value: ?int, ...},
     *   memory_limit?: array{value: ?int, ...},
     *   cpu_limit?: array{value: ?float, ...},
     *   device_read_bps?: array{value: ?int, ...},
     *   device_write_bps?: array{value: ?int, ...},
     *   bandwidth_limit?: array{value: ?int, ...},
     *   mysql_databases_limit?: array{value: ?int, ...},
     *   ftp_accounts_limit?: array{value: ?int, ...},
     *   sftp_accounts_limit?: array{value: ?int, ...},
     *   addon_domains_limit?: array{value: ?int, ...},
     *   subdomains_limit?: array{value: ?int, ...},
     *   inodes_limit?: array{value: ?int, ...},
     * } $limits
     */
    private function setLimits($users, array $limits): int
    {
        foreach ($users as $user) {
            if (array_key_exists('disk_space_limit', $limits)) {
                $user->setDiskSpaceLimit($limits['disk_space_limit']['value']);
                $this->shouldRebuild = true;
            }
            if (array_key_exists('memory_limit', $limits)) {
                $user->setMemoryLimit($limits['memory_limit']['value']);
                // A DinD account takes it live below; anything else at its rebuild.
                if (!($user->project()->runtime() instanceof Dind)) {
                    $this->shouldRebuild = true;
                }
            }
            if (array_key_exists('cpu_limit', $limits)) {
                $user->setCpuLimit($limits['cpu_limit']['value']);
                $this->shouldRebuild = true;
            }
            if (array_key_exists('device_read_bps', $limits)) {
                $user->setDeviceReadBps($limits['device_read_bps']['value']);
                $this->shouldRebuild = true;
            }
            if (array_key_exists('device_write_bps', $limits)) {
                $user->setDeviceWriteBps($limits['device_write_bps']['value']);
                $this->shouldRebuild = true;
            }
            if (array_key_exists('bandwidth_limit', $limits)) {
                $user->setBandwidthLimit($limits['bandwidth_limit']['value']);
            }
            if (array_key_exists('mysql_databases_limit', $limits)) {
                $user->setMysqlDatabasesLimit($limits['mysql_databases_limit']['value']);
            }
            if (array_key_exists('ftp_accounts_limit', $limits)) {
                $user->setFtpAccountsLimit($limits['ftp_accounts_limit']['value']);
            }
            if (array_key_exists('sftp_accounts_limit', $limits)) {
                $user->setSftpAccountsLimit($limits['sftp_accounts_limit']['value']);
            }
            if (array_key_exists('addon_domains_limit', $limits)) {
                $user->setAddonDomainsLimit($limits['addon_domains_limit']['value']);
            }
            if (array_key_exists('subdomains_limit', $limits)) {
                $user->setSubdomainsLimit($limits['subdomains_limit']['value']);
            }
            if (array_key_exists('inodes_limit', $limits)) {
                $user->setInodesLimit($limits['inodes_limit']['value']);
                $this->shouldRebuild = true;
            }

            $user->save();

            $runtime = $user->project()->runtime();
            if (array_key_exists('memory_limit', $limits) && $runtime instanceof Dind) {
                $runtime->applyMemoryLimit();
                $this->info("  {$user->username}: memory limit applied to the running account.");
            }
        }
        $this->info("Limits have been updated.");
        if ($this->shouldRebuild) {
            $this->warn("Changes will take effect after rebuild");
        }
        return 0;
    }
}
