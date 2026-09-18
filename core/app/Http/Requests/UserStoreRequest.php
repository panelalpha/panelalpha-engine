<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReportsProblems;
use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Deploy\Source\GitTokenInput;
use App\Lib\Domains\DomainPlan;
use App\Rules\GitAccessToken;
use App\Rules\GitRepositoryUrl;
use Illuminate\Foundation\Http\FormRequest;

class UserStoreRequest extends FormRequest
{
    use ReportsProblems;

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
        }

        if ($this->has('domain')) {
            $this->merge(['domain' => strtolower($this->domain)]);
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
            'username' => 'string|alpha_num:ascii|regex:/^[a-z]{1}/|lowercase|between:3,15',
            'domain' => 'string|regex:/^(?!:\/\/)(?=.{1,255}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i',
            'domain_redirect_url' => 'url|nullable',
            'email' => 'email',
            'disk_space_limit' => 'integer|nullable|min:-1',
            'memory_limit' => 'integer|nullable|min:0',
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
            'git_branch' => 'string|nullable|max:255',
            'git_token' => [
                'nullable',
                'string',
                'max:' . GitTokenInput::MAX_LENGTH,
                new GitAccessToken(),
            ],
            'env_vars' => 'array|nullable|max:200',
            'env_vars.*' => 'string|nullable|max:8192',
            // Shape only. What makes a stage's commands legal is the manifest
            // grammar, checked by DeployPlanInput -- see the note there.
            'stages' => 'array|nullable',
            // Likewise: whether the engine has a recipe by this name is
            // settled by the registry, not by a list restated here.
            'recipe' => 'string|nullable|max:64',
        ];
    }
}
