<?php

namespace App\Console\Commands\Vault;

use App\Models\SecretVaultEntry;
use Illuminate\Console\Command;

/**
 * Removes expired vault entries and their secrets.
 *
 * An expired entry cannot be resolved ({@see \App\Lib\Vault\RequestVault}
 * refuses it), but the ciphertext would sit in `secret_vault_entries` for
 * ever without this -- and a secret that no longer works should not outlive
 * its usefulness on disk any more than in behaviour.
 *
 * `--grace` keeps rows an hour past expiry before deleting, so a status
 * check on a just-expired ref still explains itself (`expired`) instead of
 * vanishing into `unknown` while somebody is reading the page.
 *
 * A global entry has no expiry and is never purged for age -- it is the
 * engine's credential, not a slot. The one exception is a global whose paste
 * link closed with nothing ever pasted into it: that is an abandoned mint,
 * and leaving it would make `vault_secret_list` claim an engine-wide secret
 * exists when none does.
 */
class VaultPurgeCommand extends Command
{
    protected $signature = 'vault:purge {--grace=3600 : Seconds to keep rows past their expiry}';

    protected $description = 'Delete expired secret vault entries';

    public function handle(): int
    {
        $grace = max(0, (int) $this->option('grace'));

        $cutoff = now()->subSeconds($grace);

        $deleted = SecretVaultEntry::query()
            ->where(function ($query) use ($cutoff) {
                $query->where('expires_at', '<=', $cutoff)
                    // An abandoned global mint: the form closed, nothing was
                    // ever pasted. A filled one has no expiry and stays.
                    ->orWhere(fn ($q) => $q
                        ->where('scope', SecretVaultEntry::SCOPE_GLOBAL)
                        ->whereNull('filled_at')
                        ->where('link_expires_at', '<=', $cutoff));
            })
            ->delete();

        $this->info("Deleted {$deleted} expired vault entr" . ($deleted === 1 ? 'y' : 'ies') . ($grace > 0 ? " (grace {$grace}s)" : '') . '.');

        return self::SUCCESS;
    }
}