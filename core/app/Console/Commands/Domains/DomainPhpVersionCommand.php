<?php

namespace App\Console\Commands\Domains;

use App\Http\Requests\DomainSetPhpVersionRequest;
use App\Lib\Domains\DomainPhpVersion;
use App\Models\Domain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class DomainPhpVersionCommand extends Command
{
    protected $signature = 'domain:php-version
        {domain : Canonical domain name}
        {version? : Installed PHP version to set}';

    protected $description = 'Show or set the domain PHP version';

    public function handle(): int
    {
        $domain = strtolower(trim((string) $this->argument('domain')));
        if ($domain === '') {
            $this->error('Domain name is required.');

            return 1;
        }

        $version = $this->argument('version');
        $setting = is_string($version) && $version !== '';
        if ($setting) {
            Validator::make(['version' => $version], (new DomainSetPhpVersionRequest())->rules())->validate();
        }

        $domainModel = Domain::findByNameOrFail($domain);

        if (!$setting) {
            $this->line($domainModel->getPhpVersion() ?? '');

            return 0;
        }

        (new DomainPhpVersion())->set($domainModel, (string) $version);

        return 0;
    }
}
