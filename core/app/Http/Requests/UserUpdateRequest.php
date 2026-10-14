<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LowercasesInput;
use App\Http\Requests\Concerns\ReportsProblems;
use App\Rules\AccountMemoryLimit;
use App\Rules\DomainName;
use App\Rules\FreeMainDomain;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reports problems so a refused memory_limit carries its code, as on create.
 */
class UserUpdateRequest extends FormRequest
{
    use ReportsProblems;
    use LowercasesInput;

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
     * -1 means unlimited for disk space, device I/O and the count limits; memory and CPU
     * have no negative meaning to docker. memory_limit can be changed, never removed.
     */
    public function rules(): array
    {
        return [
            'domain' => [
                'string',
                'regex:' . DomainName::REGEX,
                new FreeMainDomain((string) $this->route('username')),
            ],
            'email' => 'nullable|email',
            'disk_space_limit' => 'nullable|integer|min:-1',
            'memory_limit' => ['sometimes', 'integer', 'min:1', new AccountMemoryLimit()],
            'cpu_limit' => 'nullable|numeric|min:0',
            'device_read_bps' => 'integer|nullable|min:-1',
            'device_write_bps' => 'integer|nullable|min:-1',
            'bandwidth_limit' => 'integer|nullable|min:0',
            'mysql_databases_limit' => 'integer|nullable|min:-1',
            'ftp_accounts_limit' => 'integer|nullable|min:-1',
            'sftp_accounts_limit' => 'integer|nullable|min:-1',
            'addon_domains_limit' => 'integer|nullable|min:-1',
            'subdomains_limit' => 'integer|nullable|min:-1',
            'inodes_limit' => 'integer|nullable|min:-1',
            'php_fpm_pool_settings' => 'nullable|string',
            'lsphp_settings' => 'nullable|string',
            'redis_config' => 'nullable|string',
        ];
    }
}
