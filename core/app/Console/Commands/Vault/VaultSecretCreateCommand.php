<?php

namespace App\Console\Commands\Vault;

use App\Lib\Vault\RequestVault;
use App\Lib\Vault\SecretMinter;
use App\Models\SecretVaultEntry;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Mints a paste slot and prints the URL to hand to whoever holds the secret.
 *
 * The console half of `POST /api/vault/secrets`, through the same
 * {@see SecretMinter}, so the one-global-per-type and never-overwrite rules
 * cannot differ between a shell and an agent.
 */
class VaultSecretCreateCommand extends Command
{
    protected $signature = 'vault:secret:create
                            {type : The request field the reference is for (git_token, cloudflare_api_token, env_vars, ...)}
                            {--scope=request : request (one secret, expires in an hour) or global (the engine\'s own, no expiry)}
                            {--purpose= : What the secret is for, shown on the paste form and in vault:secret:list}';

    protected $description = 'Create a vault paste link for a secret';

    public function handle(): int
    {
        /** @var string $type */
        $type = $this->argument('type');
        /** @var string $scope */
        $scope = (string) $this->option('scope');

        if (preg_match('/^[a-z][a-z0-9_]*$/', $type) !== 1) {
            $this->error("Type must be snake_case (got '{$type}').");

            return self::FAILURE;
        }

        if (!in_array($scope, SecretVaultEntry::SCOPES, true)) {
            $this->error('Scope must be one of: ' . implode(', ', SecretVaultEntry::SCOPES) . '.');

            return self::FAILURE;
        }

        $purpose = $this->option('purpose');
        if (is_string($purpose) && mb_strlen($purpose) > 255) {
            $this->error('Purpose must be at most 255 characters.');

            return self::FAILURE;
        }

        try {
            [$entry, $ref] = SecretMinter::mint($type, $scope, is_string($purpose) ? $purpose : null);
        } catch (ValidationException $e) {
            // The only one it raises: a global of this type is already set.
            $this->error(collect($e->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $url = rtrim((string) config('app.url'), '/') . '/vault/' . $ref;

        $this->info('Vault link created. Give this URL to whoever holds the secret:');
        $this->newLine();
        $this->line('  ' . $url);
        $this->newLine();
        $this->line('  id:        ' . $entry->id);
        $this->line('  type:      ' . $entry->type);
        $this->line('  scope:     ' . $entry->scope);
        $this->line('  purpose:   ' . ($entry->purpose ?? '(none given)'));
        $this->line('  reference: ' . ($entry->isGlobal()
            ? RequestVault::PREFIX . RequestVault::GLOBAL_REF
            : RequestVault::PREFIX . $ref));
        $this->newLine();
        $this->line('The link stops accepting in ' . (SecretVaultEntry::TTL_SECONDS / 60) . ' minutes.');
        $this->line($entry->isGlobal()
            ? 'The secret itself will not expire, and every project without one of its own will use it.'
            : 'The secret expires with the link.');
        // Said at create time, because it is the thing that most surprises
        // somebody later: there is no edit, only delete and re-create.
        $this->line('Once pasted it cannot be changed -- to replace it, delete the entry and create a new link.');

        return self::SUCCESS;
    }
}
