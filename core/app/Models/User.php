<?php

namespace App\Models;

use App\Exceptions\MissingLinuxUserException;
use App\Exceptions\NotFoundException;
use App\Lib\Deploy\Credentials\AppCredentials;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Domains\CopyDomain;
use App\Lib\Domains\DomainPlan;
use App\Lib\Host\ProjectMemory;
use App\Lib\Limits\ResourceLimit;
use App\Lib\Project\NewProjectDetails;
use App\Lib\Project\ProjectIpAddresses;
use App\Lib\Project\RuntimeSettings;
use App\Lib\Vault\RequestVault;
use App\System;
use App\System\Project as AppSystemProject;
use App\System\Project\Dind\AppDatabase;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
 *
 * @psalm-property ?UserDetails $details
 *
 * @psalm-type UserDetails = array{
 *   mysql_prefix?: string,
 *   ...
 * }
 *
 * @method static ?User find(int $id)
 * @method static User findOrFail(int $id)
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    private const ENCRYPTED_SECRET_PREFIX = 'laravel-encrypted:v1:';

    /** Details stored as one encrypted JSON document each. */
    private const ENCRYPTED_JSON_DETAILS = ['env_vars', 'app_credentials', 'git_deploy_key'];

    /** Top-level details stored as one encrypted string each. */
    private const SECRET_STRING_DETAILS = [
        'git_token',
        'cloudflare_api_token',
        'cloudflare_tunnel_token',
        'registry_auth',
        'site_password_hash',
        AppDatabase::PASSWORD_DETAIL,
    ];

    /** Top-level details encrypted at rest; site_git tokens are per entry. */
    private const SECRET_DETAILS = [...self::SECRET_STRING_DETAILS, 'env_vars', 'app_credentials'];

    /** The frozen deploy fields a copied project starts from. */
    private const COPIED_DEPLOY_KEYS = [
        'deploy_source',
        'deploy_strategy',
        'deploy_label',
        'deploy_port',
        'deploy_runtime',
        'deploy_platform',
        'deploy_checks_dir',
        'deploy_image',
        'deploy_start_period',
        'deploy_admin_sso',
        'git_commit',
        'git_branch',
    ];

    /**
     * What a project's env files are written from, which keys its last deploy
     * merged into `.env`, and which values a vault reference set.
     */
    private const COPIED_ENV_KEYS = ['env_vars', 'used_custom_env_vars', 'env_override_keys', self::DETAIL_VAULT_ENV_KEYS];

    /** What a project can do about its own unreadable secrets; the APP_KEY is a server matter. */
    public const UNREADABLE_SECRETS_REMEDY = 'Set each one again: env_vars, git_update_credentials for a Git '
        .'token, the cloudflare-api-token or registry-auth setting, or the site password.';

    /** The `details` key of the `protected` project setting. */
    public const string DETAIL_PROTECTED = 'protected';

    /** The only value that key stores; absent means the project is not protected. */
    public const string PROTECTED_ON = 'on';

    /** The `details` key listing the env vars a vault reference set. */
    public const string DETAIL_VAULT_ENV_KEYS = 'env_vault_keys';

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
     * @param  array<string, mixed>  $patch
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
     * @param  array<string, mixed>  $sourceDetails  from getDetails()
     * @return array<string, mixed>
     */
    public static function copiedDeploySnapshot(array $sourceDetails): array
    {
        $out = [];
        foreach (self::COPIED_DEPLOY_KEYS as $key) {
            if (array_key_exists($key, $sourceDetails)) {
                $out[$key] = $sourceDetails[$key];
            }
        }

        return $out;
    }

    /**
     * The env details for a project the source's files were copied into, so
     * its next rebuild writes the env files it was copied with. A key the
     * source lacks is cleared: the copied files carry none of it.
     *
     * @param  array<string, mixed>  $sourceDetails  from getDetails()
     * @return array<string, mixed>
     */
    public static function copiedEnvDetails(array $sourceDetails): array
    {
        $out = [];
        foreach (self::COPIED_ENV_KEYS as $key) {
            $out[$key] = $sourceDetails[$key] ?? null;
        }

        return $out;
    }

    /**
     * Resource limits plus the frozen deploy snapshot for a copied project.
     *
     * @param  array<string, mixed>  $extra
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

    /**
     * The copied deploy fields as they were before applyDeploySnapshotFrom(): one the project did not have then is removed.
     *
     * @param  array<string, mixed>  $snapshot  {@see copiedDeploySnapshot()} of its details then
     */
    public function restoreDeploySnapshot(array $snapshot): void
    {
        $this->details = array_merge(
            array_diff_key($this->getDetails(), array_flip(self::COPIED_DEPLOY_KEYS)),
            $snapshot,
        );
    }

    /** Call once every check on the copy passed: a panelalpha.online name is bought here. */
    public function makePendingStaging(string $destUsername, CopyDomain $copyDomain, string $source = 'api'): self
    {
        $newDetails = $this->detailsForCopiedProject($destUsername, [
            'async_status' => [
                'staging' => 'running',
                'source' => $source,
            ],
        ]);

        return self::createCopy($destUsername, $copyDomain, [
            'email' => $this->email,
            'status' => 'pending',
            'staging' => $this->id,
            'details' => $newDetails,
        ]);
    }

    /** Call once every check on the clone passed: a panelalpha.online name is bought here. */
    public function makeClone(string $destUsername, CopyDomain $copyDomain): self
    {
        return self::createCopy($destUsername, $copyDomain, [
            'email' => $this->email,
            'details' => $this->detailsForCopiedProject($destUsername),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function createCopy(string $destUsername, CopyDomain $copyDomain, array $attributes): self
    {
        $destDomain = $copyDomain->claim($destUsername);
        try {
            return self::saveCopy($destUsername, $destDomain, $copyDomain, $attributes);
        } catch (\Throwable $failure) {
            $copyDomain->release();
            throw $failure;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function saveCopy(string $destUsername, string $destDomain, CopyDomain $copyDomain, array $attributes): self
    {
        $dest = new self(['username' => $destUsername, 'domain' => $destDomain] + $attributes);
        $dest->save();
        self::createMainDomain($dest, $destDomain);
        $copyDomain->attachTo($dest);

        return $dest;
    }

    /** A copy's main domain, with a www alias when it would answer and no project holds that name yet. */
    private static function createMainDomain(self $dest, string $destDomain): void
    {
        $wwwAliasAvailable = DomainPlan::wwwAliasWouldAnswer($destDomain)
            && ! Domain::domainOrAliasExists('www.'.$destDomain);
        $domainAliases = $wwwAliasAvailable ? ['www.'.$destDomain] : [];

        $domainModel = Domain::make([
            'user_id' => $dest->id,
            'domain' => $destDomain,
            'type' => 'main',
            'details' => [
                'document_root' => "/{$destDomain}/public_html",
                'redirect_enabled' => false,
                'redirect_url' => null,
                'aliases' => $domainAliases,
            ],
        ]);
        $domainModel->save();
    }

    public static function findByUsernameOrFail(string $username): User
    {
        return self::findByUsername($username)
            ?? throw new NotFoundException("Project '{$username}' not found.", self::class);
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
        if (! is_array($details)) {
            return [];
        }

        $details = $this->transformSecretStrings($details, $this->decryptSecretString(...));

        return $this->decryptJsonDetails($details);
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

        $details = $this->keepUnreadableAppDatabasePassword($details);
        $details = $this->transformSecretStrings($details, $this->encryptSecretString(...));

        $this->attributes['details'] = json_encode($this->encryptJsonDetails($details));
    }

    /**
     * Apply $transform to every non-empty secret string: the top-level ones, then each site_git token.
     *
     * @param  array<string, mixed>  $details
     * @param  callable(string): ?string  $transform
     * @return array<string, mixed>
     */
    private function transformSecretStrings(array $details, callable $transform): array
    {
        foreach (self::SECRET_STRING_DETAILS as $key) {
            if (self::isNonEmptyString($details[$key] ?? null)) {
                $details[$key] = $transform($details[$key]);
            }
        }
        if (! isset($details['site_git']) || ! is_array($details['site_git'])) {
            return $details;
        }
        foreach ($details['site_git'] as $path => $entry) {
            if (is_array($entry) && self::isNonEmptyString($entry['token'] ?? null)) {
                $details['site_git'][$path]['token'] = $transform($entry['token']);
            }
        }

        return $details;
    }

    private static function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    /**
     * An app database password read back as null because it could not be decrypted
     * keeps its ciphertext: the app's own config still holds that password.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function keepUnreadableAppDatabasePassword(array $details): array
    {
        $key = AppDatabase::PASSWORD_DETAIL;
        if (array_key_exists($key, $details) && $details[$key] === null && $this->hasUnreadableSecret($key)) {
            $details[$key] = $this->storedEncryptedDetail($key);
        }

        return $details;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function decryptJsonDetails(array $details): array
    {
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
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function encryptJsonDetails(array $details): array
    {
        foreach (self::ENCRYPTED_JSON_DETAILS as $key) {
            if (isset($details[$key]) && is_array($details[$key])) {
                $details[$key] = $this->encryptSecretString(
                    (string) json_encode($details[$key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }
        }

        return $details;
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

        return self::ENCRYPTED_SECRET_PREFIX.Crypt::encryptString($value);
    }

    /**
     * True when the detail is stored encrypted but cannot be decrypted, as
     * opposed to never having been stored at all. A nested detail takes its
     * path: ('site_git', 'project', 'token').
     */
    public function hasUnreadableSecret(string ...$path): bool
    {
        $stored = $this->storedEncryptedDetail(...$path);

        return $stored !== null && $this->decryptSecretString($stored) === null;
    }

    /**
     * Stored secrets that cannot be decrypted, by name; a site_git token as
     * `site_git.<path>.token`.
     *
     * @return list<string>
     */
    public function unreadableSecrets(): array
    {
        $names = array_values(array_filter(self::SECRET_DETAILS, fn (string $key): bool => $this->hasUnreadableSecret($key)));
        $siteGit = $this->storedDetails()['site_git'] ?? null;
        foreach (is_array($siteGit) ? array_keys($siteGit) : [] as $path) {
            if ($this->hasUnreadableSecret('site_git', (string) $path, 'token')) {
                $names[] = "site_git.{$path}.token";
            }
        }

        return $names;
    }

    /**
     * Names what cannot be decoded and what saving does to it, or null. Says nothing of the APP_KEY.
     * The app database password is the exception: AppDatabase keeps it and refuses to deploy without it.
     */
    public function unreadableSecretsWarning(): ?string
    {
        $names = $this->unreadableSecrets();
        if ($names === []) {
            return null;
        }

        $warning = 'Stored secrets cannot be decoded: '.implode(', ', $names).'. They read as empty, and '
            .'the next save of this project, a redeploy included, stores them empty. '
            .self::UNREADABLE_SECRETS_REMEDY;
        if (in_array(AppDatabase::PASSWORD_DETAIL, $names, true)) {
            $warning .= ' The app database password is kept, and a deploy refuses while it cannot be decoded: '
                .'a new one would lock the app out of its database.';
        }

        return $warning;
    }

    /** The raw, still-encrypted value of a detail, if it is one. */
    private function storedEncryptedDetail(string ...$path): ?string
    {
        $value = $this->storedDetails();
        foreach ($path as $segment) {
            $value = is_array($value) ? ($value[$segment] ?? null) : null;
        }

        return is_string($value) && str_starts_with($value, self::ENCRYPTED_SECRET_PREFIX) ? $value : null;
    }

    /** The details document as stored, secrets still encrypted, or null when there is none. */
    private function storedDetails(): mixed
    {
        $raw = $this->attributes['details'] ?? null;

        return is_string($raw) ? json_decode($raw, true) : null;
    }

    /**
     * Null means the ciphertext could not be read — almost always a rotated
     * APP_KEY. Logged loudly: silently dropping a git token or a set of env
     * vars turns into a deploy that fails for reasons nobody can trace.
     */
    private function decryptSecretString(string $value): ?string
    {
        if (! str_starts_with($value, self::ENCRYPTED_SECRET_PREFIX)) {
            return $value;
        }

        try {
            return Crypt::decryptString(substr($value, strlen(self::ENCRYPTED_SECRET_PREFIX)));
        } catch (\Throwable $e) {
            Log::warning(
                "Could not decrypt stored secret for user '{$this->username}' "
                    .'(APP_KEY rotated?): '.$e->getMessage()
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
        return $this->withMysqlPrefix($name);
    }

    public function qualifyMysqlDatabase(string $name): string
    {
        return $this->withMysqlPrefix($name);
    }

    private function withMysqlPrefix(string $name): string
    {
        $prefix = $this->getMysqlPrefix();

        return Str::startsWith($name, $prefix) ? $name : $prefix.$name;
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

    /** The domain whose document root contains $path, matched by the longest root. */
    public function findDomainByDocumentRoot(string $path): ?Domain
    {
        $target = $this->accountRelativePath($path);
        if ($target === null) {
            return null;
        }

        return $this->mostSpecificDomain($this->domains()->get(), $target);
    }

    private function mostSpecificDomain(Collection $domains, string $target): ?Domain
    {
        $match = null;
        $matchLength = 0;
        foreach ($domains as $domain) {
            $root = $this->accountRelativePath($domain->getDocumentRoot());
            if ($root === null || ! $this->rootContains($root, $target)) {
                continue;
            }
            if (strlen($root) > $matchLength) {
                $match = $domain;
                $matchLength = strlen($root);
            }
        }

        return $match;
    }

    /** A trimmed path with the home prefix and trailing slashes removed. */
    private function accountRelativePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $home = rtrim($this->getHomeDir(), '/');
        if ($home !== '' && ($path === $home || str_starts_with($path, $home.'/'))) {
            $path = substr($path, strlen($home));
        }

        $path = '/'.ltrim($path, '/');

        return $path === '/' ? $path : rtrim($path, '/');
    }

    private function rootContains(string $root, string $path): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/').'/');
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
     * @param  array<IpAssigned>  $ips
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

    public function project(?System $system = null): AppSystemProject
    {
        return ($system ?? new System)->project($this);
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
     * made before every project had one.
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
        if (! ResourceLimit::isUnlimited($limit) && $ftpAccountQuota > $limit) {
            return $limit;
        }

        return $ftpAccountQuota;
    }

    /** A legacy `/var/www` home resolves to the project's own home path. */
    public function getHomeDir(): string
    {
        /** @var mixed */
        $homeDir = $this->getDetails()['home_dir'] ?? null;
        if ($homeDir === '/var/www') {
            return $this->project()->homeDirPath();
        }
        if (! empty($homeDir) && is_string($homeDir)) {
            return $homeDir;
        }

        return "/home/{$this->username}";
    }

    public function getUid(): ?int
    {
        return $this->intDetail('UID');
    }

    public function getGid(): ?int
    {
        return $this->intDetail('GID');
    }

    /**
     * The account's own UID and GID, never a fallback.
     *
     * @return array{int, int}
     *
     * @throws MissingLinuxUserException
     */
    public function linuxIds(): array
    {
        $uid = $this->getUid();
        $gid = $this->getGid();
        if ($uid === null || $gid === null) {
            throw MissingLinuxUserException::for((string) $this->username);
        }

        return [$uid, $gid];
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
        return [
            'home_dir' => $this->getHomeDir(),
            'mysql_prefix' => $this->getMysqlPrefix(),
            'disk_space_limit' => $this->getDiskSpaceLimit(),
            'memory_limit' => $this->getMemoryLimit(),
            'cpu_limit' => $this->getCpuLimit(),
            'device_read_bps' => $this->getDeviceReadBps(),
            'device_write_bps' => $this->getDeviceWriteBps(),
            'bandwidth_limit' => $this->getBandwidthLimit(),
            'mysql_databases_limit' => $this->getMysqlDatabasesLimit(),
            'ftp_accounts_limit' => $this->getFtpAccountsLimit(),
            'sftp_accounts_limit' => $this->getSftpAccountsLimit(),
            'addon_domains_limit' => $this->getAddonDomainsLimit(),
            'subdomains_limit' => $this->getSubdomainsLimit(),
            'inodes_limit' => $this->getInodesLimit(),
            'php_fpm_pool_settings' => $this->getPhpFpmPoolSettings(),
            'lsphp_settings' => $this->getLsPhpSettings(),
            'redis_config' => $this->getRedisConfig(),
            'dedicated_ipv4' => $this->dedicatedIpv4Enabled(),
            'dedicated_ipv6' => $this->dedicatedIpv6Enabled(),
            'ip_addresses' => $this->getIpAddresses(),
            'UID' => $this->getUid(),
            'GID' => $this->getGid(),
        ];
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

    /**
     * The vhost's lsphp maxConns must equal the plan's PHP_LSAPI_CHILDREN;
     * more connections than children queue inside lsphp and requests hang.
     */
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
        return $this->stringDetail('template');
    }

    public function getGitRepo(): ?string
    {
        return $this->stringDetail('git_repo');
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
        return ! empty($this->getGitRepo());
    }

    public function getGitBranch(): ?string
    {
        return self::trimmed($this->stringDetail('git_branch'));
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

    /** This project's private registry logins, the `registry-auth` setting as stored, or null. */
    public function getRegistryAuth(): ?string
    {
        return self::trimmed($this->getDetails()['registry_auth'] ?? null);
    }

    /**
     * Container paths kept on named volumes across deploys: the `persist-paths` setting.
     *
     * @return list<string>
     */
    public function getPersistPaths(): array
    {
        $paths = $this->getDetails()['persist_paths'] ?? null;

        return is_array($paths) ? array_values(array_filter($paths, 'is_string')) : [];
    }

    /**
     * Whether the `protected` setting is on: API and MCP tokens may no longer
     * delete, stop or redeploy this project.
     */
    public function isProtected(): bool
    {
        return ($this->getDetails()[self::DETAIL_PROTECTED] ?? null) === self::PROTECTED_ON;
    }

    /** A details value that is a non-empty string once trimmed, else null. */
    private static function trimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return ($trimmed = trim($value)) !== '' ? $trimmed : null;
    }

    /** A details value that is a string and not empty() (so not '' nor '0'), else null. */
    private function stringDetail(string $key): ?string
    {
        $value = $this->getDetails()[$key] ?? null;

        return ! empty($value) && is_string($value) ? $value : null;
    }

    /** A details value that is a non-zero int, else null. */
    private function intDetail(string $key): ?int
    {
        $value = $this->getDetails()[$key] ?? null;

        return ! empty($value) && is_int($value) ? $value : null;
    }

    public function getCloudflareAccountId(): ?string
    {
        return self::trimmed($this->stringDetail('cloudflare_account_id'));
    }

    public function getCloudflareTunnelId(): ?string
    {
        return self::trimmed($this->stringDetail('cloudflare_tunnel_id'));
    }

    public function getCloudflareTunnelToken(): ?string
    {
        return self::trimmed($this->stringDetail('cloudflare_tunnel_token'));
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

        return $pathKey === 'project' ? $this->deployRepoAsSiteGit() : null;
    }

    /**
     * A deploy account stores its remote on git_repo; surfaced as the default checkout's
     * site_git, status reports it connected without a separate POST /git/connect.
     *
     * @return array{repo_url: string, branch: string, token: ?string}|null
     */
    private function deployRepoAsSiteGit(): ?array
    {
        $repo = $this->getGitRepo();
        if ($repo === null || $repo === '') {
            return null;
        }

        return [
            'repo_url' => $repo,
            'branch' => $this->getGitBranch() ?? '',
            'token' => $this->getGitToken(),
        ];
    }

    /**
     * @param  array{repo_url: string, branch: string, token: ?string}  $entry
     */
    public function putSiteGit(string $pathKey, array $entry): void
    {
        $site = $this->siteGitEntries();
        $site[$pathKey] = $entry;
        $this->storeSiteGit($site);
    }

    public function forgetSiteGit(string $pathKey): void
    {
        $site = $this->siteGitEntries();
        if (! array_key_exists($pathKey, $site)) {
            return;
        }
        unset($site[$pathKey]);
        $this->storeSiteGit($site);
    }

    /** @return array<array-key, mixed> */
    private function siteGitEntries(): array
    {
        $site = $this->getDetails()['site_git'] ?? [];

        return is_array($site) ? $site : [];
    }

    /**
     * Saves only a project that already exists.
     *
     * @param  array<array-key, mixed>  $site
     */
    private function storeSiteGit(array $site): void
    {
        $this->setDetails(['site_git' => $site]);
        if ($this->exists) {
            $this->save();
        }
    }

    public function getDeployStrategy(): ?string
    {
        return $this->stringDetail('deploy_strategy');
    }

    /**
     * The image the last deploy resolved for this project, e.g. `golang:1.27-alpine`.
     * Frozen with the snapshot: the version comes from the project (go.mod, pom.xml,
     * composer.json), which the strategy alone cannot tell.
     */
    public function getDeployImage(): ?string
    {
        return $this->stringDetail('deploy_image');
    }

    public function getDeployRuntime(): ?string
    {
        return $this->stringDetail('deploy_runtime');
    }

    public function getGitCommit(): ?string
    {
        return self::trimmed($this->stringDetail('git_commit'));
    }

    /**
     * User-supplied environment variable overrides for the next deploy/rebuild.
     *
     * @return array<string, string>
     */
    public function getEnvVars(): array
    {
        $details = $this->getDetails();
        if (empty($details['env_vars']) || ! is_array($details['env_vars'])) {
            return [];
        }
        $result = [];
        foreach ($details['env_vars'] as $key => $value) {
            if (self::isUsableEnvVar($key, $value)) {
                $result[$key] = (string) $value;
            }
        }

        return $result;
    }

    /**
     * Stores the env overrides, remembering which keys a `vault:<key>` set: such a
     * value is the vault's, and is never revealed. `$sent` is the map the caller
     * sent, references unread; a key it set to a value of its own is no longer
     * the vault's.
     *
     * @param  array<string, string>  $vars
     */
    public function setEnvVars(array $vars, mixed $sent): void
    {
        $this->setDetails([
            'env_vars' => $vars,
            self::DETAIL_VAULT_ENV_KEYS => $this->vaultEnvKeysAfter(is_array($sent) ? $sent : [], $vars),
        ]);
    }

    /**
     * The env var keys whose stored value a vault reference set.
     *
     * @return list<string>
     */
    public function getVaultEnvKeys(): array
    {
        $keys = $this->getDetails()[self::DETAIL_VAULT_ENV_KEYS] ?? [];

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    /**
     * @param  array<mixed>  $sent
     * @param  array<string, string>  $vars
     * @return list<string>
     */
    private function vaultEnvKeysAfter(array $sent, array $vars): array
    {
        $kept = array_diff($this->getVaultEnvKeys(), array_map('strval', array_keys($sent)));
        $keys = array_unique([...$kept, ...RequestVault::referencedKeys($sent)]);

        return array_values(array_intersect($keys, array_keys($vars)));
    }

    private static function isUsableEnvVar(mixed $key, mixed $value): bool
    {
        return is_string($key) && $key !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)
            && (is_string($value) || is_numeric($value));
    }

    /**
     * The application login the engine generated and delivers on every
     * deploy, as {@see AppCredentials} stores it.
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

        return ! empty($details['used_custom_env_vars']);
    }

    public function getAppPort(): ?int
    {
        $port = $this->getDetails()['app_port'] ?? null;

        return $port === null ? null : (int) $port;
    }

    public function setAppPort(?int $value): void
    {
        $this->setDetails(['app_port' => $value]);
    }

    /** 'https' when the recipe declares the app port speaks TLS, else null (plain http). */
    public function getAppPortScheme(): ?string
    {
        $scheme = $this->getDetails()['app_port_scheme'] ?? null;

        return $scheme === 'https' ? 'https' : null;
    }

    public function setAppPortScheme(?string $value): void
    {
        $this->setDetails(['app_port_scheme' => $value === 'https' ? 'https' : null]);
    }

    public function getDeploymentStatus(): string
    {
        return $this->stringDetail('deployment_status') ?? 'unknown';
    }

    /** Set when a deploy failed after an earlier one had succeeded. */
    public function hasDeployedBefore(): bool
    {
        return ($this->getDetails()['deployed_before'] ?? false) === true;
    }

    /** Whether a deploy now upgrades an application that already ran ({@see PlatformStage::phaseFor()}). */
    public function deploysAsUpgrade(): bool
    {
        return PlatformStage::phaseFor($this->getDeploymentStatus(), $this->hasDeployedBefore()) === PlatformStage::UPGRADE;
    }

    public function getDeploymentWarnings(): array
    {
        $details = $this->getDetails();
        if (
            ! empty($details['deployment_warnings'])
            && is_array($details['deployment_warnings'])
        ) {
            return $details['deployment_warnings'];
        }

        return [];
    }

    public function hasDeploymentWarnings(): bool
    {
        return ! empty($this->getDeploymentWarnings());
    }

    /**
     * Record a clean deploy: the status and an empty warnings list, together.
     * The API reports deployment_warnings as a list once a deploy has finished,
     * and setDetails() merges, so a list left by an earlier partial run has to
     * be overwritten rather than left in place. A failed deploy's error is cleared, and so is a stop
     * asked for earlier: the app runs again. Does not save.
     */
    public function markDeploySucceeded(): void
    {
        $this->setDetails([
            'deployment_status' => 'success',
            'deployment_warnings' => [],
            'error' => null,
            'app_stopped_by_request' => null,
        ]);
    }

    /** Set by a stop/down through the API, cleared by a start action or a deploy that finished. Saves. */
    public function markAppStoppedByRequest(bool $stopped): void
    {
        if ($this->isAppStoppedByRequest() === $stopped) {
            return;
        }
        $this->setDetails(['app_stopped_by_request' => $stopped ? true : null]);
        $this->save();
    }

    public function isAppStoppedByRequest(): bool
    {
        return ($this->getDetails()['app_stopped_by_request'] ?? null) === true;
    }

    public function delete()
    {
        $this->assignedIpAddresses()->delete();
        parent::delete();
    }
}
