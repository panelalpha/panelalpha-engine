<?php

namespace App\Lib\Project;

use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Deploy\Source\GitTokenInput;
use App\Lib\Domains\DomainPlan;
use App\Lib\Host\ProjectMemory;
use App\Rules\AccountMemoryLimit;
use App\Rules\DomainName;
use App\Rules\EnvVarKeysNotReserved;
use App\Rules\GitAccessToken;
use App\Rules\GitRepositoryUrl;
use App\Rules\ProjectName;
use App\Rules\RedirectUrl;
use App\Rules\UnlimitedOrAtLeastZero;
use App\System\Project\Git\Ref as GitRef;

/**
 * What a project create accepts: the input it rewrites before validating, and the rules it validates against, read by
 * POST /users, POST /projects and the CLI alike.
 */
final class NewProjectRules
{
    /**
     * The fields to overwrite before validation, and what the caller called the project name. A missing `memory_limit`
     * becomes the default, since every project has one; a schemeless `git_repo` (`github.com/owner/repo`) is accepted.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: string}
     */
    public static function normalised(array $input): array
    {
        [$changes, $nameField] = self::normalisedName($input);

        if (array_key_exists('domain', $input)) {
            $changes['domain'] = self::normalisedDomain($input['domain']);
        }

        if (($input['memory_limit'] ?? null) === null) {
            $changes['memory_limit'] = ProjectMemory::defaultMb();
        }

        $gitRepo = $input['git_repo'] ?? null;
        if (is_string($gitRepo) && trim($gitRepo) !== '') {
            $changes['git_repo'] = GitRepoInput::normalise($gitRepo);
        }

        return [$changes, $nameField];
    }

    /**
     * Whether a create makes a container project: one named `dind`, or one with a `git_repo`, which deploys into it.
     *
     * @param  array<string, mixed>  $input
     */
    public static function createsContainer(array $input): bool
    {
        $gitRepo = $input['git_repo'] ?? null;

        return ($input['template'] ?? null) === 'dind' || (is_string($gitRepo) && trim($gitRepo) !== '');
    }

    /**
     * The project name alone, for a request that takes nothing else of a create.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: string}
     */
    public static function normalisedName(array $input): array
    {
        return self::namesTheProjectAsName($input)
            ? [['username' => $input['name']], 'name']
            : [[], 'username'];
    }

    /**
     * `name` is what every other client calls this field, so it is accepted as an alias of the
     * documented `username` rather than ignored, which would derive a name the caller never asked for.
     *
     * @param  array<string, mixed>  $input
     */
    private static function namesTheProjectAsName(array $input): bool
    {
        return ! array_key_exists('username', $input) && array_key_exists('name', $input);
    }

    /** MCP and the CLI skip the trimming middleware, and `example.com\n` must not be a second name for `example.com`. */
    private static function normalisedDomain(mixed $domain): mixed
    {
        return is_string($domain) ? strtolower(trim($domain)) : $domain;
    }

