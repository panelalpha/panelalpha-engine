<?php

namespace App\Models;

use App\Lib\Vault\GlobalVault;
use App\System\Project as AppSystemProject;
use App\System\Services\Webserver\AbstractWebserver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $username
 * @property string $domain
 * @property string $email
 * @property string $name
 * @property string $status
 * @property ?int $staging
 * @property ?Carbon $email_verified_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Collection<int, Domain> $domains
 * @property Collection<array-key, FtpAccount> $ftpAccounts
 * @property int $ftp_accounts_count
 * @property Collection<array-key, SftpAccount> $sftpAccounts
 * @property int $sftp_accounts_count
 * @property Collection<array-key, MysqlUser> $mysqlUsers
 * @property Collection<array-key, MysqlSsoToken> $mysqlSsoTokens
 * @property Collection<array-key, MysqlDatabase> $mysqlDatabases
 * @property int $mysql_databases_count
 * @property Collection<array-key, IpAssigned> $assignedIpAddresses
 * @property Collection<int, Backup> $backups
 * @psalm-property ?UserDetails $details
 * @psalm-type UserDetails = array{
 *   mysql_prefix?: string,
 *   ...
 * }
 * @method static ?User find(int $id)
 * @method static User findOrFail(int $id)
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    private const ENCRYPTED_SECRET_PREFIX = 'laravel-encrypted:v1:';

    protected $fillable = [
        'username',
        'domain',
        'email',
        'name',
        'status',
        'staging',
        'details',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'details' => 'array',
    ];

    public static function findByUsername(string $username): ?User
    {
        /** @var ?User */
        return self::query()
            ->where('username', $username)
            ->first();
    }

    public function liveUser(): BelongsTo
    {
        return $this->belongsTo(self::class, 'staging');
    }

    public function stagingUser(): HasOne
    {
        return $this->hasOne(self::class, 'staging');
    }

    public function isStaging(): bool
    {
        return $this->staging !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function asyncStatus(): array
    {
        $details = $this->getDetails();

        return is_array($details['async_status'] ?? null) ? $details['async_status'] : [];
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function mergeAsyncStatus(array $patch): void
    {
        $this->setDetails([
            'async_status' => array_merge($this->asyncStatus(), $patch),
        ]);
    }

    /** Must run before cleanup: a row that survives it would otherwise still read "running". */
    public function markStagingFailed(string $error): void
    {
        $this->mergeAsyncStatus(['staging' => 'failed']);
        $this->setDetails(['error' => $error]);
        $this->save();
    }

    /**
     * Frozen deploy fields a clone/staging mirror needs to start the copied
     * app without re-running detection. `Dind::app()` keys off
     * `deploy_strategy`; without it `startUserApp()` throws.
     *
     * @param array<string, mixed> $sourceDetails from getDetails()
     * @return array<string, mixed>
     */
    public static function copiedDeploySnapshot(array $sourceDetails): array
    {
        $out = [];
        foreach ([
            'deploy_source',
            'deploy_strategy',
            'deploy_label',
            'deploy_port',
            'deploy_runtime',
            'deploy_platform',
            'deploy_checks_dir',
            'deploy_image',
            'git_commit',
            'git_branch',
        ] as $key) {
            if (array_key_exists($key, $sourceDetails)) {
                $out[$key] = $sourceDetails[$key];
            }
        }

        return $out;
    }

    /**
     * Resource limits plus the frozen deploy snapshot for a copied project.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function detailsForCopiedProject(string $destUsername, array $extra = []): array
    {
        $srcDetails = $this->getDetails();

        return array_merge([
            'home_dir'               => "/home/{$destUsername}",
            'mysql_prefix'           => $destUsername . '_',
            'disk_space_limit'       => $srcDetails['disk_space_limit'] ?? -1,
            'memory_limit'           => $srcDetails['memory_limit'] ?? null,
            'cpu_limit'              => $srcDetails['cpu_limit'] ?? null,
            'device_read_bps'        => $srcDetails['device_read_bps'] ?? null,
            'device_write_bps'       => $srcDetails['device_write_bps'] ?? null,
            'bandwidth_limit'        => $srcDetails['bandwidth_limit'] ?? null,
            'mysql_databases_limit'  => $srcDetails['mysql_databases_limit'] ?? null,
            'ftp_accounts_limit'     => $srcDetails['ftp_accounts_limit'] ?? null,
            'sftp_accounts_limit'    => $srcDetails['sftp_accounts_limit'] ?? null,
            'addon_domains_limit'    => $srcDetails['addon_domains_limit'] ?? null,
            'subdomains_limit'       => $srcDetails['subdomains_limit'] ?? null,
            'inodes_limit'           => $srcDetails['inodes_limit'] ?? null,
            'php_fpm_pool_settings'  => $srcDetails['php_fpm_pool_settings'] ?? null,
            'lsphp_settings'         => $srcDetails['lsphp_settings'] ?? null,
            'redis_config'           => $srcDetails['redis_config'] ?? null,
            'dedicated_ipv4'         => false,
            'dedicated_ipv6'         => false,
            'template'               => $srcDetails['template'] ?? null,
            'git_repo'               => $srcDetails['git_repo'] ?? null,
            'app_port'               => $srcDetails['app_port'] ?? null,
        ], self::copiedDeploySnapshot($srcDetails), $extra);
    }

    public function applyDeploySnapshotFrom(self $source): void
    {
        $snapshot = self::copiedDeploySnapshot($source->getDetails());
        if ($snapshot === []) {
            return;
        }
        $this->setDetails($snapshot);
    }

    public function makePendingStaging(string $destUsername, string $destDomain, string $source = 'api'): self
    {
        $newDetails = $this->detailsForCopiedProject($destUsername, [
            'async_status' => [
                'staging' => 'running',
                'source'  => $source,
            ],
        ]);

        $dest = new self([
            'username' => $destUsername,
            'domain'   => $destDomain,
            'email'    => $this->email,
            'status'   => 'pending',
            'staging'  => $this->id,
            'details'  => $newDetails,
        ]);

        $dest->save();

        $wwwAliasAvailable = !Domain::domainOrAliasExists('www.' . $destDomain);
        $domainAliases = $wwwAliasAvailable ? ['www.' . $destDomain] : [];

        $domainModel = Domain::make([
            'user_id' => $dest->id,
            'domain'  => $destDomain,
            'type'    => 'main',
            'details' => [
                'document_root'    => "/{$destDomain}/public_html",
                'redirect_enabled' => false,
                'redirect_url'     => null,
                'aliases'          => $domainAliases,
            ],
        ]);
        $domainModel->save();

        return $dest;
    }

    public static function findByUsernameOrFail(string $username): User
    {
        $user = self::findByUsername($username);
        if ($user === null) {
            throw new ModelNotFoundException();
        }
        return $user;
    }

    public static function existsByUsername(string $username): bool
    {
        return self::query()
            ->where('username', $username)
            ->exists();
    }

    /**
     * @return array<User>
     */
    public static function getAll(): array
    {
        /** @var Collection<array-key, self> */
        $users = self::get();
        return $users->all();
    }

    /**
     * @psalm-return UserDetails
     */
    public function getDetails(): array
    {
        $details = is_null($this->details) ? [] : $this->details;
        if (!is_array($details)) {
            return [];
        }

        if (isset($details['git_token']) && is_string($details['git_token'])) {
            $details['git_token'] = $this->decryptSecretString($details['git_token']);
        }
        if (isset($details['cloudflare_api_token']) && is_string($details['cloudflare_api_token'])) {
            $details['cloudflare_api_token'] = $this->decryptSecretString($details['cloudflare_api_token']);
        }
        if (isset($details['cloudflare_tunnel_token']) && is_string($details['cloudflare_tunnel_token'])) {
            $details['cloudflare_tunnel_token'] = $this->decryptSecretString($details['cloudflare_tunnel_token']);
        }
        if (isset($details['site_git']) && is_array($details['site_git'])) {
            foreach ($details['site_git'] as $key => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (isset($entry['token']) && is_string($entry['token'])) {
                    $entry['token'] = $this->decryptSecretString($entry['token']);
                    $details['site_git'][$key] = $entry;
                }
            }
        }
        if (isset($details['env_vars']) && is_string($details['env_vars'])) {
            $decoded = $this->decryptSecretString($details['env_vars']);
            $decoded = $decoded !== null ? json_decode($decoded, true) : null;
            $details['env_vars'] = is_array($decoded) ? $decoded : [];
        }

        return $details;
    }

    /**
     * Encrypt only secret values inside the JSON details document. Existing
     * plaintext records remain readable and are upgraded on their next write.
     */
    public function setDetailsAttribute(mixed $value): void
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        $details = is_array($value) ? $value : [];

        if (isset($details['git_token']) && is_string($details['git_token']) && $details['git_token'] !== '') {
            $details['git_token'] = $this->encryptSecretString($details['git_token']);
        }
        if (isset($details['cloudflare_api_token']) && is_string($details['cloudflare_api_token']) && $details['cloudflare_api_token'] !== '') {
            $details['cloudflare_api_token'] = $this->encryptSecretString($details['cloudflare_api_token']);
        }
        if (isset($details['cloudflare_tunnel_token']) && is_string($details['cloudflare_tunnel_token']) && $details['cloudflare_tunnel_token'] !== '') {
            $details['cloudflare_tunnel_token'] = $this->encryptSecretString($details['cloudflare_tunnel_token']);
        }
        if (isset($details['site_git']) && is_array($details['site_git'])) {
            foreach ($details['site_git'] as $key => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (isset($entry['token']) && is_string($entry['token']) && $entry['token'] !== '') {
                    $entry['token'] = $this->encryptSecretString($entry['token']);
                    $details['site_git'][$key] = $entry;
                }
            }
        }
        if (isset($details['env_vars']) && is_array($details['env_vars'])) {
            $details['env_vars'] = $this->encryptSecretString(
                (string) json_encode($details['env_vars'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }

        $this->attributes['details'] = json_encode($details);
    }

    /**
     * @param array{
     *   mysql_prefix?: string,
     *   ...
     * } $details
     * @return array
     */
    public function setDetails($details)
    {
        $this->details = array_merge(
            $this->getDetails(),
            $details,
        );

        return $this->getDetails();
    }

    private function encryptSecretString(string $value): string
    {
        if (str_starts_with($value, self::ENCRYPTED_SECRET_PREFIX)) {
            return $value;
        }

        return self::ENCRYPTED_SECRET_PREFIX . Crypt::encryptString($value);
    }

    /**
     * Null means the ciphertext could not be read — almost always a rotated
     * APP_KEY. Logged loudly: silently dropping a git token or a set of env
     * vars turns into a deploy that fails for reasons nobody can trace.
     */
    private function decryptSecretString(string $value): ?string
    {
        if (!str_starts_with($value, self::ENCRYPTED_SECRET_PREFIX)) {
            return $value;
        }

        try {
            return Crypt::decryptString(substr($value, strlen(self::ENCRYPTED_SECRET_PREFIX)));
        } catch (\Throwable $e) {
            Log::warning(
                "Could not decrypt stored secret for user '{$this->username}' "
                    . '(APP_KEY rotated?): ' . $e->getMessage()
            );

            return null;
        }
    }

    public function getMysqlPrefix(): string
    {
        return $this->details['mysql_prefix'] ?? '';
    }

    /**
     * mysql_user_create/mysql_database_create add the account's prefix when
     * a caller omits it; every other MySQL endpoint looks up the stored,
     * always-prefixed value by exact match. Accept either form so a caller
     * doesn't have to know which name it stored.
     */
    public function qualifyMysqlUser(string $name): string
    {
        $prefix = $this->getMysqlPrefix();

        return Str::startsWith($name, $prefix) ? $name : $prefix . $name;
    }

    public function qualifyMysqlDatabase(string $name): string
    {
        $prefix = $this->getMysqlPrefix();

        return Str::startsWith($name, $prefix) ? $name : $prefix . $name;
    }

    public function getMainDomain(): ?Domain
    {
        $query = $this->domains()->getQuery()->where('type', 'main');
        /** @var ?Domain */
        return $query->first();
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * @return array<Domain>
     */
    public function getDomains(): array
    {
        return $this->domains->all();
    }

    public function findDomainByDocumentRoot(string $path): ?Domain
    {
        $relPath = Str::after($path, $this->getHomeDir());
        $query = $this->domains()
            ->getQuery()
            ->whereJsonContains('details->document_root', $relPath);
        /** @var ?Domain */
        return $query->first();
    }

    public function ftpAccounts(): HasMany
    {
        return $this->hasMany(FtpAccount::class);
    }

    public function sftpAccounts(): HasMany
    {
        return $this->hasMany(SftpAccount::class);
    }

    /**
     * @return array<SftpAccount>
     */
    public function getSftpAccounts(): array
    {
        return $this->sftpAccounts->all();
    }

    public function mysqlDatabases(): HasMany
    {
        return $this->hasMany(MysqlDatabase::class);
    }

    /**
     * @return array<MysqlDatabase>
     */
    public function getMysqlDatabases(): array
    {
        return $this->mysqlDatabases->all();
    }

    public function mysqlUsers(): HasMany
    {
        return $this->hasMany(MysqlUser::class);
    }

    public function mysqlSsoTokens(): HasMany
    {
        return $this->hasMany(MysqlSsoToken::class);
    }

    public function assignedIpAddresses(): HasMany
    {
        return $this->hasMany(IpAssigned::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    /** @var ?array<IpAssigned> */
    private static ?array $assignedIpAddressesOverride = null;

    /**
     * @param array<IpAssigned> $ips
     */
    public static function setAssignedIpAddressesOverride(array $ips): void
    {
        self::$assignedIpAddressesOverride = $ips;
    }

    public static function clearAssignedIpAddressesOverride(): void
    {
        self::$assignedIpAddressesOverride = null;
    }

    /**
     * @return array<IpAssigned>
     */
    public function getAssignedIpAddresses(): array
    {
        if (self::$assignedIpAddressesOverride !== null) {
            return self::$assignedIpAddressesOverride;
        }
        return $this->assignedIpAddresses->all();
    }

    public function listAllDomainNames(): array
    {
        $names = [];
        foreach ($this->domains as $domain) {
            $names[] = $domain->domain;
            foreach ($domain->getAliases() as $alias) {
                $names[] = $alias;
            }
        }
        return $names;
    }

    public function project(?\App\System $system = null): AppSystemProject
    {
        return ($system ?? new \App\System())->project($this);
    }

    public function getDiskSpaceLimit(): ?int
    {
        $details = $this->getDetails();
        if (array_key_exists('disk_space_limit', $details)) {
            if ($details['disk_space_limit'] === null) {
                return null;
            }
            return (int)$details['disk_space_limit'];
        }
        return null;
    }

    public function setDiskSpaceLimit(?int $value): void
    {
        $this->setDetails(['disk_space_limit' => $value]);
    }

    public function getCpuLimit(): ?float
    {
        $details = $this->getDetails();
        if (array_key_exists('cpu_limit', $details)) {
            if ($details['cpu_limit'] === null) {
                return null;
            }
            return (float)$details['cpu_limit'];
        }
        return null;
    }

    public function setCpuLimit(?float $value): void
    {
        $this->setDetails(['cpu_limit' => $value]);
    }

    public function getDeviceReadBps(): ?int
    {
        $details = $this->getDetails();
        if (array_key_exists('device_read_bps', $details)) {
            if ($details['device_read_bps'] === null) {
                return null;
            }
            return (int)$details['device_read_bps'];
        }
        return null;
    }

    public function setDeviceReadBps(?int $value): void
    {
        $this->setDetails(['device_read_bps' => $value]);
    }

    public function getDeviceWriteBps(): ?int
    {
        $details = $this->getDetails();
        if (array_key_exists('device_write_bps', $details)) {
            if ($details['device_write_bps'] === null) {
                return null;
            }
            return (int)$details['device_write_bps'];
        }
        return null;
    }

    public function setDeviceWriteBps(?int $value): void
    {
        $this->setDetails(['device_write_bps' => $value]);
    }

    public function getBandwidthLimit(): ?int
    {
        $details = $this->getDetails();
        if (array_key_exists('bandwidth_limit', $details)) {
            if ($details['bandwidth_limit'] === null) {
                return null;
            }
            return (int)$details['bandwidth_limit'];
        }
        return null;
    }

    public function setBandwidthLimit(?int $value): void
    {
        $this->setDetails(['bandwidth_limit' => $value]);
    }

    public function getMysqlDatabasesLimit(): ?int
    {
        $details = $this->getDetails();
        if (array_key_exists('mysql_databases_limit', $details)) {
            if ($details['mysql_databases_limit'] === null) {
                return null;
            }
            return (int)$details['mysql_databases_limit'];
        }
        return null;
    }

    public function setMysqlDatabasesLimit(?int $value): void
    {
        $this->setDetails(['mysql_databases_limit' => $value]);
    }

    public function getFtpAccountsLimit(): ?int
    {
        $details = $this->getDetails();
        if (array_key_exists('ftp_accounts_limit', $details)) {
            if ($details['ftp_accounts_limit'] === null) {
                return null;
            }
            return (int)$details['ftp_accounts_limit'];
        }
        return null;
    }

    public function setFtpAccountsLimit(?int $value): void
    {
        $this->setDetails(['ftp_accounts_limit' => $value]);
    }

    public function getSftpAccountsLimit(): ?int
    {
        $details = $this->getDetails();
        if (array_key_exists('sftp_accounts_limit', $details)) {
            if ($details['sftp_accounts_limit'] === null) {
                return null;
            }
            return (int)$details['sftp_accounts_limit'];
        }
        return null;
    }

    public function setSftpAccountsLimit(?int $value): void
    {
        $this->setDetails(['sftp_accounts_limit' => $value]);
    }

    public function getAddonDomainsLimit(): ?int
    {
        $details = $this->getDetails();
        if (array_key_exists('addon_domains_limit', $details)) {
            if ($details['addon_domains_limit'] === null) {
                return null;
            }
            return (int)$details['addon_domains_limit'];
        }
        return null;
    }

    public function setAddonDomainsLimit(?int $value): void
    {
        $this->setDetails(['addon_domains_limit' => $value]);
    }

    // 'subdomains_limit' => 'integer|nullable',
    public function getSubdomainsLimit(): ?int
    {
        $details = $this->getDetails();
        if (array_key_exists('subdomains_limit', $details)) {
            if ($details['subdomains_limit'] === null) {
                return null;
            }
            return (int)$details['subdomains_limit'];
        }
        return null;
    }

    public function setSubdomainsLimit(?int $value): void
    {
        $this->setDetails(['subdomains_limit' => $value]);
    }

    public function getInodesLimit(): ?int
    {
        $details = $this->getDetails();
        if (array_key_exists('inodes_limit', $details)) {
            if ($details['inodes_limit'] === null) {
                return null;
            }
            return (int)$details['inodes_limit'];
        }
        return null;
    }

    public function setInodesLimit(?int $value): void
    {
        $this->setDetails(['inodes_limit' => $value]);
    }

    public function getMemoryLimit(): ?int
    {
        $details = $this->getDetails();
        if (array_key_exists('memory_limit', $details)) {
            if ($details['memory_limit'] === null) {
                return null;
            }
            return (int)$details['memory_limit'];
        }
        return null;
    }

    public function setMemoryLimit(?int $value): void
    {
        $this->setDetails(['memory_limit' => $value]);
    }

    public function getRealFtpAccountQuota(?int $ftpAccountQuota): ?int
    {
        $limit = $this->getDiskSpaceLimit();
        if ($limit === null) {
            return $ftpAccountQuota;
        }
        if ($ftpAccountQuota === null) {
            return $limit;
        }
        if ($limit > -1 && $ftpAccountQuota > $limit) {
            return $limit;
        }
        return $ftpAccountQuota;
    }

    public function getHomeDir(): string
    {
        /** @var mixed */
        $homeDir = $this->getDetails()['home_dir'] ?? null;
        if ($homeDir === "/var/www") {
            //backwards compatibility
            return $this->project()->homeDirPath();
        }
        if (!empty($homeDir) && is_string($homeDir)) {
            return $homeDir;
        }
        return "/home/{$this->username}";
    }

    public function getUid(): ?int
    {
        $details = $this->getDetails();
        if (empty($details['UID']) || !is_int($details['UID'])) {
            return null;
        }
        return $details['UID'];
    }

    public function getGid(): ?int
    {
        $details = $this->getDetails();
        if (empty($details['GID']) || !is_int($details['GID'])) {
            return null;
        }
        return $details['GID'];
    }

    public function getChownString(): ?string
    {
        $uid = $this->getUid();
        $gid = $this->getGid();
        if ($uid && $gid) {
            return "{$uid}:{$gid}";
        }
        return null;
    }

    public function getConfig(): array
    {
        $config = [
            "home_dir" => $this->getHomeDir(),
            "mysql_prefix" => $this->getMysqlPrefix(),
            "disk_space_limit" => $this->getDiskSpaceLimit(),
            "memory_limit" => $this->getMemoryLimit(),
            "cpu_limit" => $this->getCpuLimit(),
            "device_read_bps" => $this->getDeviceReadBps(),
            "device_write_bps" => $this->getDeviceWriteBps(),
            "bandwidth_limit" => $this->getBandwidthLimit(),
            "mysql_databases_limit" => $this->getMysqlDatabasesLimit(),
            "ftp_accounts_limit" => $this->getFtpAccountsLimit(),
            "sftp_accounts_limit" => $this->getSftpAccountsLimit(),
            "addon_domains_limit" => $this->getAddonDomainsLimit(),
            "subdomains_limit" => $this->getSubdomainsLimit(),
            "inodes_limit" => $this->getInodesLimit(),
            "php_fpm_pool_settings" => $this->getPhpFpmPoolSettings(),
            "lsphp_settings" => $this->getLsPhpSettings(),
            "redis_config" => $this->getRedisConfig(),
            "dedicated_ipv4" => $this->dedicatedIpv4Enabled(),
            "dedicated_ipv6" => $this->dedicatedIpv6Enabled(),
            "ip_addresses" => $this->getIpAddresses(),
            "UID" => $this->getUid(),
            "GID" => $this->getGid(),
        ];

        return $config;
    }

    /**
     * @return array<string,string>
     */
    public function getPhpFpmPoolSettings(): array
    {
        $default = [
            'pm' => 'dynamic',
            'pm.max_children' => '5',
            'pm.start_servers' => '2',
            'pm.min_spare_servers' => '1',
            'pm.max_spare_servers' => '3',
            'pm.max_requests' => '0',
        ];

        $details = $this->getDetails();
        if (
            empty($details['php_fpm_pool_settings'])
            || !is_string($details['php_fpm_pool_settings'])
        ) {
            return $default;
        }

        $parsed = [];
        $raw = trim($details['php_fpm_pool_settings']);
        $lines = explode("\n", $raw);
        foreach ($lines as $line) {
            $line = trim($line);
            $parts = explode(" = ", $line, 2);
            if (count($parts) < 2) {
                continue;
            }
            $parsed[$parts[0]] = $parts[1];
        }

        $validated = $default;
        if (
            array_key_exists('pm', $parsed)
            && in_array($parsed['pm'], ['static', 'dynamic', 'ondemand'])
        ) {
            $validated['pm'] = $parsed['pm'];
        }
        foreach (
            [
                'pm.max_children',
                'pm.start_servers',
                'pm.min_spare_servers',
                'pm.max_spare_servers',
                'pm.max_requests',
            ] as $key
        ) {
            if (
                array_key_exists($key, $parsed)
                && filter_var($parsed[$key], FILTER_VALIDATE_INT) !== false
            ) {
                $validated[$key] = $parsed[$key];
            }
        }

        $settings = array_merge($parsed, $validated);

        return $settings;
    }

    /**
     * @return array<string,string>
     */
    public function getLsPhpSettings(): array
    {
        $default = [
            'PHP_LSAPI_CHILDREN' => '35',
            'PHP_LSAPI_MAX_REQUESTS' => '5000',
        ];

        $details = $this->getDetails();
        if (
            empty($details['lsphp_settings'])
            || !is_string($details['lsphp_settings'])
        ) {
            return $default;
        }

        $parsed = [];
        $raw = trim($details['lsphp_settings']);
        $lines = explode("\n", $raw);
        foreach ($lines as $line) {
            $line = trim($line);
            $parts = explode("=", $line, 2);
            if (count($parts) < 2) {
                continue;
            }
            $parsed[$parts[0]] = $parts[1];
        }

        $validated = $default;
        foreach (
            [
                'PHP_LSAPI_CHILDREN',
                'PHP_LSAPI_MAX_REQUESTS',
            ] as $key
        ) {
            if (
                array_key_exists($key, $parsed)
                && filter_var($parsed[$key], FILTER_VALIDATE_INT) !== false
            ) {
                $validated[$key] = $parsed[$key];
            }
        }

        $settings = array_merge($parsed, $validated);

        return $settings;
    }

    /**
     * @return array<string,string>
     */
    public function getRedisConfig(): array
    {
        $default = [
            'maxmemory' => '128mb',
            'maxmemory-policy' => 'allkeys-lru',
            'maxmemory-samples' => '5',
            'save' => '""',
            'hz' => '10',
            'timeout' => '0',
            'lazyfree-lazy-eviction' => 'no',
            'lazyfree-lazy-expire' => 'no',
            'activedefrag' => 'no',
            'lfu-log-factor' => '10',
            'lfu-decay-time' => '1',
        ];

        $details = $this->getDetails();
        if (
            empty($details['redis_config'])
            || !is_string($details['redis_config'])
        ) {
            return $default;
        }

        $parsed = [];
        $raw = trim($details['redis_config']);
        $lines = explode("\n", $raw);
        foreach ($lines as $line) {
            $line = trim($line);
            $parts = explode(" ", $line, 2);
            if (count($parts) < 2) {
                continue;
            }
            $parsed[$parts[0]] = $parts[1];
        }

        $allowedKeys = [
            'maxmemory',
            'maxmemory-policy',
            'save',
            'hz',
            'timeout',
            'maxmemory-samples',
            'lazyfree-lazy-eviction',
            'lazyfree-lazy-expire',
            'activedefrag',
            'lfu-decay-time',
            'lfu-log-factor',
        ];

        $allowedMaxmemoryPolicies = [
            'noeviction',
            'allkeys-lru',
            'volatile-lru',
            'allkeys-random',
            'volatile-random',
            'volatile-ttl',
            'allkeys-lfu',
            'volatile-lfu',
        ];

        $validated = $default;
        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $parsed)) {
                continue;
            }
            $value = $parsed[$key];
            if ($key === 'maxmemory-policy') {
                if (in_array($value, $allowedMaxmemoryPolicies)) {
                    $validated[$key] = $value;
                }
                continue;
            }
            if (in_array($key, ['hz', 'timeout', 'maxmemory-samples', 'lfu-decay-time', 'lfu-log-factor'])) {
                if (filter_var($value, FILTER_VALIDATE_INT) !== false) {
                    $validated[$key] = $value;
                }
                continue;
            }
            if (in_array($key, ['lazyfree-lazy-eviction', 'lazyfree-lazy-expire', 'activedefrag'])) {
                if (in_array($value, ['yes', 'no'])) {
                    $validated[$key] = $value;
                }
                continue;
            }
            $validated[$key] = $value;
        }

        return $validated;
    }

    public function dedicatedIpv4Enabled(): bool
    {
        $default = false;
        $details = $this->getDetails();
        if (empty($details['dedicated_ipv4']) || !is_bool($details['dedicated_ipv4'])) {
            return $default;
        }
        return $details['dedicated_ipv4'];
    }

    public function dedicatedIpv6Enabled(): bool
    {
        $default = false;
        $details = $this->getDetails();
        if (empty($details['dedicated_ipv6']) || !is_bool($details['dedicated_ipv6'])) {
            return $default;
        }
        return $details['dedicated_ipv6'];
    }

    /**
     * @return array{
     *   ipv4: list<string>,
     *   ipv6: list<string>,
     * }
     */
    public function getIpAddresses(): array
    {
        $ips = $this->resolveIpAddresses(false);

        // When NAT mode is active, map local IPs to their public counterparts
        // so clients and DNS see the public address.
        if (Ipv4NatMap::isNatModeEnabled()) {
            $localToPublic = Ipv4NatMap::getLocalToPublicMap();
            $ips['ipv4'] = self::mapIps($ips['ipv4'], $localToPublic);
        }

        return $ips;
    }

    /**
     * Returns the IPs that should be used for webserver bind/listen directives.
     * Under NAT mode public IPs are translated back to local IPs. When no NAT
     * maps exist, non-local default IPv4 addresses are filtered out to prevent
     * webserver bind failures on cloud VMs.
     *
     * @return array{
     *   ipv4: array<string>,
     *   ipv6: array<string>,
     * }
     */
    public function getBindIpAddresses(): array
    {
        $ips = $this->resolveIpAddresses(true);

        if (Ipv4NatMap::isNatModeEnabled()) {
            $publicToLocal = Ipv4NatMap::getPublicToLocalMap();
            $ips['ipv4'] = self::mapIps($ips['ipv4'], $publicToLocal);
        }

        return $ips;
    }

    /**
     * @return array{
     *   ipv4: array<string>,
     *   ipv6: array<string>,
     * }
     */
    private function resolveIpAddresses(bool $forBinding): array
    {
        $ips = [
            'ipv4' => [],
            'ipv6' => [],
        ];

        if (!empty(Setting::get('disable-user-ip-assign'))) {
            return $ips;
        }

        $assigned = $this->getAssignedIpAddresses();
        if (!empty($assigned)) {
            foreach ($assigned as $ip) {
                if (filter_var($ip->ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $ips['ipv6'][] = $ip->ip_address;
                    continue;
                }
                $ips['ipv4'][] = $ip->ip_address;
            }
        }

        $defaults = self::defaultIpAddresses();
        if (empty($ips['ipv4'])) {
            $ips['ipv4'] = $defaults['ipv4'];
        }
        if (empty($ips['ipv6'])) {
            $ips['ipv6'] = $defaults['ipv6'];
        }

        return $ips;
    }

    /**
     * The engine's own addresses: what a user with no dedicated IP resolves to.
     *
     * Static and user-independent on purpose. The webserver's main config has
     * to declare these listen addresses from install time, and there are no
     * users then -- deriving them from the user table instead makes them
     * appear only once somebody is hosted, which is a bind address nginx
     * cannot adopt on a reload. {@see AbstractWebserver::getAllIpsVars()}.
     *
     * @return array{
     *   ipv4: array<string>,
     *   ipv6: array<string>,
     * }
     */
    public static function defaultIpAddresses(): array
    {
        $ips = [
            'ipv4' => [],
            'ipv6' => [],
        ];

        if (!empty(Setting::get('disable-user-ip-assign'))) {
            return $ips;
        }

        $defaultIpv4 = Setting::get('default_ipv4');
        if (!empty($defaultIpv4)) {
            $ips['ipv4'][] = $defaultIpv4;
        }

        $defaultIpv6 = Setting::get('default_ipv6');
        if (!empty($defaultIpv6)) {
            $ips['ipv6'][] = $defaultIpv6;
        }

        return $ips;
    }

    /**
     * {@see defaultIpAddresses()} translated for binding, the way
     * {@see getBindIpAddresses()} translates a user's own addresses.
     *
     * @return array{
     *   ipv4: array<string>,
     *   ipv6: array<string>,
     * }
     */
    public static function defaultBindIpAddresses(): array
    {
        $ips = self::defaultIpAddresses();

        if (Ipv4NatMap::isNatModeEnabled()) {
            $ips['ipv4'] = self::mapIps($ips['ipv4'], Ipv4NatMap::getPublicToLocalMap());
        }

        return $ips;
    }

    /**
     * @param array<string> $ips
     * @param array<string, string> $map
     * @return array<string>
     */
    private static function mapIps(array $ips, array $map): array
    {
        $mapped = [];
        foreach ($ips as $ip) {
            $mapped[] = $map[$ip] ?? $ip;
        }
        return array_values(array_unique($mapped));
    }

    private function isBindableIpv4(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        // NAT mode handles public IPs via the mapping table.
        if (Ipv4NatMap::isNatModeEnabled()) {
            return true;
        }

        return $this->isLocalIpv4($ip);
    }

    private function isLocalIpv4(string $ip): bool
    {
        // 127.0.0.0/8 is local but not usable as a default bind address.
        if (strpos($ip, '127.') === 0) {
            return false;
        }

        // 0.0.0.0/8 is invalid.
        if (strpos($ip, '0.') === 0) {
            return false;
        }

        // RFC 1918 private ranges and RFC 6598 CGNAT range are local bindable.
        $privateRanges = [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '100.64.0.0/10',
        ];

        foreach ($privateRanges as $range) {
            if (\Symfony\Component\HttpFoundation\IpUtils::checkIp($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    public function assignFreeDedicatedIpv4(): bool
    {
        /** @var Collection<array-key, IpSubnet> */
        $subnets = IpSubnet::query()
            ->where('family', 4)
            ->where('is_shared', 0)
            ->get();
        /** @var array<IpSubnet> */
        $subnets = $subnets->all();

        /** @var string */
        $defaultIpv4 = Setting::get('default_ipv4');

        foreach ($subnets as $subnet) {
            if ($freeIp = $subnet->findFreeIp([$defaultIpv4])) {
                IpAssigned::create([
                    'user_id' => $this->id,
                    'ip_subnet_id' => $subnet->id,
                    'ip_address' => $freeIp,
                ]);
                return true;
            }
        }

        return false;
    }

    public function assignFreeDedicatedIpv6(): bool
    {
        /** @var Collection<array-key, IpSubnet> */
        $subnets = IpSubnet::query()
            ->where('family', 4)
            ->where('is_shared', 0)
            ->get();
        /** @var array<IpSubnet> */
        $subnets = $subnets->all();

        /** @var string */
        $defaultIpv6 = Setting::get('default_ipv6');

        foreach ($subnets as $subnet) {
            if ($freeIp = $subnet->findFreeIp([$defaultIpv6])) {
                IpAssigned::create([
                    'user_id' => $this->id,
                    'ip_subnet_id' => $subnet->id,
                    'ip_address' => $freeIp,
                ]);
                return true;
            }
        }

        return false;
    }

    public function getTemplate(): ?string
    {
        $details = $this->getDetails();
        if (empty($details['template']) || !is_string($details['template'])) {
            return null;
        }
        return $details['template'];
    }

    public function getGitRepo(): ?string
    {
        $details = $this->getDetails();
        if (empty($details['git_repo']) || !is_string($details['git_repo'])) {
            return null;
        }
        return $details['git_repo'];
    }

    public function getGitRepoOrFail(): string
    {
        $repo = $this->getGitRepo();
        if ($repo) {
            return $repo;
        }
        throw new \Exception("git_repo for user '{$this->username}' not found");
    }

    public function hasGitProject(): bool
    {
        return !empty($this->getGitRepo());
    }

    public function getGitBranch(): ?string
    {
        $details = $this->getDetails();
        if (empty($details['git_branch']) || !is_string($details['git_branch'])) {
            return null;
        }
        $branch = trim($details['git_branch']);

        return $branch !== '' ? $branch : null;
    }

    /**
     * This project's Git token, or the engine's own when it has none.
     *
     * The fallback is what makes a token pasted once work for every project
     * afterwards ({@see GlobalVault}); a project that was given its own still
     * uses that, so nothing set by hand is replaced from underneath, and an
     * engine set to keep tokens per project never reaches for the global at
     * all. {@see getOwnGitToken()} for the project's own, without inheriting.
     */
    public function getGitToken(): ?string
    {
        return $this->getOwnGitToken() ?? GlobalVault::secret(SecretVaultEntry::TYPE_GIT_TOKEN);
    }

    /** This project's own Git token -- null where it inherits the engine's. */
    public function getOwnGitToken(): ?string
    {
        return self::trimmed($this->getDetails()['git_token'] ?? null);
    }

    /** This project's Cloudflare API token, or the engine's own. {@see getGitToken()} */
    public function getCloudflareApiToken(): ?string
    {
        return $this->getOwnCloudflareApiToken() ?? GlobalVault::secret(SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN);
    }

    /** This project's own Cloudflare API token -- null where it inherits the engine's. */
    public function getOwnCloudflareApiToken(): ?string
    {
        return self::trimmed($this->getDetails()['cloudflare_api_token'] ?? null);
    }

    /** A details value that is a non-empty string once trimmed, else null. */
    private static function trimmed(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return ($trimmed = trim($value)) !== '' ? $trimmed : null;
    }

    public function getCloudflareAccountId(): ?string
    {
        $details = $this->getDetails();
        if (empty($details['cloudflare_account_id']) || !is_string($details['cloudflare_account_id'])) {
            return null;
        }
        $id = trim($details['cloudflare_account_id']);

        return $id !== '' ? $id : null;
    }

    public function getCloudflareTunnelId(): ?string
    {
        $details = $this->getDetails();
        if (empty($details['cloudflare_tunnel_id']) || !is_string($details['cloudflare_tunnel_id'])) {
            return null;
        }
        $id = trim($details['cloudflare_tunnel_id']);

        return $id !== '' ? $id : null;
    }

    public function getCloudflareTunnelToken(): ?string
    {
        $details = $this->getDetails();
        if (empty($details['cloudflare_tunnel_token']) || !is_string($details['cloudflare_tunnel_token'])) {
            return null;
        }
        $token = trim($details['cloudflare_tunnel_token']);

        return $token !== '' ? $token : null;
    }

    /**
     * @return array{repo_url: string, branch: string, token: ?string}|null
     */
    public function getSiteGit(string $pathKey): ?array
    {
        $details = $this->getDetails();
        $entry = $details['site_git'][$pathKey] ?? null;
        if (is_array($entry)) {
            return [
                'repo_url' => is_string($entry['repo_url'] ?? null) ? $entry['repo_url'] : '',
                'branch' => is_string($entry['branch'] ?? null) ? $entry['branch'] : '',
                'token' => isset($entry['token']) && is_string($entry['token']) && $entry['token'] !== ''
                    ? $entry['token'] : null,
            ];
        }

        // Deploy accounts store the remote on git_repo; surface it as site_git
        // for the default DinD checkout so status reports connected without a
        // separate POST /git/connect.
        if ($pathKey === 'project') {
            $repo = $this->getGitRepo();
            if ($repo !== null && $repo !== '') {
                return [
                    'repo_url' => $repo,
                    'branch' => $this->getGitBranch() ?? '',
                    'token' => $this->getGitToken(),
                ];
            }
        }

        return null;
    }

    /**
     * @param array{repo_url: string, branch: string, token: ?string} $entry
     */
    public function putSiteGit(string $pathKey, array $entry): void
    {
        $details = $this->getDetails();
        $site = $details['site_git'] ?? [];
        if (!is_array($site)) {
            $site = [];
        }
        $site[$pathKey] = $entry;
        $this->setDetails(['site_git' => $site]);
        if ($this->exists) {
            $this->save();
        }
    }

    public function forgetSiteGit(string $pathKey): void
    {
        $details = $this->getDetails();
        $site = $details['site_git'] ?? [];
        if (!is_array($site) || !array_key_exists($pathKey, $site)) {
            return;
        }
        unset($site[$pathKey]);
        $this->setDetails(['site_git' => $site]);
        if ($this->exists) {
            $this->save();
        }
    }

    public function getDeployStrategy(): ?string
    {
        $details = $this->getDetails();
        if (empty($details['deploy_strategy']) || !is_string($details['deploy_strategy'])) {
            return null;
        }

        return $details['deploy_strategy'];
    }

    /**
     * The image the last deploy resolved for this project, e.g.
     * `golang:1.27-alpine`.
     *
     * Frozen with the rest of the snapshot because the version comes from the
     * project -- go.mod, pom.xml, composer.json -- and the prewarm catalogue
     * is a fixed list. Without it the launcher can only guess from the
     * strategy, which is how a Go project resolved to 1.27 and had 1.22 seeded
     * for it.
     */
    public function getDeployImage(): ?string
    {
        $details = $this->getDetails();
        if (empty($details['deploy_image']) || !is_string($details['deploy_image'])) {
            return null;
        }

        return $details['deploy_image'];
    }

    public function getDeployRuntime(): ?string
    {
        $details = $this->getDetails();
        if (empty($details['deploy_runtime']) || !is_string($details['deploy_runtime'])) {
            return null;
        }

        return $details['deploy_runtime'];
    }

    public function getGitCommit(): ?string
    {
        $details = $this->getDetails();
        if (empty($details['git_commit']) || !is_string($details['git_commit'])) {
            return null;
        }
        $commit = trim($details['git_commit']);

        return $commit !== '' ? $commit : null;
    }

    /**
     * User-supplied environment variable overrides for the next deploy/rebuild.
     *
     * @return array<string, string>
     */
    public function getEnvVars(): array
    {
        $details = $this->getDetails();
        if (empty($details['env_vars']) || !is_array($details['env_vars'])) {
            return [];
        }
        $result = [];
        foreach ($details['env_vars'] as $key => $value) {
            if (!is_string($key) || $key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }
            $result[$key] = (string) $value;
        }

        return $result;
    }

    public function usedCustomEnvVars(): bool
    {
        $details = $this->getDetails();

        return !empty($details['used_custom_env_vars']);
    }

    public function getAppPort(): ?int
    {
        $details = $this->getDetails();
        if (array_key_exists('app_port', $details)) {
            if ($details['app_port'] === null) {
                return null;
            }
            return (int)$details['app_port'];
        }
        return null;
    }

    public function setAppPort(?int $value): void
    {
        $this->setDetails(['app_port' => $value]);
    }

    public function getDeploymentStatus(): string
    {
        $details = $this->getDetails();
        if (
            !empty($details['deployment_status'])
            && is_string($details['deployment_status'])
        ) {
            return $details['deployment_status'];
        }
        return 'unknown';
    }

    public function getDeploymentWarnings(): array
    {
        $details = $this->getDetails();
        if (
            !empty($details['deployment_warnings'])
            && is_array($details['deployment_warnings'])
        ) {
            return $details['deployment_warnings'];
        }
        return [];
    }

    public function hasDeploymentWarnings(): bool
    {
        return !empty($this->getDeploymentWarnings());
    }

    public function delete()
    {
        $this->assignedIpAddresses()->delete();
        parent::delete();
    }
}
