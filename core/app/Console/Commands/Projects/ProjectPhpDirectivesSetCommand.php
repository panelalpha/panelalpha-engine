<?php

namespace App\Console\Commands\Projects;

use App\Console\Commands\Concerns\CallsEngineApi;
use Illuminate\Console\Command;

class ProjectPhpDirectivesSetCommand extends Command
{
    use CallsEngineApi;

    protected $signature = 'project:php-directives:set
        {username : Hosting account username}
        {version : PHP version}
        {--settings= : JSON object replacing this version\'s directive map}
        {--clear : Leave this version with an empty directive file}';

    protected $description = 'Replace account PHP directives for one PHP version';

    public function handle(): int
    {
        $username = trim((string) $this->argument('username'));
        $version = trim((string) $this->argument('version'));
        if ($username === '' || $version === '') {
            $this->error('Username and PHP version are required.');

            return 1;
        }

        $clear = (bool) $this->option('clear');
        $raw = $this->option('settings');
        $hasSettings = is_string($raw);
        if ($clear && $hasSettings) {
            $this->error('Pass either --settings or --clear, not both.');

            return 1;
        }
        if (!$clear && !$hasSettings) {
            $this->error('Pass --settings or --clear.');

            return 1;
        }

        $settings = [];
        if (!$clear) {
            /** @var mixed $decoded */
            $decoded = json_decode((string) $raw, true);
            if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
                $this->error('--settings must be a JSON object.');

                return 1;
            }
            foreach ($decoded as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    $this->error('--settings values must be strings.');

                    return 1;
                }
                $settings[$key] = $value;
            }
        }

        $response = $this->dispatchEngine('PUT', '/projects/' . rawurlencode($username) . '/php/custom-ini-settings', [
            'php_version' => $version,
            'settings' => $settings,
        ]);
        if ($response->getStatusCode() >= 400) {
            return $this->rejectEngineResponse($response);
        }

        return 0;
    }
}
