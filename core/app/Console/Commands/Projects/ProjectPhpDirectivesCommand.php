<?php

namespace App\Console\Commands\Projects;

use App\Console\Commands\Concerns\CallsEngineApi;
use Illuminate\Console\Command;

class ProjectPhpDirectivesCommand extends Command
{
    use CallsEngineApi;

    protected $signature = 'project:php-directives
        {username : Hosting account username}
        {version : PHP version}';

    protected $description = 'Show account PHP directives for one PHP version';

    public function handle(): int
    {
        $username = trim((string) $this->argument('username'));
        $version = trim((string) $this->argument('version'));
        if ($username === '' || $version === '') {
            $this->error('Username and PHP version are required.');

            return 1;
        }

        $response = $this->dispatchEngine(
            'GET',
            '/projects/' . rawurlencode($username) . '/php/custom-ini-settings?php_version=' . rawurlencode($version),
        );
        if ($response->getStatusCode() >= 400) {
            return $this->rejectEngineResponse($response);
        }

        /** @var mixed $payload */
        $payload = json_decode((string) $response->getContent(), true);
        $data = is_array($payload) ? ($payload['data'] ?? []) : [];
        $map = [];
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $map[$key] = $value;
                }
            }
        }
        $this->printDirectiveMap($map);

        return 0;
    }
}
