<?php

namespace App\Lib\Vault;

use App\Models\SecretVaultEntry;
use Illuminate\Validation\ValidationException;

/**
 * The current request's fields, with `vault:<id>` read in place of a secret.
 *
 * The vault only proxies what a caller passes: a controller reads its request
 * parameter here and stores the secret it gets back. Nothing else reads the
 * vault -- no integration looks a secret up on a project's behalf.
 */
class RequestVault
{
    /**
     * Request field `$field` as `$project` may use it. A literal (or null, or
     * '') passes through; `vault:<id>` becomes its secret; an array field
     * (`env_vars`) has each value read the same way.
     *
     * - the entry must have been created for `$type`, the field name unless
     *   given -- so a Git token cannot be spent as a Cloudflare token;
     * - a `global` entry is returned to anyone;
     * - a `project` entry with no project yet is assigned to `$project` and
     *   returned; one already assigned to another project is refused.
     *
     * `$project` null is a read outside any project (source_inspect): a
     * project entry no project owns yet may be used there, and stays
     * unassigned until a project reads it; one a project owns is refused.
     *
     * @return mixed the field's value, with every reference read
     * @throws ValidationException naming `$field` when a reference cannot be used
     */
    public static function get(string $field, ?string $project, ?string $type = null): mixed
    {
        return self::resolve($field, request()->input($field), $project, $type);
    }

    /**
     * {@see get()} for a value the caller already holds, such as a create
     * that did not come in as a request.
     *
     * @return mixed the value, with every reference read
     * @throws ValidationException naming `$field` when a reference cannot be used
     */
    public static function resolve(string $field, mixed $value, ?string $project, ?string $type = null): mixed
    {
        $type ??= $field;

        return is_array($value)
            ? array_map(fn (mixed $item) => self::read($item, $project, $type, $field), $value)
            : self::read($value, $project, $type, $field);
    }

    private static function read(mixed $value, ?string $project, string $type, string $field): mixed
    {
        if (!self::isReference($value)) {
            return $value;
        }

        $entry = self::entry($value);
        $ref = trim((string) $value);

        if ($entry === null) {
            self::fail($field, "No vault entry for '{$ref}'. Use the `vault:<id>` vault_secret_create returned.");
        }
        if ($entry->type !== $type) {
            self::fail($field, "Vault entry {$ref} holds a '{$entry->type}', not a '{$type}'.");
        }
        if ($entry->expired()) {
            self::fail($field, "Vault entry {$ref} expired at {$entry->expires_at?->toIso8601String()}. Create a new one.");
        }
        if ($entry->filled_at === null) {
            self::fail($field, "Vault entry {$ref} has no secret pasted yet. Open the URL vault_secret_create returned.");
        }

        if (!$entry->isGlobal()) {
            if ($entry->project === null && $project !== null) {
                // Conditional, so two projects racing for one entry cannot both win.
                SecretVaultEntry::query()->whereKey($entry->id)->whereNull('project')->update(['project' => $project]);
                $entry->refresh();
            }
            if ($entry->project !== null && $entry->project !== $project) {
                self::fail($field, "Vault entry {$ref} belongs to project '{$entry->project}'.");
            }
        }

        $secret = $entry->revealSecret();
        if ($secret === null || $secret === '') {
            self::fail($field, "Vault entry {$ref} could not be decrypted (APP_KEY rotated?). Create a new one.");
        }

        $entry->forceFill(['use_count' => $entry->use_count + 1, 'last_used_at' => now()])->save();

        return $secret;
    }

    /** Whether `$value` is meant as a vault reference -- usable or not. */
    public static function isReference(mixed $value): bool
    {
        return is_string($value) && str_starts_with(trim($value), SecretVaultEntry::PREFIX);
    }

    /** The entry a reference names, whatever its state; null for a literal or an unknown id. */
    public static function entry(?string $value): ?SecretVaultEntry
    {
        $id = self::isReference($value) ? SecretVaultEntry::idFromReference(trim((string) $value)) : null;

        return $id === null ? null : SecretVaultEntry::query()->find($id);
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
