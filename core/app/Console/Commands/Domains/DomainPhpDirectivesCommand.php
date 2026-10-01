<?php

namespace App\Console\Commands\Domains;

use App\Console\Commands\Concerns\PrintsPhpSettings;
use App\Models\Domain;
use App\System;
use Illuminate\Console\Command;
use Throwable;

class DomainPhpDirectivesCommand extends Command
{
    use PrintsPhpSettings;

    protected $signature = 'domain:php-directives
        {domain : Canonical domain name}';

    protected $description = 'Show domain PHP directives';

    public function handle(): int
    {
        $domain = strtolower(trim((string) $this->argument('domain')));
        if ($domain === '') {
            $this->error('Domain name is required.');

            return 1;
        }

        try {
            $domainModel = Domain::findByName($domain);
            if (!$domainModel) {
                return $this->rejectWithBody('"Not Found"');
            }

            $settings = $domainModel->user->project(app(System::class))->php()->getDomainDirectives($domainModel);
            $this->requireEncodable($settings);
        } catch (Throwable $e) {
            return $this->rejectWithException($e);
        }

        $this->printDirectiveMap($settings);

        return 0;
    }
}
