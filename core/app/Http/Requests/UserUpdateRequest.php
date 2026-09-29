<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReportsProblems;
use App\Rules\AccountMemoryLimit;
use Illuminate\Foundation\Http\FormRequest;

class UserUpdateRequest extends FormRequest
{
    // A refused memory_limit carries its code, as on create.
    use ReportsProblems;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('domain')) {
            $this->merge(['domain' => strtolower($this->domain)]);
        }
    }

    public function rules(): array
    {
        return [
            // Same hostname grammar as UserStoreRequest: the domain becomes a vhost
            // filename, a certificate path and a directory under the account.
            'domain' => 'string|regex:/^(?!:\/\/)(?=.{1,255}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i',
            'email' => 'nullable|email',
            // -1 is the documented "unlimited" sentinel for disk space only;
            // the other limits have no negative meaning, and a negative one
            // reaches docker as an invalid mem_limit/cpus value.
            'disk_space_limit' => 'nullable|integer|min:-1',
            // Can be changed, never removed: every project has one.
            'memory_limit' => ['sometimes', 'integer', 'min:1', new AccountMemoryLimit()],
            'cpu_limit' => 'nullable|numeric|min:0',
            'device_read_bps' => 'integer|nullable',
            'device_write_bps' => 'integer|nullable',
            'bandwidth_limit' => 'integer|nullable|min:0',
            'mysql_databases_limit' => 'integer|nullable',
            'ftp_accounts_limit' => 'integer|nullable',
            'sftp_accounts_limit' => 'integer|nullable',
            'addon_domains_limit' => 'integer|nullable',
            'subdomains_limit' => 'integer|nullable',
            'inodes_limit' => 'integer|nullable',
            'php_fpm_pool_settings' => 'nullable|string',
            'lsphp_settings' => 'nullable|string',
            'redis_config' => 'nullable|string',
        ];
    }
}
