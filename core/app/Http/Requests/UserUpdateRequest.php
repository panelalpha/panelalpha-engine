<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LowercasesInput;
use App\Http\Requests\Concerns\ReportsProblems;
use App\Models\User;
use App\Rules\AccountMemoryLimit;
use App\Rules\DomainName;
use App\Rules\FreeMainDomain;
use App\Rules\UnlimitedOrAtLeastZero;
use App\System\Projects;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reports problems so a refused memory_limit carries its code, as on create.
 */
class UserUpdateRequest extends FormRequest
{
    use LowercasesInput;
    use ReportsProblems;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->lowercaseInput('domain');
    }

    /**
     * The domain takes UserStoreRequest's hostname grammar: it becomes a vhost filename,
     * a certificate path and a directory under the account.
     * -1 means unlimited for every limit but memory_limit, which every project runs with:
     * it can be changed, never removed.
     */
    public function rules(): array
    {
        return [
            'domain' => [
                'string',
                'regex:'.DomainName::REGEX,
                new FreeMainDomain((string) $this->route('username')),
            ],
            'email' => 'nullable|email',
            'disk_space_limit' => ['nullable', 'integer', new UnlimitedOrAtLeastZero],
            'memory_limit' => ['sometimes', 'integer', new AccountMemoryLimit(container: $this->updatesContainerProject())],
            'cpu_limit' => ['nullable', 'numeric', new UnlimitedOrAtLeastZero],
            'device_read_bps' => ['integer', 'nullable', new UnlimitedOrAtLeastZero],
            'device_write_bps' => ['integer', 'nullable', new UnlimitedOrAtLeastZero],
            'bandwidth_limit' => ['integer', 'nullable', new UnlimitedOrAtLeastZero],
            'mysql_databases_limit' => ['integer', 'nullable', new UnlimitedOrAtLeastZero],
            'ftp_accounts_limit' => ['integer', 'nullable', new UnlimitedOrAtLeastZero],
            'sftp_accounts_limit' => ['integer', 'nullable', new UnlimitedOrAtLeastZero],
            'addon_domains_limit' => ['integer', 'nullable', new UnlimitedOrAtLeastZero],
            'subdomains_limit' => ['integer', 'nullable', new UnlimitedOrAtLeastZero],
            'inodes_limit' => ['integer', 'nullable', new UnlimitedOrAtLeastZero],
            'php_fpm_pool_settings' => 'nullable|string',
            'lsphp_settings' => 'nullable|string',
            'redis_config' => 'nullable|string',
        ];
    }

    /** A container project's account runs its own Docker daemon, so its memory floor is higher. */
    private function updatesContainerProject(): bool
    {
        $username = (string) $this->route('username');
        $user = $username === '' ? null : User::findByUsername($username);

        return $user !== null && Projects::isDind($user);
    }
}
