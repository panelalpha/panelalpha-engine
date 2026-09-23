<?php

namespace App\Lib\Vault;

use App\Models\SecretVaultEntry;
use Illuminate\Validation\ValidationException;

/**
 * Resolves a `vault:<ref>` riding in a request field into the pasted secret.
 *
 * The API's secret-bearing parameters stay exactly as they are: a caller may
 * send a literal token or a vault reference in the same field, and the call
 * sites cannot tell the difference after this class runs. That is the point --
 * no new parameters, no schema changes, and a transcript or activity log that
 * captures `git_token: vault:abc...` holds a reference, not a secret.
 *
 * Usage, at the HTTP boundary where the request lives:
 *
 *     'git_token' => RequestVault::get('git_token'),
 *
 * The key is both the request field to read and the vault `type` to match --
 * one name, by design, so `RequestVault::get('git_token')` can only ever
 * return an entry that was created for `git_token`.
 *
 * Copy-on-resolve: the plaintext is handed to the caller, which stores it
 * wherever the literal would have gone (`User.details.git_token`, encrypted
 * by the existing accessor). The queue and the deploy pipeline never see the
 * vault, and an entry expiring later cannot affect a project already created.
 *
 * The engine's own secrets ({@see GlobalVault}) are reached two ways, never
 * by surprise: `vault:global` in a field asks for one by name, and
 * {@see getOrGlobal()} lets a *transient* read fall back to one when the
 * field was left out. A stored credential does neither -- it stays absent and
 * inherits at read time, so rotating the engine's token reaches every project
 * that never had one of its own.
 */
class RequestVault
{
    /** What marks a value as a reference rather than a literal secret. */
    public const PREFIX = 'vault:';

    /**
     * `vault:global` -- the engine's own secret for this field's type, asked
     * for by name. No ref, because a global entry is addressed by what it is
     * rather than by a link somebody was handed: the field says the type, so
     * this cannot be aimed at the wrong secret.
     *
     * It is honoured even when {@see GlobalVault::projectScoped()} is on: that
     * setting governs what the engine reaches for on a caller's behalf, not
     * what a caller may ask for outright.
     */
    public const GLOBAL_REF = 'global';

    /**
     * The value of `$field` from the current request, with a vault reference
     * (if any) resolved to its secret.
     *
     * A value without the prefix passes through untouched -- including null
     * and '' (an empty string is how a caller clears a stored credential, and
     * that must keep working). A value with the prefix that has no usable
     * entry is a 422, never a passthrough: storing a typo'd reference as a
     * literal token would surface later as a clone-time auth failure nobody
     * can trace, instead of the plain sentence this raises now.
     *
     * `$type` defaults to the field name, which is right wherever the field and
     * the kind of secret are the same thing. A field that carries several kinds
     * -- `project_setting_set`'s single `value` -- names the type itself; see
     * the note below.
     *
     * {@see get()} uses one name for both by design -- it reads `git_token` and
     * matches a `git_token` entry, so a reference cannot be aimed at the wrong
     * secret by accident. That is right where the field and the kind of secret
     * are the same thing, and wrong where one field carries several kinds of
     * secret: `project_setting_set` has a single `value`, and the *setting key*
     * is what says whether it is a Cloudflare token or something else.
     *
     * Without this the controller would have to ask for `get('value')` and
     * match entries of type `value`, which no entry ever is.
     *
     * @return ?string the plaintext secret, the literal value, or null
     */
    public static function get(string $field, ?string $type = null): ?string
    {
        return self::resolve(request()->input($field), $type ?? $field);
    }

