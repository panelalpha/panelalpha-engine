<?php

namespace App\Console\Commands\Domains;

use App\Console\Commands\Concerns\CallsEngineApi;
use Illuminate\Console\Command;

class DomainPhpVersionCommand extends Command
{
    use CallsEngineApi;

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
            $response = $this->dispatchEngine('PUT', "/domains/{$domain}/php-version", [
                'version' => $version,
            ]);
            if ($response->getStatusCode() >= 400) {
                return $this->rejectEngineResponse($response);
            }

            return 0;
        }

        $response = $this->dispatchEngine('GET', "/domains/{$domain}/php-version");
        if ($response->getStatusCode() >= 400) {
            return $this->rejectEngineResponse($response);
        }

        /** @var mixed $payload */
        $payload = json_decode((string) $response->getContent(), true);
        $value = is_array($payload) ? ($payload['data'] ?? '') : '';
        $this->line(is_scalar($value) ? (string) $value : '');

        return 0;
    }
}
