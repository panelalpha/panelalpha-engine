<?php

namespace App\Console\Commands\Domains;

use App\Http\Requests\DomainReplacePhpDirectivesRequest;
use App\Models\Domain;
use App\System;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class DomainPhpDirectivesSetCommand extends Command
{
    protected $signature = 'domain:php-directives:set
        {domain : Canonical domain name}
        {--settings= : JSON object replacing the whole directive map}
        {--clear : Remove domain PHP directives}';

    protected $description = 'Replace domain PHP directives';

    public function handle(): int
    {
        $domain = strtolower(trim((string) $this->argument('domain')));
        if ($domain === '') {
            $this->error('Domain name is required.');

            return 1;
        }

        $settings = $this->replacementMap();
        if ($settings === null) {
            return 1;
        }

        Validator::make(['settings' => $settings], (new DomainReplacePhpDirectivesRequest())->rules())->validate();

        $domainModel = Domain::findByNameOrFail($domain);
        $domainModel->user->project(app(System::class))->php()->replaceDomainDirectives($domainModel, $settings);

        return 0;
    }

    /**
     * @return array<string, string>|null
     */
    private function replacementMap(): ?array
    {
        $clear = (bool) $this->option('clear');
        $raw = $this->option('settings');
        $hasSettings = is_string($raw);

        if ($clear && $hasSettings) {
            $this->error('Pass either --settings or --clear, not both.');

            return null;
        }
        if (!$clear && !$hasSettings) {
            $this->error('Pass --settings or --clear.');

            return null;
        }
        if ($clear) {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
            $this->error('--settings must be a JSON object.');

            return null;
        }

        $map = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                $this->error('--settings values must be strings.');

                return null;
            }
            $map[$key] = $value;
        }

        return $map;
    }
}
