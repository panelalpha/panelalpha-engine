<?php

namespace App\Console\Commands\Vault;

use App\Models\SecretVaultEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The inventory: what is stored, never the values.
 *
 * No option prints a secret, and there is deliberately no command that can:
 * the vault's whole point is that a pasted value goes to the code that needs
 * it and nowhere else. What this answers is the question an operator
 * actually has -- what is in here, what is it for, and which row do I delete.
 *
 * `purpose` is the column that earns its place. `type` is a category, and
 * three entries can all be `git_token`; since the secret can never be read
 * back, the purpose is the only thing that says which one is which.
 */
class VaultSecretListCommand extends Command
{
    protected $signature = 'vault:secret:list
                            {--type= : Only entries of this type}
                            {--scope= : Only project or only global entries}';

    protected $description = 'List vault entries: id, type, purpose, scope, status and dates -- never the secret';

    public function handle(): int
    {
        $query = SecretVaultEntry::query()->orderByDesc('id');

        if (is_string($type = $this->option('type')) && $type !== '') {
            $query->where('type', $type);
        }
        if (is_string($scope = $this->option('scope')) && $scope !== '') {
            if (!in_array($scope, SecretVaultEntry::SCOPES, true)) {
                $this->error('Scope must be one of: ' . implode(', ', SecretVaultEntry::SCOPES) . '.');

                return self::FAILURE;
            }
            $query->where('scope', $scope);
        }

        /** @var list<SecretVaultEntry> $entries */
        $entries = $query->get()->all();

        if ($entries === []) {
            $this->line('No vault entries.');

            return self::SUCCESS;
        }

        $this->table(
            ['ref', 'type', 'purpose', 'scope', 'project', 'status', 'check', 'used', 'created', 'expires', 'link closes'],
            array_map(fn (SecretVaultEntry $e) => [
                $e->reference(),
                $e->type,
                // Truncated rather than wrapped: one row per entry keeps the
                // table scannable, and the full text is in the API response.
                $e->purpose === null ? '-' : Str::limit($e->purpose, 40),
                $e->scope,
                $e->project ?? '-',
                $e->status(),
                $e->verification['result'] ?? '-',
                $e->use_count,
                $e->created_at?->format('Y-m-d H:i') ?? '-',
                $e->expires_at?->format('Y-m-d H:i') ?? 'never',
                $e->link_expires_at?->format('Y-m-d H:i') ?? '-',
            ], $entries),
        );

        $this->line('The secret itself is never shown. Delete one with: vault:secret:delete <id>');

        return self::SUCCESS;
    }
}
