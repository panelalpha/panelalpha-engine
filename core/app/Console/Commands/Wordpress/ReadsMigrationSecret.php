<?php

namespace App\Console\Commands\Wordpress;

/**
 * The wp-admin password or plugin token of a WordPress import, from the
 * environment (WORDPRESS_MIGRATE_PASSWORD, WORDPRESS_MIGRATE_SOURCE_TOKEN)
 * or a silent prompt, never from an argument.
 */
trait ReadsMigrationSecret
{
    /**
     * @param  string  $field  `password` or `source_token`, as the API names it
     */
    private function migrationSecret(string $field): ?string
    {
        return $field === 'source_token'
            ? $this->resolveSecret('WORDPRESS_MIGRATE_SOURCE_TOKEN', 'Migrator plugin token', 'Source token')
            : $this->resolveSecret('WORDPRESS_MIGRATE_PASSWORD', 'WordPress admin password', 'Password');
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
