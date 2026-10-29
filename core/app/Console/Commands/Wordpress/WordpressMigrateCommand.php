<?php

namespace App\Console\Commands\Wordpress;

use App\Exceptions\ProblemException;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStarter;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Starts a WordPress migration and queues its job, validated as the API does.
 * The secret comes from the environment (WORDPRESS_MIGRATE_PASSWORD for
 * wp_admin, WORDPRESS_MIGRATE_SOURCE_TOKEN for manual_plugin) or a silent
 * prompt — never as a positional argument.
 */
class WordpressMigrateCommand extends Command
{
    use ReadsMigrationSecret;

    protected $signature = 'wordpress:migrate
                            {url : Source WordPress site URL}
                            {username? : wp-admin username on the source site (wp_admin)}
                            {--method=wp_admin : wp_admin or manual_plugin}
                            {--login-url= : wp_admin: the login page, when it is not <url>/wp-login.php}
                            {--source-ip= : Reach the source at this public address with its own Host}
                            {--domain= : Domain the imported site gets (default: the source host without www.)}
                            {--project= : Import into this existing, empty classic project}
                            {--project-name= : Name of the project the import creates}';

    protected $description = 'Start a WordPress site migration and print its id';

    public function handle(MigrationStarter $starter): int
    {
        $secretField = $this->option('method') === WordpressMigration::METHOD_MANUAL_PLUGIN ? 'source_token' : 'password';
        $secret = $this->migrationSecret($secretField);
        if ($secret === null) {
            return self::FAILURE;
        }

        try {
            $migration = $starter->startFromInput($this->apiInput($secretField, $secret));
        } catch (ProblemException $e) {
            $this->error((string) $e->validator->errors()->first());

            return self::FAILURE;
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->line((string) $migration->id);

        return self::SUCCESS;
    }

    /**
     * The arguments and options under their API names, the empty ones left out.
     *
     * @return array<string, string>
     */
    private function apiInput(string $secretField, string $secret): array
    {
        return array_filter([
            'url' => (string) $this->argument('url'),
            'method' => (string) $this->option('method'),
            'login_url' => $this->option('login-url'),
            'username' => $this->argument('username'),
            $secretField => $secret,
            'source_ip' => $this->option('source-ip'),
            'domain' => $this->option('domain'),
            'project' => $this->option('project'),
            'project_name' => $this->option('project-name'),
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');
    }
}
