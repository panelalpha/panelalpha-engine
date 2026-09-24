<?php

namespace App\Console\Commands\Vault;

use App\Models\SecretVaultEntry;
use Illuminate\Console\Command;

/**
 * Removes an entry and the secret in it.
 *
 * More than cleanup now: a stored secret can never be overwritten, so this
 * is the first half of *replacing* one. Delete, then
 * `vault:secret:create` a new link.
 *
 * Addressed by its `vault:<id>` or the bare id `vault:secret:list` prints.
 * A project that already used it keeps the copy it stored.
 */
class VaultSecretDeleteCommand extends Command
{
    protected $signature = 'vault:secret:delete
                            {ref : The id from vault:secret:list, or vault:<id>}
                            {--force : Skip the confirmation}';

    protected $description = 'Delete a vault entry and its secret';

    public function handle(): int
    {
        /** @var string $ref */
        $ref = $this->argument('ref');

        $id = SecretVaultEntry::idFromReference($ref);
        $entry = $id === null ? null : SecretVaultEntry::query()->find($id);

        if ($entry === null) {
            $this->error("No vault entry for '{$ref}'. Use an id from vault:secret:list.");

            return self::FAILURE;
        }

        $what = "entry {$entry->id} ({$entry->type}, {$entry->scope}"
            . ($entry->purpose !== null ? ", \"{$entry->purpose}\"" : '') . ')';

        if (!$this->option('force') && !$this->confirm("Delete {$what}? The secret cannot be recovered.")) {
            $this->line('Left alone.');

            return self::SUCCESS;
        }

        $entry->delete();
        $this->info("Deleted {$what}.");

        return self::SUCCESS;
    }
}
