<?php

namespace App\Console\Commands\Vault;

use App\Models\SecretVaultEntry;
use Illuminate\Console\Command;

/**
 * Removes vault entries nobody should have to clean up by hand: a secret past
 * the expiry it was created with, and an entry whose paste link closed with
 * nothing pasted. A filled entry with no expiry is only removed by delete.
 *
 * `--grace` keeps such rows a while, so a status check on one that just ran
 * out still explains itself (`expired`, `abandoned`).
 */
class VaultPurgeCommand extends Command
{
    protected $signature = 'vault:purge {--grace=3600 : Seconds to keep a row after it expires or its link closes unfilled}';

    protected $description = 'Delete expired vault secrets and entries whose paste link closed unfilled';

    public function handle(): int
    {
        $grace = max(0, (int) $this->option('grace'));

        $cutoff = now()->subSeconds($grace);

        $deleted = SecretVaultEntry::query()
            ->where(fn ($query) => $query
                ->where('expires_at', '<=', $cutoff)
                ->orWhere(fn ($q) => $q->whereNull('filled_at')->where('link_expires_at', '<=', $cutoff)))
            ->delete();

        $this->info("Deleted {$deleted} expired or abandoned vault entr" . ($deleted === 1 ? 'y' : 'ies') . ($grace > 0 ? " (grace {$grace}s)" : '') . '.');

        return self::SUCCESS;
    }
}