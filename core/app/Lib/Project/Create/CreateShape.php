<?php

namespace App\Lib\Project\Create;

/**
 * The typed create body of POST /projects: a `source` object with a `type`, and settings in groups. A body is typed
 * when it carries a key only the typed body has, or no key but the shared ones.
 */
final class CreateShape
{
    public const RUNTIME_CONTAINER = 'container';

    public const RUNTIME_PHP_HOSTING = 'php_hosting';

    public const RUNTIMES = [self::RUNTIME_CONTAINER, self::RUNTIME_PHP_HOSTING];

    public const SOURCE_NONE = 'none';

    public const SOURCE_GIT = 'git';

    public const SOURCE_ARCHIVE = 'archive';

    public const SOURCE_PROJECT = 'project';

    public const SOURCE_WORDPRESS = 'wordpress';

    public const SOURCE_TYPES = [
        self::SOURCE_NONE,
        self::SOURCE_GIT,
        self::SOURCE_ARCHIVE,
        self::SOURCE_PROJECT,
        self::SOURCE_WORDPRESS,
    ];

    /** What an attach to an existing project takes: the source types that deploy into one. */
    public const ATTACHABLE_SOURCE_TYPES = [self::SOURCE_GIT, self::SOURCE_ARCHIVE];

    public const AUTH_NONE = 'none';

    public const AUTH_TOKEN = 'token';

    public const AUTH_DEPLOY_KEY = 'deploy_key';

    public const AUTH_TYPES = [self::AUTH_NONE, self::AUTH_TOKEN, self::AUTH_DEPLOY_KEY];

    /** Top-level keys only the typed body has: any one of them selects it. */
    private const MARKERS = ['runtime', 'source', 'limits', 'settings', 'network', 'build', 'site_password'];

    /** Top-level keys both bodies have alike: a body of nothing else is typed. */
    private const SHARED = ['name', 'email'];

    /** Top-level scalars, with the flat field each becomes; `runtime` becomes `template` by {@see templateFor()}. */
    public const SCALARS = [
        'name' => 'name',
        'email' => 'email',
        'site_password' => 'password',
    ];

    /** Top-level groups, with the flat field each of their keys becomes. */
    public const GROUPS = [
        'domain' => [
            'name' => 'domain',
            'tunnel' => 'tunnel',
            'redirect_url' => 'domain_redirect_url',
        ],
        'limits' => [
            'disk' => 'disk_space_limit',
            'memory' => 'memory_limit',
            'cpu' => 'cpu_limit',
            'device_read_bps' => 'device_read_bps',
            'device_write_bps' => 'device_write_bps',
            'bandwidth' => 'bandwidth_limit',
            'mysql_databases' => 'mysql_databases_limit',
            'ftp_accounts' => 'ftp_accounts_limit',
            'sftp_accounts' => 'sftp_accounts_limit',
            'addon_domains' => 'addon_domains_limit',
            'subdomains' => 'subdomains_limit',
            'inodes' => 'inodes_limit',
        ],
        'settings' => [
            'php_fpm_pool_settings' => 'php_fpm_pool_settings',
            'lsphp_settings' => 'lsphp_settings',
            'redis_config' => 'redis_config',
        ],
        'network' => [
            'dedicated_ipv4' => 'dedicated_ipv4',
            'dedicated_ipv6' => 'dedicated_ipv6',
        ],
        'build' => [
            'env_vars' => 'env_vars',
            'recipe' => 'recipe',
            'stages' => 'stages',
        ],
    ];

    /** The keys a source of each type takes, besides `type`. */
    public const SOURCE_KEYS = [
        self::SOURCE_NONE => [],
        self::SOURCE_GIT => ['url', 'branch', 'auth'],
        self::SOURCE_ARCHIVE => ['upload_id', 'url', 'path'],
        self::SOURCE_PROJECT => ['from', 'copy_databases'],
        self::SOURCE_WORDPRESS => ['title', 'admin', 'locale', 'version'],
    ];

    public const AUTH_KEYS = ['type', 'token', 'key'];

    public const WORDPRESS_ADMIN_KEYS = ['user', 'email', 'password'];

    /** The flat body's own spelling of a field the typed body has elsewhere. */
    public const LEGACY_FIELDS = [
        'username' => 'name',
        'template' => 'runtime',
        'tunnel' => 'domain.tunnel',
        'domain_redirect_url' => 'domain.redirect_url',
        'password' => 'site_password',
        'disk_space_limit' => 'limits.disk',
        'memory_limit' => 'limits.memory',
        'cpu_limit' => 'limits.cpu',
        'device_read_bps' => 'limits.device_read_bps',
        'device_write_bps' => 'limits.device_write_bps',
        'bandwidth_limit' => 'limits.bandwidth',
        'mysql_databases_limit' => 'limits.mysql_databases',
        'ftp_accounts_limit' => 'limits.ftp_accounts',
        'sftp_accounts_limit' => 'limits.sftp_accounts',
        'addon_domains_limit' => 'limits.addon_domains',
        'subdomains_limit' => 'limits.subdomains',
        'inodes_limit' => 'limits.inodes',
        'php_fpm_pool_settings' => 'settings.php_fpm_pool_settings',
        'lsphp_settings' => 'settings.lsphp_settings',
        'redis_config' => 'settings.redis_config',
        'dedicated_ipv4' => 'network.dedicated_ipv4',
        'dedicated_ipv6' => 'network.dedicated_ipv6',
        'git_repo' => 'source.url',
        'git_branch' => 'source.branch',
        'git_token' => 'source.auth.token',
        'env_vars' => 'build.env_vars',
        'recipe' => 'build.recipe',
        'stages' => 'build.stages',
    ];

    /**
     * Whether $body is the typed create body; a `domain` object counts too. A body of only `name` and `email` is
     * typed, so it makes the empty container project.
     *
     * @param  array<string, mixed>  $body
     */
    public static function isTyped(array $body): bool
    {
        foreach (self::MARKERS as $key) {
            if (array_key_exists($key, $body)) {
                return true;
            }
        }
        if (array_key_exists('domain', $body)) {
            return is_array($body['domain']);
        }

        return array_diff(array_keys($body), self::SHARED) === [];
    }

    /** The source type a create takes when it names none. */
    public static function sourceTypeOf(mixed $source): string
    {
        if (! is_array($source) || ! is_string($source['type'] ?? null)) {
            return self::SOURCE_NONE;
        }

        return $source['type'];
    }

    /**
     * The runtime a source implies; null when the caller decides (an empty
     * project) or the copied project does.
     */
    public static function impliedRuntime(string $sourceType): ?string
    {
        return match ($sourceType) {
            self::SOURCE_GIT, self::SOURCE_ARCHIVE => self::RUNTIME_CONTAINER,
            self::SOURCE_WORDPRESS => self::RUNTIME_PHP_HOSTING,
            default => null,
        };
    }

    /** The template the flat create takes for a runtime. */
    public static function templateFor(string $runtime): ?string
    {
        return $runtime === self::RUNTIME_CONTAINER ? 'dind' : null;
    }
}
