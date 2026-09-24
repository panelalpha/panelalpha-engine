<?php

namespace App\Console\Commands\Vault;

use App\Models\SecretVaultEntry;
use Illuminate\Console\Command;

/** Lists the global vault entries any project may use. */
class VaultConfigCommand extends Command
{
    protected $signature = 'vault:config';

    protected $description = 'List the global vault secrets any project may use';

    public function handle(): int
    {
        $globals = SecretVaultEntry::query()
            ->where('scope', SecretVaultEntry::SCOPE_GLOBAL)
            ->whereNotNull('filled_at')
            ->orderBy('type')
            ->orderBy('id')
            ->get();

        if ($globals->isEmpty()) {
            $this->line('No global secrets stored.');

            return self::SUCCESS;
        }

        $this->table(['ref', 'type', 'purpose'], $globals->map(fn (SecretVaultEntry $e) => [
            $e->reference(), $e->type, $e->purpose ?? '-',
        ])->all());

        return self::SUCCESS;
    }
}
