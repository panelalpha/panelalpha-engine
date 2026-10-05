<?php

namespace App\Lib\Usage;

use App\Exceptions\NotFoundException;
use App\Integrations\Statistics\Statistics;
use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use Illuminate\Support\Facades\DB;

/** Resource usage and transfer for a project, shared by the API and the CLI. */
class ProjectUsage
{
    public function __construct(private Statistics $statistics)
    {
    }

    /**
     * @return array<string, array{usage: mixed, maximum: mixed}>
     */
    public function summary(User $user, ?System $system = null): array
    {
        $project = $user->project($system);
        $diskUsage = $project->fileManager()->diskUsage();
        // Only a DinD account keeps its containers' logs inside its own home.
        $logBytes = $project->runtime() instanceof Dind ? $project->fileManager()->containerLogBytes() : 0;

        $query = "SELECT ";
        $query .= "(SELECT COUNT(*) FROM domains WHERE user_id = ? AND type = 'addon') AS addon_domains, ";
        $query .= "(SELECT COUNT(*) FROM domains WHERE user_id = ? AND type = 'sub') AS subdomains, ";
        $query .= "(SELECT COUNT(*) FROM ftp_accounts WHERE user_id = ?) AS ftp_accounts, ";
        $query .= "(SELECT COUNT(*) FROM sftp_accounts WHERE user_id = ?) AS sftp_accounts, ";
        $query .= "(SELECT COUNT(*) FROM mysql_databases WHERE user_id = ?) AS mysql_databases";

        $result = DB::select($query, array_fill(0, 5, $user->id));
        /** @var object $counters */
        $counters = $result[0];

        $limitMb = $user->getBandwidthLimit();

        return [
            'storage' => [
                'usage' => $diskUsage,
                'maximum' => $user->getDiskSpaceLimit(),
            ],
            'logs' => [
                'usage' => $logBytes,
                'maximum' => null,
            ],
            'bandwidth' => [
                'usage' => $this->statistics->projectCalendarMonthBytes($this->domainNames($user)),
                'maximum' => $limitMb === null ? null : $limitMb * 1024 * 1024,
            ],
           'addon_domains' => [
              'usage' => $counters->addon_domains,
              'maximum' => $user->getAddonDomainsLimit(),
            ],
            'subdomains' => [
              'usage' => $counters->subdomains,
              'maximum' => $user->getSubdomainsLimit(),
            ],
            'ftp_accounts' => [
              'usage' => $counters->ftp_accounts,
              'maximum' => $user->getFtpAccountsLimit(),
            ],
            'sftp_accounts' => [
              'usage' => $counters->sftp_accounts,
              'maximum' => $user->getSftpAccountsLimit(),
            ],
            'mysql_databases' => [
              'usage' => $counters->mysql_databases,
              'maximum' => $user->getMysqlDatabasesLimit(),
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    public function projectBandwidth(User $user, string $start, string $end, string $groupBy): array
    {
        return $this->statistics->projectBandwidth($this->domainNames($user), $start, $end, $groupBy);
    }

    public function ownedDomain(User $user, string $domain): ?Domain
    {
        /** @var ?Domain */
        return $user->domains()->getQuery()->where('domain', $domain)->first();
    }

    public function ownedDomainOrFail(User $user, string $domain): Domain
    {
        return $this->ownedDomain($user, $domain)
            ?? throw new NotFoundException("Domain '{$domain}' not found for project '{$user->username}'.");
    }

    /**
     * @return list<string>
     */
    private function domainNames(User $user): array
    {
        return $user->domains()->pluck('domain')->all();
    }
}
