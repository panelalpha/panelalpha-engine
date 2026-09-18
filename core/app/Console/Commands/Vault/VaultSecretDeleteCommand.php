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
 * Addressed by the id `vault:secret:list` prints, or `global:<type>` for an
 * engine-wide secret. Not by the paste ref -- that is shown once and only
 * its hash is kept, so it is no use to somebody reading a listing.
 */
class VaultSecretDeleteCommand extends Command
{
    protected $signature = 'vault:secret:delete
                            {ref : The id from vault:secret:list, or global:<type>}
                            {--force : Skip the confirmation}';

    protected $description = 'Delete a vault entry and its secret';

    public function handle(): int
    {
        /** @var string $ref */
        $ref = $this->argument('ref');

        $entry = str_starts_with($ref, 'global:')
            ? SecretVaultEntry::globalFor(substr($ref, strlen('global:')))
            : (ctype_digit($ref) ? SecretVaultEntry::query()->find((int) $ref) : null);

        if ($entry === null) {
            $this->error("No vault entry for '{$ref}'. Use an id from vault:secret:list, or global:<type>.");

            return self::FAILURE;
        }

        $what = "entry {$entry->id} ({$entry->type}, {$entry->scope}"
            . ($entry->purpose !== null ? ", \"{$entry->purpose}\"" : '') . ')';

        // A global with a secret in it is the one every project without its
        // own is using, so deleting it is felt immediately and elsewhere.
        if ($entry->isGlobal() && $entry->isSealed()) {
            $this->warn('This is the engine-wide secret for ' . $entry->type
                . '. Every project without one of its own is using it and will stop.');
        }

        if (!$this->option('force') && !$this->confirm("Delete {$what}? The secret cannot be recovered.")) {
            $this->line('Left alone.');

            return self::SUCCESS;
        }

        $entry->delete();
        $this->info("Deleted {$what}.");

        return self::SUCCESS;
    }
}
