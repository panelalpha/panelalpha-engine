<?php

use App\Models\SecretVaultEntry;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vault scopes become `project` (bound to the first project that uses it) and
 * `global` (usable by any project that names it), both referenced as
 * `vault:<id>`. A secret expires only when it was created with an expiry.
 *
 * Nothing reads the vault on a project's behalf any more, so a project that
 * was using a global secret -- by a stored `vault:` reference, or through the
 * old implicit fallback -- gets that secret stored on it, and an upgrade
 * changes nothing for it.
 */
return new class extends Migration
{
    /** The retired sharing switch; read once here and removed. */
    private const SETTING_PROJECT_SCOPED = 'vault-project-scoped-tokens';

    private const OLD_GLOBAL_REFERENCE = 'vault:global';

    private const PREFIX = 'vault:';

    public function up(): void
    {
        $scoped = null;
        if (Schema::hasTable('settings')) {
            $scoped = DB::table('settings')->where('name', self::SETTING_PROJECT_SCOPED)->value('value');
            DB::table('settings')->where('name', self::SETTING_PROJECT_SCOPED)->delete();
        }

        if (!Schema::hasTable('secret_vault_entries')) {
            return;
        }

        // One-hour slots whose secret was copied into the project that used it.
        DB::table('secret_vault_entries')->where('scope', 'request')->delete();

        if (!Schema::hasColumn('secret_vault_entries', 'project')) {
            Schema::table('secret_vault_entries', function (Blueprint $table) {
                $table->string('project', 64)->nullable()->after('scope')->index();
                $table->string('scope', 16)->default(SecretVaultEntry::SCOPE_PROJECT)->change();
            });
        }

        if (!Schema::hasTable('users')) {
            return;
        }

        // The secret each type fell back to before: the newest global, if usable.
        $git = self::usableGlobal(SecretVaultEntry::TYPE_GIT_TOKEN);
        $cloudflare = self::usableGlobal(SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN);
        $sharing = !self::truthy($scoped);

        User::query()->chunkById(100, function ($users) use ($git, $cloudflare, $sharing): void {
            foreach ($users as $user) {
                $details = $user->getDetails();
                $record = [];

                $gitToken = self::trimmed($details['git_token'] ?? null);
                if (self::isReference($gitToken)) {
                    $record['git_token'] = self::secretFor((string) $gitToken, $git);
                } elseif ($gitToken === null && $sharing && $git !== null && self::trimmed($details['git_repo'] ?? null) !== null) {
                    $record['git_token'] = $git;
                }

                $cfToken = self::trimmed($details['cloudflare_api_token'] ?? null);
                if (self::isReference($cfToken)) {
                    $record['cloudflare_api_token'] = self::secretFor((string) $cfToken, $cloudflare);
                } elseif ($cfToken === null && $sharing && $cloudflare !== null && self::usesCloudflare($user, $details)) {
                    $record['cloudflare_api_token'] = $cloudflare;
                }

                if ($record !== []) {
                    $user->setDetails($record);
                    $user->save();
                }
            }
        });
    }

    /** The data is not restored: a recorded reference looks like one a caller sent. */
    public function down(): void
    {
        if (!Schema::hasTable('secret_vault_entries')) {
            return;
        }

        Schema::table('secret_vault_entries', function (Blueprint $table) {
            $table->dropIndex(['project']);
            $table->dropColumn('project');
            $table->string('scope', 16)->default('request')->change();
        });
    }

    /** The secret of the global the old code would have used for `$type`, or null. */
    private static function usableGlobal(string $type): ?string
    {
        $entry = SecretVaultEntry::query()
            ->where('scope', SecretVaultEntry::SCOPE_GLOBAL)
            ->where('type', $type)
            ->orderByDesc('id')
            ->first();

        return $entry !== null && $entry->filled_at !== null ? self::trimmed($entry->revealSecret()) : null;
    }

    private static function isReference(?string $value): bool
    {
        return $value !== null && str_starts_with($value, self::PREFIX);
    }

    /** What a stored reference stood for: `vault:global` the fallback global, `vault:<id>` that entry. */
    private static function secretFor(string $reference, ?string $global): ?string
    {
        if ($reference === self::OLD_GLOBAL_REFERENCE) {
            return $global;
        }

        $id = substr($reference, strlen(self::PREFIX));
        $entry = ctype_digit($id) ? SecretVaultEntry::query()->find((int) $id) : null;

        return $entry !== null ? self::trimmed($entry->revealSecret()) : null;
    }

    /** @param array<string, mixed> $details */
    private static function usesCloudflare(User $user, array $details): bool
    {
        if (self::trimmed($details['cloudflare_account_id'] ?? null) !== null || self::trimmed($details['cloudflare_tunnel_id'] ?? null) !== null) {
            return true;
        }

        return Schema::hasTable('tunnels')
            && DB::table('tunnels')->where('user_id', $user->id)->where('provider', 'cloudflare')->exists();
    }

    private static function trimmed(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function truthy(mixed $value): bool
    {
        return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
};
