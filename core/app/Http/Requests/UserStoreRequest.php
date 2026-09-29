<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReportsProblems;
use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Deploy\Source\GitTokenInput;
use App\Lib\Domains\DomainPlan;
use App\Lib\Host\ProjectMemory;
use App\Rules\AccountMemoryLimit;
use App\Rules\GitAccessToken;
use App\Rules\GitRepositoryUrl;
use App\Rules\ProjectName;
use App\System\Project\Git\Ref as GitRef;
use Illuminate\Foundation\Http\FormRequest;

class UserStoreRequest extends FormRequest
{
    use ReportsProblems;

    /** What the caller called the project name: problems are reported under it. */
    private string $nameField = 'username';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // `name` is what every other client calls this field. The MCP tool maps
        // it to `username` on the way in, so only a direct REST caller was
        // bitten -- and bitten silently: the request validated, the engine
        // derived a username from the repository instead, and the caller got a
        // project under a name it had not asked for. suroi's test sent
        // `name: suroi2dd4` and the engine created `suroi`, which then collided
        // with an account of that name and failed the deploy inside a stage.
        //
        // Accepted as an alias rather than renamed, because `username` is what
        // the rest of the API documents and both spellings have to keep working.
        if (!$this->has('username') && $this->has('name')) {
            $this->merge(['username' => $this->input('name')]);
            $this->nameField = 'name';
        }

        if ($this->has('domain')) {
            $this->merge(['domain' => strtolower($this->domain)]);
        }

        // Every project has a memory limit; one not given is the default.
        if ($this->input('memory_limit') === null) {
            $this->merge(['memory_limit' => ProjectMemory::defaultMb()]);
        }

        // Accept schemeless host/path URLs like "github.com/owner/repo".
        $gitRepo = $this->input('git_repo');
        if (is_string($gitRepo) && trim($gitRepo) !== '') {
            $this->merge(['git_repo' => GitRepoInput::normalise($gitRepo)]);
        }
    }

    public function rules(): array
    {
        return [
            'username' => ['string', new ProjectName($this->nameField)],
            'domain' => 'string|regex:/^(?!:\/\/)(?=.{1,255}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i',
            'domain_redirect_url' => 'url|nullable',
            'email' => 'email',
            'disk_space_limit' => 'integer|nullable|min:-1',
            // prepareForValidation() fills in the default, so it is always there.
            'memory_limit' => ['integer', 'min:1', new AccountMemoryLimit()],
            'cpu_limit' => 'numeric|nullable|min:0',
            'device_read_bps' => 'integer|nullable',
            'device_write_bps' => 'integer|nullable',
            'bandwidth_limit' => 'integer|nullable|min:0',
            'mysql_databases_limit' => 'integer|nullable',
            'ftp_accounts_limit' => 'integer|nullable',
            'sftp_accounts_limit' => 'integer|nullable',
            'addon_domains_limit' => 'integer|nullable',
            'subdomains_limit' => 'integer|nullable',
            'inodes_limit' => 'integer|nullable',
            'php_fpm_pool_settings' => 'string|nullable',
            'lsphp_settings' => 'string|nullable',
            'redis_config' => 'string|nullable',
            'dedicated_ipv4' => 'boolean|nullable',
            'dedicated_ipv6' => 'boolean|nullable',
            'template' => 'string|nullable',
            // A PanelAlpha Online tunnel serves the name it is attached to,
            // so asking for one while naming a domain that is not that name
            // is a contradiction -- and one that looks like it worked, which
            // is why it is refused here rather than explained later.
            'tunnel' => [
                'string',
                'nullable',
                'in:' . implode(',', DomainPlan::TUNNELS),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== DomainPlan::TUNNEL_PANELALPHA) {
                        return;
                    }
                    $domain = $this->input('domain');
                    if (is_string($domain) && $domain !== '' && DomainPlan::onlineLabel($domain) === null) {
                        $fail(
                            'A PanelAlpha Online tunnel serves the name it is attached to, so the domain '
                            . 'must be that name. Omit `domain` to have one allocated, or set a Cloudflare '
                            . 'token on the project and use the tunnels endpoint for ' . $domain . '.'
                        );
                    }
                },
            ],
            'git_repo' => ['nullable', 'string', 'max:' . GitRepoInput::MAX_LENGTH, new GitRepositoryUrl()],
            // Whether the remote has it is asked with the repository probe,
            // in the controller: that is the one check that leaves the host.
            'git_branch' => [
                'string',
                'nullable',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && $value !== '' && !GitRef::isValidName($value)) {
                        $fail("'{$value}' is not a valid branch or tag name.");
                    }
                },
            ],
            'git_token' => [
                'nullable',
                'string',
                'max:' . GitTokenInput::MAX_LENGTH,
                new GitAccessToken(),
            ],
            'env_vars' => 'array|nullable|max:200',
            'env_vars.*' => 'string|nullable|max:8192',
            'password' => 'string|nullable|min:1|max:255',
            // Shape only. What makes a stage's commands legal is the manifest
            // grammar, checked by DeployPlanInput -- see the note there.
            'stages' => 'array|nullable',
            // Likewise: whether the engine has a recipe by this name is
            // settled by the registry, not by a list restated here.
            'recipe' => 'string|nullable|max:64',
        ];
    }

    /** `name` or `username`, whichever the caller sent. */
    public function nameField(): string
    {
        return $this->nameField;
    }

    public function attributes(): array
    {
        return ['username' => $this->nameField];
    }

    protected function reportedField(string $field): string
    {
        return $field === 'username' ? $this->nameField : $field;
    }

    protected function expectations(): array
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
            'memory_limit' => $count('an integer number of MB, 0 or more; omit or null for no limit', '512'),
            'cpu_limit' => $count('a number of CPU cores, 0 or more, fractions allowed; omit or null for no limit', '1.5'),
            'bandwidth_limit' => $count('an integer, 0 or more; omit or null for no limit', '100000'),
            'inodes_limit' => $count('an integer; omit or null for no limit', '500000'),
            'tunnel' => ['expected' => 'one of: ' . implode(', ', DomainPlan::TUNNELS), 'examples' => DomainPlan::TUNNELS],
            'git_branch' => ['expected' => 'the name of a branch or tag in the repository', 'examples' => ['main', 'v1.2.0']],
            'env_vars' => ['expected' => 'an object of KEY: "value" strings, at most 200', 'examples' => ['{"APP_ENV": "production"}']],
            'password' => ['expected' => 'a string of 1-255 characters'],
            'recipe' => ['expected' => 'a recipe name, at most 64 characters'],
            'template' => ['expected' => 'a template name; dind for a git_repo', 'examples' => ['dind']],
        ];
    }
}