    /**
     * Some fields are checked for shape only: `stages` against the manifest grammar by DeployPlanInput, `recipe` against the
     * recipe registry, and `git_branch` by ProjectCreator's repository probe, the one check that leaves the host.
     *
     * @param  string  $nameField  what the caller called the project name
     * @param  mixed  $domain  the normalised `domain` field
     * @param  bool  $container  whether the create makes a container project ({@see createsContainer()})
     * @param  bool  $sshWithKey  the create carries a deploy key, so `git_repo` may be an SSH remote
     * @return array<string, mixed>
     */
    public static function rules(string $nameField, mixed $domain, bool $container, bool $sshWithKey = false): array
    {
        return [
            'username' => self::nameRules($nameField),
            'domain' => 'string|regex:'.DomainName::REGEX,
            'domain_redirect_url' => ['url', 'nullable', new RedirectUrl],
            'email' => 'email',
            'disk_space_limit' => ['integer', 'nullable', new UnlimitedOrAtLeastZero],
            'memory_limit' => ['integer', new AccountMemoryLimit(container: $container)],
            'cpu_limit' => ['numeric', 'nullable', new UnlimitedOrAtLeastZero],
            'device_read_bps' => ['integer', 'nullable', new UnlimitedOrAtLeastZero],
            'device_write_bps' => ['integer', 'nullable', new UnlimitedOrAtLeastZero],
            'bandwidth_limit' => ['integer', 'nullable', new UnlimitedOrAtLeastZero],
            'mysql_databases_limit' => ['integer', 'nullable', new UnlimitedOrAtLeastZero],
            'ftp_accounts_limit' => ['integer', 'nullable', new UnlimitedOrAtLeastZero],
            'sftp_accounts_limit' => ['integer', 'nullable', new UnlimitedOrAtLeastZero],
            'addon_domains_limit' => ['integer', 'nullable', new UnlimitedOrAtLeastZero],
            'subdomains_limit' => ['integer', 'nullable', new UnlimitedOrAtLeastZero],
            'inodes_limit' => ['integer', 'nullable', new UnlimitedOrAtLeastZero],
            'php_fpm_pool_settings' => 'string|nullable',
            'lsphp_settings' => 'string|nullable',
            'redis_config' => 'string|nullable',
            'dedicated_ipv4' => 'boolean|nullable',
            'dedicated_ipv6' => 'boolean|nullable',
            'template' => 'string|nullable',
            'tunnel' => [
                'string',
                'nullable',
                'in:'.implode(',', DomainPlan::TUNNELS),
                self::onlineTunnelServesTheDomain($domain),
            ],
            'git_repo' => ['nullable', ...self::gitRepositoryRules($sshWithKey)],
            'git_branch' => self::gitBranchRules(),
            'git_token' => self::gitTokenRules(),
            ...self::envVarRules(),
            'password' => 'string|nullable|min:1|max:255',
            'stages' => 'array|nullable',
            'recipe' => 'string|nullable|max:64',
        ];
    }

    /**
     * A project name's shape, without the `required` the caller decides: a
     * create may leave the name to the engine.
     *
     * @param  string  $nameField  what the caller called the project name
     * @return array<int, mixed>
     */
    public static function nameRules(string $nameField): array
    {
        return ['string', new ProjectName($nameField)];
    }

    /**
     * The keys a flat create body takes: every rule, and `name` for `username`.
     *
     * @return list<string>
     */
    public static function flatKeys(): array
    {
        return [...array_keys(self::rules('username', null, false)), 'name'];
    }

    /**
     * What each field holds, said in a refusal so a caller can fix the value
     * without reading the docs.
     *
     * @return array<string, array{expected: string, examples?: list<string>}>
     */
    public static function expectations(): array
    {
        $count = static fn (string $what, string $example): array => [
            'expected' => $what,
            'examples' => [$example],
        ];

        return [
            'username' => ['expected' => ProjectName::EXPECTED, 'examples' => ProjectName::EXAMPLES],
            'domain' => ['expected' => 'a hostname, lowercase, without scheme or path', 'examples' => ['shop.example.com']],
            'domain_redirect_url' => ['expected' => 'an absolute URL', 'examples' => ['https://example.com/']],
            'email' => ['expected' => 'an email address', 'examples' => ['ops@example.com']],
            'disk_space_limit' => $count('an integer number of MB, -1 for unlimited', '10240'),
            'memory_limit' => $count('an integer number of MB, '.ProjectMemory::MIN_MB.' or more, '.ProjectMemory::CONTAINER_MIN_MB
                .' or more for a container project; omitted, the server default', '512'),
            'cpu_limit' => $count('a number of CPU cores, fractions allowed, -1 for unlimited', '1.5'),
            'bandwidth_limit' => $count('an integer number of MB a month, -1 for unlimited', '100000'),
            'inodes_limit' => $count('an integer, -1 for unlimited', '500000'),
            'tunnel' => ['expected' => 'one of: '.implode(', ', DomainPlan::TUNNELS), 'examples' => DomainPlan::TUNNELS],
            'git_branch' => ['expected' => 'the name of a branch or tag in the repository', 'examples' => ['main', 'v1.2.0']],
            'env_vars' => ['expected' => 'an object of KEY: "value" strings, at most 200', 'examples' => ['{"APP_ENV": "production"}']],
            'password' => ['expected' => 'a string of 1-255 characters'],
            'recipe' => ['expected' => 'a recipe name, at most 64 characters'],
            'template' => ['expected' => 'a template name; dind for a git_repo', 'examples' => ['dind']],
        ];
    }

