<?php

namespace App\Console\Commands\Vault;

use App\Lib\Vault\GlobalVault;
use App\Models\SecretVaultEntry;
use Illuminate\Console\Command;

/**
 * Shows, and switches, whether projects inherit the engine's own secrets.
 *
 * Off by default: a Git or Cloudflare token pasted once at engine scope is
 * used by every project that has none of its own. An engine hosting several
 * customers wants the opposite and turns this on, which restores the original
 * per-project isolation exactly -- global entries stay stored and stay
 * resolvable by an explicit `vault:global`, but nothing reaches for them on a
 * project's behalf.
 *
 * The same switch as `PUT /api/vault/config`, for an operator at a shell.
 */
class VaultConfigCommand extends Command
{
    protected $signature = 'vault:config
                            {--project-scoped= : true keeps every project on its own tokens; false shares the engine\'s}';

    protected $description = 'Show or set whether projects inherit the engine-wide vault secrets';

    public function handle(): int
    {
        $option = $this->option('project-scoped');

        if ($option !== null) {
            $scoped = filter_var((string) $option, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($scoped === null) {
                $this->error('--project-scoped takes true or false.');

                return self::FAILURE;
            }

            GlobalVault::setProjectScoped($scoped);
        }

        $scoped = GlobalVault::projectScoped();

        $this->line('project-scoped tokens: ' . ($scoped ? 'on' : 'off'));
        $this->line($scoped
            ? 'Each project uses only the credentials it was given.'
            : "Projects without a credential of their own use the engine's.");

        $types = SecretVaultEntry::query()
            ->where('scope', SecretVaultEntry::SCOPE_GLOBAL)
            ->whereNotNull('filled_at')
            ->orderBy('type')
            ->pluck('type')
            ->all();

        // Worth saying either way: "sharing is on" and "there is nothing to
        // share" look identical from a project, and only one of them is a
        // thing the operator still has to do.
        $this->line('engine-wide secrets: ' . ($types === [] ? 'none stored' : implode(', ', $types)));

        return self::SUCCESS;
    }
}