    /**
     * As {@see get()}, but a field the caller left out falls back to the
     * engine's own secret of that type ({@see GlobalVault}).
     *
     * Only for a value that is *used* and then dropped -- a transient clone,
     * say. Not for one that is stored: a project that inherits the engine's
     * token should keep inheriting it, so that rotating the global reaches
     * every project and `project_setting_get` can say the value is inherited.
     * Copying it in at create time would freeze a snapshot and quietly make
     * the project look like it had been given a token of its own.
     *
     * Persisted fields therefore keep {@see get()}, and inherit at read time
     * instead -- {@see \App\Models\User::getGitToken()}.
     *
     * An empty string is not an absent field: it is how a caller clears a
     * credential, and it keeps meaning that.
     */
    public static function getOrGlobal(string $field, ?string $type = null): ?string
    {
        $type ??= $field;
        /** @var mixed $value */
        $value = request()->input($field);

        return $value === null ? GlobalVault::secret($type) : self::resolve($value, $type);
    }

    /**
     * An `env_vars`-shaped map from the current request, with every value
     * that carries the prefix resolved. Same rules as {@see get()}, one type
     * for the whole map -- entries are per-secret, not per-variable, so two
     * variables each need their own reference.
     *
     * @return array<string, string>|null
     */
    public static function envVars(string $field = 'env_vars'): ?array
    {
        $value = request()->input($field);

        if (!is_array($value)) {
            return is_string($value) ? self::resolve($value, $field) : $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = is_string($item) ? self::resolve($item, $field) : $item;
        }

        return $out;
    }

    /**
     * One raw value: prefix check, lookup, and the four ways a reference can
     * be unusable -- each a different sentence, because they need different
     * fixes from the caller.
     *
     * @return ?string the plaintext, the literal value, or null
     */
    private static function resolve(mixed $value, string $type): ?string
    {
        if (!is_string($value) || !str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        $ref = substr($value, strlen(self::PREFIX));

        if ($ref === self::GLOBAL_REF) {
            return self::resolveGlobal($type);
        }

        $entry = SecretVaultEntry::query()
            ->where('ref_hash', SecretVaultEntry::hashRef($ref))
            ->where('type', $type)
            ->first();

        if ($entry === null) {
            self::fail($type, "No vault entry of type '{$type}' for this reference. Create one with vault_secret_create.");
        } elseif ($entry->filled_at === null) {
            self::fail($type, "Vault entry for '{$type}' has no secret pasted yet. Open the URL vault_secret_create returned.");
        } elseif ($entry->expired()) {
            self::fail($type, "Vault entry for '{$type}' expired. Create a new one with vault_secret_create.");
        }

        $secret = $entry->revealSecret();
        if ($secret === null) {
            // Unfilled entries were caught above; this is an undecryptable
            // ciphertext, i.e. an APP_KEY rotation between paste and use.
            self::fail($type, "Vault entry for '{$type}' could not be decrypted (APP_KEY rotated?). Create a new one.");
        }

        // Reusable until TTL: reads are counted, not consumed.
        $entry->forceFill(['use_count' => $entry->use_count + 1, 'last_used_at' => now()])->save();

        return $secret;
    }

    /**
     * `vault:global` -- asked for outright, so an absent or unpasted global
     * is a 422 rather than the silence the implicit fallback answers with.
     * The caller named a secret they believe exists; saying nothing would
     * surface later as an auth failure with no trace back to here.
     */
    private static function resolveGlobal(string $type): string
    {
        $entry = SecretVaultEntry::globalFor($type);

        if ($entry === null) {
            self::fail($type, "No global vault entry for '{$type}'. Create one with vault_secret_create (scope: global).");
        } elseif ($entry->filled_at === null) {
            self::fail($type, "The global vault entry for '{$type}' has no secret pasted yet. Open the URL vault_secret_create returned.");
        }

        $secret = GlobalVault::secret($type, force: true);

        if ($secret === null) {
            self::fail($type, "The global vault entry for '{$type}' could not be decrypted (APP_KEY rotated?). Create a new one.");
        }

        return $secret;
    }

    /**
     * A 422 naming the field, so the caller sees the mistake next to where
     * they made it.
     */
    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}