    /**
     * The repository URL grammar, without the `nullable` or `required` the
     * caller decides: a create may omit it, an attach may not. An SSH remote
     * passes only when a deploy key comes with it.
     *
     * @return array<int, mixed>
     */
    public static function gitRepositoryRules(bool $sshWithKey = false): array
    {
        return ['string', 'max:'.GitRepoInput::MAX_LENGTH, new GitRepositoryUrl(false, $sshWithKey)];
    }

    /**
     * A branch or tag name in the repository.
     *
     * @return array<int, mixed>
     */
    public static function gitBranchRules(): array
    {
        return ['string', 'nullable', 'max:255', self::validRefName()];
    }

    /**
     * An HTTPS token, or a `vault:` reference to one.
     *
     * @return array<int, mixed>
     */
    public static function gitTokenRules(): array
    {
        return ['nullable', 'string', 'max:'.GitTokenInput::MAX_LENGTH, new GitAccessToken];
    }

    /**
     * The env override field, as every deploy endpoint declares it.
     *
     * @return array<string, mixed>
     */
    public static function envVarRules(): array
    {
        return [
            'env_vars' => ['array', 'nullable', 'max:200', new EnvVarKeysNotReserved],
            'env_vars.*' => 'string|nullable|max:8192',
        ];
    }

    /**
     * A git source given to a project that exists: the repository required,
     * and the build options of the deploy it starts.
     *
     * @param  bool  $sshWithKey  a deploy key comes with it, so `git_repo` may be an SSH remote
     * @return array<string, mixed>
     */
    public static function attachRules(bool $sshWithKey = false): array
    {
        return [
            'git_repo' => ['required', ...self::gitRepositoryRules($sshWithKey)],
            'git_branch' => self::gitBranchRules(),
            'git_token' => self::gitTokenRules(),
            ...self::buildRules(),
        ];
    }

    /**
     * What a deploy of a project that exists takes beside its source.
     *
     * @return array<string, mixed>
     */
    public static function buildRules(): array
    {
        return [
            ...self::envVarRules(),
            'stages' => 'array|nullable',
            'recipe' => 'string|nullable|max:64',
        ];
    }

    /**
     * A PanelAlpha Online tunnel serves the name it is attached to, so naming another domain
     * with one is a contradiction that would look like it worked; it is refused here.
     */
    private static function onlineTunnelServesTheDomain(mixed $domain): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($domain): void {
            if ($value !== DomainPlan::TUNNEL_PANELALPHA) {
                return;
            }
            if (is_string($domain) && $domain !== '' && DomainPlan::onlineLabel($domain) === null) {
                $fail(
                    'A PanelAlpha Online tunnel serves the name it is attached to, so the domain '
                    .'must be that name. Omit `domain` to have one allocated, or set a Cloudflare '
                    .'token on the project and use the tunnels endpoint for '.$domain.'.'
                );
            }
        };
    }

    private static function validRefName(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && $value !== '' && ! GitRef::isValidName($value)) {
                $fail("'{$value}' is not a valid branch or tag name.");
            }
        };
    }
}
