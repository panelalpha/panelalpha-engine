<?php

namespace App\Console\Commands\Wordpress;

use App\Exceptions\ProblemException;
use App\Http\Requests\WordpressMigrationStoreRequest;
use App\Jobs\MigrateWordpress;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTargetResolver;
use App\System\Project\PhpHosting\WordPress\Migration\SourceAccess;
use App\System\Project\PhpHosting\WordPress\Migration\TargetChoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Starts a WordPress migration and queues its job, validated as the API does.
 * The secret comes from the environment (WORDPRESS_MIGRATE_PASSWORD for
 * wp_admin, WORDPRESS_MIGRATE_SOURCE_TOKEN for manual_plugin) or a silent
 * prompt — never as a positional argument.
 */
class WordpressMigrateCommand extends Command
{
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

    public function handle(): int
    {
        $manual = $this->option('method') === WordpressMigration::METHOD_MANUAL_PLUGIN;
        $secret = $manual
            ? $this->resolveSecret('WORDPRESS_MIGRATE_SOURCE_TOKEN', 'Migrator plugin token', 'Source token')
            : $this->resolveSecret('WORDPRESS_MIGRATE_PASSWORD', 'WordPress admin password', 'Password');
        if ($secret === null) {
            return self::FAILURE;
        }

        $input = array_filter([
            'url' => (string) $this->argument('url'),
            'method' => (string) $this->option('method'),
            'login_url' => $this->option('login-url'),
            'username' => $this->argument('username'),
            $manual ? 'source_token' : 'password' => $secret,
            'source_ip' => $this->option('source-ip'),
            'domain' => $this->option('domain'),
            'project' => $this->option('project'),
            'project_name' => $this->option('project-name'),
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');

        $validator = Validator::make($input, WordpressMigrationStoreRequest::rulesFor($input));
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $validated = $validator->validated();
        $target = $this->target($validated);
        if ($target === null) {
            return self::FAILURE;
        }

        $access = SourceAccess::fromValidated($validated);
        $migration = WordpressMigration::start(
            sourceUrl: $access->url,
            username: $access->username,
            password: $access->password,
            method: $access->method,
            loginUrl: $access->loginUrl,
            sourceIp: $access->sourceIp,
            sourceToken: $access->sourceToken,
            target: $target,
        );
        MigrateWordpress::dispatch($migration->id);

        $this->line((string) $migration->id);

        return self::SUCCESS;
    }

    /**
     * The target the options name, or null after printing why it is refused.
     *
     * @param  array<string, mixed>  $validated
     */
    private function target(array $validated): ?MigrationTarget
    {
        try {
            return (new MigrationTargetResolver)->resolveChoice(
                (string) $validated['url'],
                TargetChoice::fromValidated($validated),
            );
        } catch (ProblemException $e) {
            $this->error((string) $e->validator->errors()->first());

            return null;
        }
    }

    /**
     * Prefer the environment, then a hidden prompt. Never invent a secret
     * when the session is non-interactive and the env var is unset.
     */
    private function resolveSecret(string $env, string $prompt, string $label): ?string
    {
        $fromEnv = getenv($env);
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        $canPrompt = $this->input->isInteractive()
            && defined('STDIN')
            && is_resource(STDIN)
            && stream_isatty(STDIN);

        if (! $canPrompt) {
            $this->error("{$label} required: set {$env} or run interactively.");

            return null;
        }

        $secret = $this->secret($prompt);
        if (! is_string($secret) || $secret === '') {
            $this->error("{$label} required: set {$env} or enter it when prompted.");

            return null;
        }

        return $secret;
    }
}
