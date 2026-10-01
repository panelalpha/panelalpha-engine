<?php

namespace App\Console\Commands\Domains;

use App\Console\Commands\Concerns\PrintsPhpSettings;
use App\Models\Domain;
use App\System;
use Illuminate\Console\Command;

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

        $domainModel = Domain::findByNameOrFail($domain);
        $settings = $domainModel->user->project(app(System::class))->php()->getDomainDirectives($domainModel);

        $this->printDirectiveMap($settings);

        return 0;
    }
}
