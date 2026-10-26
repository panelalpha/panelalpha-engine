<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LowercasesInput;
use App\Http\Requests\Concerns\ReportsProblems;
use App\Lib\Limits\ResourceLimit;
use App\Rules\AccountMemoryLimit;
use App\Rules\DomainName;
use App\Rules\FreeMainDomain;
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
            'disk_space_limit' => 'nullable|integer|min:'.ResourceLimit::UNLIMITED,
            'memory_limit' => ['sometimes', 'integer', 'min:1', new AccountMemoryLimit],
            'cpu_limit' => 'nullable|numeric|min:'.ResourceLimit::UNLIMITED,
            'device_read_bps' => 'integer|nullable|min:'.ResourceLimit::UNLIMITED,
            'device_write_bps' => 'integer|nullable|min:'.ResourceLimit::UNLIMITED,
            'bandwidth_limit' => 'integer|nullable|min:'.ResourceLimit::UNLIMITED,
            'mysql_databases_limit' => 'integer|nullable|min:'.ResourceLimit::UNLIMITED,
            'ftp_accounts_limit' => 'integer|nullable|min:'.ResourceLimit::UNLIMITED,
            'sftp_accounts_limit' => 'integer|nullable|min:'.ResourceLimit::UNLIMITED,
            'addon_domains_limit' => 'integer|nullable|min:'.ResourceLimit::UNLIMITED,
            'subdomains_limit' => 'integer|nullable|min:'.ResourceLimit::UNLIMITED,
            'inodes_limit' => 'integer|nullable|min:'.ResourceLimit::UNLIMITED,
            'php_fpm_pool_settings' => 'nullable|string',
            'lsphp_settings' => 'nullable|string',
            'redis_config' => 'nullable|string',
        ];
    }
}
