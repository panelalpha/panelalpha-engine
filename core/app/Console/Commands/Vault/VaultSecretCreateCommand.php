<?php

namespace App\Console\Commands\Vault;

use App\Lib\Vault\PasteCheck;
use App\Lib\Vault\SecretMinter;
use App\Models\SecretVaultEntry;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Mints a paste slot and prints the URL to hand to whoever holds the secret.
 *
 * The console half of `POST /api/vault/secrets`, through the same
 * {@see SecretMinter}, so a shell and an agent cannot mint differently.
 */
class VaultSecretCreateCommand extends Command
{
    protected $signature = 'vault:secret:create
                            {type : The request field the reference is for (git_token, cloudflare_api_token, env_vars, ...)}
                            {--scope=project : project (usable by the first project given it) or global (usable by any project)}
                            {--purpose= : What the secret is for, shown on the paste form and in vault:secret:list}
                            {--project= : project scope only: the project that owns it from the start (need not exist yet)}
                            {--expires-in= : Seconds until the secret expires and is deleted; omit to keep it}
                            {--repo-url= : git_token only: the repository a pasted token is tried against before it is saved}
                            {--hostname= : cloudflare_api_token only: a domain whose zone the pasted token must see}';

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

        $verifyWith = array_filter([
            'repo_url' => $this->option('repo-url'),
            'hostname' => $this->option('hostname'),
        ], static fn (mixed $v): bool => is_string($v) && $v !== '');

        try {
            $project = $this->option('project');
            $expiresIn = $this->option('expires-in');
            if (is_string($expiresIn) && !ctype_digit($expiresIn)) {
                $this->error('--expires-in takes a number of seconds.');

                return self::FAILURE;
            }
            [$entry, $ref] = SecretMinter::mint(
                $type,
                $scope,
                is_string($purpose) ? $purpose : null,
                $verifyWith,
                is_string($project) ? $project : null,
                is_string($expiresIn) ? (int) $expiresIn : null
            );
        } catch (ValidationException $e) {
            // --repo-url/--hostname or --project is unusable.
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
        if (($target = PasteCheck::describe($entry)) !== null) {
            $this->line('  checked:   against ' . $target . ' before saving');
        }
        $this->line('  reference: ' . $entry->reference());
        $this->newLine();
        $this->line('The link stops accepting in ' . (SecretVaultEntry::TTL_SECONDS / 60) . ' minutes; '
            . ($entry->expires_at === null
                ? 'the secret itself does not expire.'
                : 'the secret expires at ' . $entry->expires_at->format('Y-m-d H:i') . ' and is then deleted.'));
        $this->line(match (true) {
            $entry->isGlobal() => 'Any project may use it by passing ' . $entry->reference() . '.',
            $entry->project !== null => "Only project '{$entry->project}' can use " . $entry->reference() . '.',
            default => 'The first project given ' . $entry->reference() . ' claims it; any other project is refused.',
        });
        // Said at create time, because it is the thing that most surprises
        // somebody later: there is no edit, only delete and re-create.
        $this->line('Once pasted it cannot be changed -- to replace it, delete the entry and create a new link.');

        return self::SUCCESS;
    }
}
