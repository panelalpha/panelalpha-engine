<?php

namespace App\Lib\Vault;

use App\Models\SecretVaultEntry;
use Illuminate\Validation\ValidationException;

/**
 * The current request's fields, with `vault:<key>` read in place of a secret. The vault only proxies what a caller passes:
 * nothing else reads it, and no integration looks a secret up on a project's behalf.
 */
class RequestVault
{
    /**
     * Request field `$field` as `$project` may use it: a literal (or null, or '') passes through, `vault:<key>` becomes
     * its secret, and an array field (`env_vars`) has each value read the same way.
     *
     * @return mixed the field's value, with every reference read
     *
     * @throws ValidationException naming `$field` when a reference cannot be used
     */
    public static function get(string $field, ?string $project, ?string $type = null): mixed
    {
        return self::resolve($field, request()->input($field), $project, $type);
    }

    /**
     * {@see get()} for a value the caller already holds, such as a create
     * that did not come in as a request. `$type` may list several types the
     * entry may have been created for.
     *
     * @param  string|list<string>|null  $type
     * @return mixed the value, with every reference read
     *
     * @throws ValidationException naming `$field` when a reference cannot be used
     */
    public static function resolve(string $field, mixed $value, ?string $project, string|array|null $type = null): mixed
    {
        $types = (array) ($type ?? $field);

        return is_array($value)
            ? array_map(fn (mixed $item) => self::read($item, $project, $types, $field), $value)
            : self::read($value, $project, $types, $field);
    }

    /** @param  list<string>  $types */
    private static function read(mixed $value, ?string $project, array $types, string $field): mixed
    {
        if (! self::isReference($value)) {
            return $value;
        }

        $entry = self::entry($value);
        $ref = trim((string) $value);

        if ($entry === null) {
            self::fail($field, "No vault entry for '{$ref}'. Use the `vault:<key>` the vault returned when the secret was created.");
        }
        self::assertUsable($entry, $ref, $types, $field);

        self::claim($entry, $project, $ref, $field);

        $secret = $entry->revealSecret();
        if ($secret === null || $secret === '') {
            self::fail($field, "Vault entry {$ref} could not be decrypted (APP_KEY rotated?). Create a new one.");
        }

        $entry->forceFill(['use_count' => $entry->use_count + 1, 'last_used_at' => now()])->save();

        return $secret;
    }

    /**
     * The entry must have been created for `$type`, the field name unless given, so a Git token cannot be spent as a
     * Cloudflare token.
     *
     * @param  list<string>  $types
     */
    private static function assertUsable(SecretVaultEntry $entry, string $ref, array $types, string $field): void
    {
        if (! in_array($entry->type, $types, true)) {
            self::fail($field, "Vault entry {$ref} holds a '{$entry->type}', not a '".implode("' or '", $types)."'.");
        }
        if ($entry->expired()) {
            self::fail($field, "Vault entry {$ref} expired at {$entry->expires_at?->toIso8601String()}. Create a new one.");
        }
        if ($entry->filled_at === null) {
            self::fail($field, "Vault entry {$ref} has no secret pasted yet. Open the paste URL the vault returned when the secret was created.");
        }
    }

    /**
     * A global entry serves anyone. A project entry goes to the first project that reads it, conditionally so two racing
     * cannot both win, and is refused to others; read with no project (source_inspect), an unowned one stays unassigned.
     */
    private static function claim(SecretVaultEntry $entry, ?string $project, string $ref, string $field): void
    {
        if (! $entry->isGlobal()) {
            if ($entry->project === null && $project !== null) {
                SecretVaultEntry::query()->whereKey($entry->id)->whereNull('project')->update(['project' => $project]);
                $entry->refresh();
            }
            if ($entry->project !== null && $entry->project !== $project) {
                self::fail($field, "Vault entry {$ref} belongs to project '{$entry->project}'.");
            }
        }
    }

    /** Whether `$value` is meant as a vault reference -- usable or not. */
    public static function isReference(mixed $value): bool
    {
        return is_string($value) && str_starts_with(trim($value), SecretVaultEntry::PREFIX);
    }

    /**
     * The keys of an array field (`env_vars`) whose value is a `vault:<key>`.
     *
     * @param  array<mixed>  $values
     * @return list<string>
     */
    public static function referencedKeys(array $values): array
    {
        return array_values(array_filter(
            array_keys(array_filter($values, self::isReference(...))),
            'is_string'
        ));
    }

    /** The entry a reference names, whatever its state; null for a literal or an unknown key. */
    public static function entry(?string $value): ?SecretVaultEntry
    {
        return self::isReference($value) ? SecretVaultEntry::findByReference(trim((string) $value)) : null;
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
