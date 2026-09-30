<?php

namespace App\Models;

use App\Lib\Host\ProjectMemory;
use App\Lib\Limits\ResourceLimit;
use App\Lib\Project\NewProjectDetails;
use App\Lib\Project\ProjectIpAddresses;
use App\Lib\Project\RuntimeSettings;
use App\System\Project as AppSystemProject;
use App\System\Project\Dind\AppDatabase;
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

    /** Details stored as one encrypted JSON document each. */
    private const ENCRYPTED_JSON_DETAILS = ['env_vars', 'app_credentials'];

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

        return array_merge(
            NewProjectDetails::forCopy($destUsername, $srcDetails),
            self::copiedDeploySnapshot($srcDetails),
            $extra
        );
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
        if (isset($details['site_password_hash']) && is_string($details['site_password_hash'])) {
            $details['site_password_hash'] = $this->decryptSecretString($details['site_password_hash']);
        }
        if (isset($details[AppDatabase::PASSWORD_DETAIL]) && is_string($details[AppDatabase::PASSWORD_DETAIL])) {
            $details[AppDatabase::PASSWORD_DETAIL] = $this->decryptSecretString($details[AppDatabase::PASSWORD_DETAIL]);
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
        foreach (self::ENCRYPTED_JSON_DETAILS as $key) {
            if (isset($details[$key]) && is_string($details[$key])) {
                $decoded = $this->decryptSecretString($details[$key]);
                $decoded = $decoded !== null ? json_decode($decoded, true) : null;
                $details[$key] = is_array($decoded) ? $decoded : [];
            }
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
        if (isset($details['site_password_hash']) && is_string($details['site_password_hash']) && $details['site_password_hash'] !== '') {
            $details['site_password_hash'] = $this->encryptSecretString($details['site_password_hash']);
        }
        $dbPassword = $details[AppDatabase::PASSWORD_DETAIL] ?? null;
        if ($dbPassword === null && array_key_exists(AppDatabase::PASSWORD_DETAIL, $details)
            && $this->hasUnreadableSecret(AppDatabase::PASSWORD_DETAIL)) {
            // Null here is an undecryptable read written back; keep the ciphertext,
            // the app's own config still holds that password.
            $dbPassword = $this->storedEncryptedDetail(AppDatabase::PASSWORD_DETAIL);
            $details[AppDatabase::PASSWORD_DETAIL] = $dbPassword;
        }
        if (is_string($dbPassword) && $dbPassword !== '') {
            $details[AppDatabase::PASSWORD_DETAIL] = $this->encryptSecretString($dbPassword);
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
        foreach (self::ENCRYPTED_JSON_DETAILS as $key) {
            if (isset($details[$key]) && is_array($details[$key])) {
                $details[$key] = $this->encryptSecretString(
                    (string) json_encode($details[$key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }
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
     * True when the detail is stored encrypted but cannot be decrypted, as
     * opposed to never having been stored at all.
     */
    public function hasUnreadableSecret(string $key): bool
    {
        $stored = $this->storedEncryptedDetail($key);

        return $stored !== null && $this->decryptSecretString($stored) === null;
    }

    /** The raw, still-encrypted value of a top-level detail, if it is one. */
    private function storedEncryptedDetail(string $key): ?string
    {
        $raw = $this->attributes['details'] ?? null;
        $stored = is_string($raw) ? json_decode($raw, true) : null;
        $value = is_array($stored) ? ($stored[$key] ?? null) : null;

        return is_string($value) && str_starts_with($value, self::ENCRYPTED_SECRET_PREFIX) ? $value : null;
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

    public function deployHooks(): HasMany
    {
        return $this->hasMany(DeployHook::class);
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

    /** The stored value for one limit, cast, or null when it is unset. */
    public function limit(ResourceLimit $limit): int|float|null
    {
        return $limit->read($this->getDetails());
    }

    public function setLimit(ResourceLimit $limit, int|float|null $value): void
    {
        $this->setDetails([$limit->key => $value]);
    }

    public function getDiskSpaceLimit(): ?int
    {
        return $this->limit(ResourceLimit::byKey('disk_space_limit'));
    }

    public function setDiskSpaceLimit(?int $value): void
    {
        $this->setLimit(ResourceLimit::byKey('disk_space_limit'), $value);
    }

    public function getCpuLimit(): ?float
    {
        return $this->limit(ResourceLimit::byKey('cpu_limit'));
    }

    public function setCpuLimit(?float $value): void
    {
        $this->setLimit(ResourceLimit::byKey('cpu_limit'), $value);
    }

    public function getDeviceReadBps(): ?int
    {
        return $this->limit(ResourceLimit::byKey('device_read_bps'));
    }

    public function setDeviceReadBps(?int $value): void
    {
        $this->setLimit(ResourceLimit::byKey('device_read_bps'), $value);
    }

    public function getDeviceWriteBps(): ?int
    {
        return $this->limit(ResourceLimit::byKey('device_write_bps'));
    }

    public function setDeviceWriteBps(?int $value): void
    {
        $this->setLimit(ResourceLimit::byKey('device_write_bps'), $value);
    }

    public function getBandwidthLimit(): ?int
    {
        return $this->limit(ResourceLimit::byKey('bandwidth_limit'));
    }

    public function setBandwidthLimit(?int $value): void
    {
        $this->setLimit(ResourceLimit::byKey('bandwidth_limit'), $value);
    }

    public function getMysqlDatabasesLimit(): ?int
    {
        return $this->limit(ResourceLimit::byKey('mysql_databases_limit'));
    }

    public function setMysqlDatabasesLimit(?int $value): void
    {
        $this->setLimit(ResourceLimit::byKey('mysql_databases_limit'), $value);
    }

    public function getFtpAccountsLimit(): ?int
    {
        return $this->limit(ResourceLimit::byKey('ftp_accounts_limit'));
    }

    public function setFtpAccountsLimit(?int $value): void
    {
        $this->setLimit(ResourceLimit::byKey('ftp_accounts_limit'), $value);
    }

    public function getSftpAccountsLimit(): ?int
    {
        return $this->limit(ResourceLimit::byKey('sftp_accounts_limit'));
    }

    public function setSftpAccountsLimit(?int $value): void
    {
        $this->setLimit(ResourceLimit::byKey('sftp_accounts_limit'), $value);
    }

    public function getAddonDomainsLimit(): ?int
    {
        return $this->limit(ResourceLimit::byKey('addon_domains_limit'));
    }

    public function setAddonDomainsLimit(?int $value): void
    {
        $this->setLimit(ResourceLimit::byKey('addon_domains_limit'), $value);
    }

    // 'subdomains_limit' => 'integer|nullable',
    public function getSubdomainsLimit(): ?int
    {
        return $this->limit(ResourceLimit::byKey('subdomains_limit'));
    }

    public function setSubdomainsLimit(?int $value): void
    {
        $this->setLimit(ResourceLimit::byKey('subdomains_limit'), $value);
    }

    public function getInodesLimit(): ?int
    {
        return $this->limit(ResourceLimit::byKey('inodes_limit'));
    }

    public function setInodesLimit(?int $value): void
    {
        $this->setLimit(ResourceLimit::byKey('inodes_limit'), $value);
    }

    public function getMemoryLimit(): ?int
    {
        return $this->limit(ResourceLimit::byKey('memory_limit'));
    }

    public function setMemoryLimit(?int $value): void
    {
        $this->setLimit(ResourceLimit::byKey('memory_limit'), $value);
    }

    /**
     * The limit the account runs with: its own, or the default for a project
     * made before every project had one (#294).
     */
    public function effectiveMemoryLimit(): int
    {
        return ProjectMemory::resolve($this->getMemoryLimit());
    }

    /** The value a limit that cannot be unset falls back to when it is unset. */
    public function effectiveLimit(ResourceLimit $limit): int|float|null
    {
        return $limit->alwaysApplies ? $this->effectiveMemoryLimit() : $this->limit($limit);
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
        return RuntimeSettings::phpFpmPool($this->getDetails()['php_fpm_pool_settings'] ?? null);
    }

    /**
     * @return array<string,string>
     */
    public function getLsPhpSettings(): array
    {
        return RuntimeSettings::lsphp($this->getDetails()['lsphp_settings'] ?? null);
    }

    // The vhost's lsphp maxConns must equal the plan's PHP_LSAPI_CHILDREN;
    // more connections than children queue inside lsphp and requests hang.
    public function getLsPhpMaxConns(): int
    {
        return max(1, (int) $this->getLsPhpSettings()['PHP_LSAPI_CHILDREN']);
    }

    /**
     * @return array<string,string>
     */
    public function getRedisConfig(): array
    {
        return RuntimeSettings::redis($this->getDetails()['redis_config'] ?? null);
    }

    public function ipAddresses(): ProjectIpAddresses
    {
        return new ProjectIpAddresses($this);
    }

    public function dedicatedIpv4Enabled(): bool
    {
        return $this->ipAddresses()->dedicatedIpv4Enabled();
    }

    public function dedicatedIpv6Enabled(): bool
    {
        return $this->ipAddresses()->dedicatedIpv6Enabled();
    }

    /**
     * @return array{
     *   ipv4: list<string>,
     *   ipv6: list<string>,
     * }
     */
    public function getIpAddresses(): array
    {
        return $this->ipAddresses()->getIpAddresses();
    }

    /**
     * @return array{
     *   ipv4: array<string>,
     *   ipv6: array<string>,
     * }
     */
    public function getBindIpAddresses(): array
    {
        return $this->ipAddresses()->getBindIpAddresses();
    }

    /**
     * @return array{
     *   ipv4: array<string>,
     *   ipv6: array<string>,
     * }
     */
    public static function defaultIpAddresses(): array
    {
        return ProjectIpAddresses::defaultIpAddresses();
    }

    /**
     * @return array{
     *   ipv4: array<string>,
     *   ipv6: array<string>,
     * }
     */
    public static function defaultBindIpAddresses(): array
    {
        return ProjectIpAddresses::defaultBindIpAddresses();
    }

    public function assignFreeDedicatedIpv4(): bool
    {
        return $this->ipAddresses()->assignFreeDedicatedIpv4();
    }

    public function assignFreeDedicatedIpv6(): bool
    {
        return $this->ipAddresses()->assignFreeDedicatedIpv6();
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

    /** The Git token this project clones with, or null for an anonymous clone. */
    public function getGitToken(): ?string
    {
        return self::trimmed($this->getDetails()['git_token'] ?? null);
    }

    /** This project's Cloudflare API token, or null. */
    public function getCloudflareApiToken(): ?string
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

    /**
     * The application login the engine generated and delivers on every
     * deploy, as {@see \App\Lib\Deploy\Credentials\AppCredentials} stores it.
     *
     * @return ?array<string, mixed>
     */
    public function getAppCredentials(): ?array
    {
        $stored = $this->getDetails()['app_credentials'] ?? null;

        return is_array($stored) && $stored !== [] ? $stored : null;
    }

    /** @param ?array<string, mixed> $stored null forgets them */
    public function setAppCredentials(?array $stored): void
    {
        $this->setDetails(['app_credentials' => $stored]);
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

    /**
     * Record a clean deploy: the status and an empty warnings list, together.
     * The API reports deployment_warnings as a list once a deploy has finished,
     * and setDetails() merges, so a list left by an earlier partial run has to
     * be overwritten rather than left in place. Does not save.
     */
    public function markDeploySucceeded(): void
    {
        $this->setDetails([
            'deployment_status' => 'success',
            'deployment_warnings' => [],
        ]);
    }

    public function delete()
    {
        $this->assignedIpAddresses()->delete();
        parent::delete();
    }
}
