<?php

namespace App\Console\Commands\Domains;

use App\Console\Commands\Concerns\CallsEngineApi;
use Illuminate\Console\Command;

class DomainPhpDirectivesCommand extends Command
{
    use CallsEngineApi;

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

        $response = $this->dispatchEngine('GET', "/domains/{$domain}/php-directives");
        if ($response->getStatusCode() >= 400) {
            return $this->rejectEngineResponse($response);
        }

        $this->printDirectiveMap($this->directiveMap($response->getContent()));

        return 0;
    }

    /**
     * @return array<string, string>
     */
    private function directiveMap(string|false $body): array
    {
        /** @var mixed $payload */
        $payload = json_decode(is_string($body) ? $body : '', true);
        $data = is_array($payload) ? ($payload['data'] ?? []) : [];
        if (!is_array($data)) {
            return [];
        }

        $map = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $map[$key] = $value;
            }
        }

        return $map;
    }
}
