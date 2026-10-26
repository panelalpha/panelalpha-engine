<?php

namespace App\Lib\Project;

use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Deploy\Source\GitTokenInput;
use App\Lib\Domains\DomainPlan;
use App\Lib\Host\ProjectMemory;
use App\Lib\Limits\ResourceLimit;
use App\Rules\AccountMemoryLimit;
use App\Rules\DomainName;
use App\Rules\EnvVarKeysNotReserved;
use App\Rules\GitAccessToken;
use App\Rules\GitRepositoryUrl;
use App\Rules\ProjectName;
use App\Rules\RedirectUrl;
use App\System\Project\Git\Ref as GitRef;

/**
 * What a project create accepts: the input it rewrites before validating, and
 * the rules it validates against. POST /users, POST /projects and the CLI all
 * read them from here.
 */
final class NewProjectRules
{
    /**
     * The fields to overwrite before validation, and what the caller called
     * the project name. A missing `memory_limit` becomes the default, since every
     * project has one, and a schemeless `git_repo` (`github.com/owner/repo`) is accepted.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: string}
     */
    public static function normalised(array $input): array
    {
        $changes = [];
        $nameField = 'username';

        if (self::namesTheProjectAsName($input)) {
            $changes['username'] = $input['name'];
            $nameField = 'name';
        }

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
     * Some fields are checked for shape only: `stages` against the manifest grammar by
     * DeployPlanInput, `recipe` against the recipe registry, and whether the remote has
     * `git_branch` by ProjectCreator's repository probe, the one check that leaves the host.
     *
     * @param  string  $nameField  what the caller called the project name
     * @param  mixed  $domain  the normalised `domain` field
     * @return array<string, mixed>
     */
    public static function rules(string $nameField, mixed $domain): array
    {
        return [
            'username' => ['string', new ProjectName($nameField)],
            'domain' => 'string|regex:'.DomainName::REGEX,
            'domain_redirect_url' => ['url', 'nullable', new RedirectUrl],
            'email' => 'email',
            'disk_space_limit' => 'integer|nullable|min:'.ResourceLimit::UNLIMITED,
            'memory_limit' => ['integer', 'min:1', new AccountMemoryLimit],
            'cpu_limit' => 'numeric|nullable|min:'.ResourceLimit::UNLIMITED,
            'device_read_bps' => 'integer|nullable|min:'.ResourceLimit::UNLIMITED,
            'device_write_bps' => 'integer|nullable|min:'.ResourceLimit::UNLIMITED,
            'bandwidth_limit' => 'integer|nullable|min:'.ResourceLimit::UNLIMITED,
            'mysql_databases_limit' => 'integer|nullable|min:'.ResourceLimit::UNLIMITED,
            'ftp_accounts_limit' => 'integer|nullable|min:'.ResourceLimit::UNLIMITED,
            'sftp_accounts_limit' => 'integer|nullable|min:'.ResourceLimit::UNLIMITED,
            'addon_domains_limit' => 'integer|nullable|min:'.ResourceLimit::UNLIMITED,
            'subdomains_limit' => 'integer|nullable|min:'.ResourceLimit::UNLIMITED,
            'inodes_limit' => 'integer|nullable|min:'.ResourceLimit::UNLIMITED,
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
            'git_repo' => ['nullable', ...self::gitRepositoryRules()],
            'git_branch' => self::gitBranchRules(),
            'git_token' => self::gitTokenRules(),
            ...self::envVarRules(),
            'password' => 'string|nullable|min:1|max:255',
            'stages' => 'array|nullable',
            'recipe' => 'string|nullable|max:64',
        ];
    }

    /**
     * The repository URL grammar, without the `nullable` or `required` the
     * caller decides: a create may omit it, an attach may not.
     *
     * @return array<int, mixed>
     */
    public static function gitRepositoryRules(): array
    {
        return ['string', 'max:'.GitRepoInput::MAX_LENGTH, new GitRepositoryUrl];
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
