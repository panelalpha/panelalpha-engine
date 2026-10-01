<?php

namespace App\Console\Commands\Domains;

use App\Console\Commands\Concerns\PrintsPhpSettings;
use App\Http\Requests\DomainSetPhpVersionRequest;
use App\Lib\Domains\DomainPhpVersion;
use App\Models\Domain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Throwable;

class DomainPhpVersionCommand extends Command
{
    use PrintsPhpSettings;

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
        if (is_string($version) && $version !== '') {
            try {
                Validator::make(['version' => $version], (new DomainSetPhpVersionRequest())->rules())->validate();

                $domainModel = Domain::findByName($domain);
                if (!$domainModel) {
                    return $this->rejectWithBody('"Not Found"');
                }

                (new DomainPhpVersion())->set($domainModel, $version);
            } catch (Throwable $e) {
                return $this->rejectWithException($e);
            }

            return 0;
        }

        try {
            $domainModel = Domain::findByName($domain);
            if (!$domainModel) {
                return $this->rejectWithBody('"Not Found"');
            }

            $value = $domainModel->getPhpVersion();
            $this->requireEncodable($value);
        } catch (Throwable $e) {
            return $this->rejectWithException($e);
        }
        $this->line($value ?? '');

        return 0;
    }
}
