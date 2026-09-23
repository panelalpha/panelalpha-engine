<?php

namespace App\Lib\Vault;

use App\Models\SecretVaultEntry;
use App\Models\Setting;

/**
 * The engine's own credentials: one secret per type, pasted once, used by
 * every project that does not carry its own.
 *
 * A Git token and a Cloudflare API token are per-project because the engine
 * was built for shared hosting, where two projects belong to two customers
 * and must not see each other's credentials. A solo developer running their
 * own engine has the opposite problem: the same token, re-pasted at every
 * project. This is the other half -- paste it once at engine scope and every
 * project that has none of its own uses it.
 *
 * Which half is in force is one setting, {@see SETTING_PROJECT_SCOPED}, off
 * by default (so tokens are shared). Turning it on restores the old behaviour
 * exactly: a project sees only what was set on it, and a global entry is
 * inert -- still stored, still resolvable by an explicit `vault:global`, but
 * never reached for on a project's behalf.
 *
 * Reading a global secret is a fallback, never an override: a project that
 * has its own token keeps using it, so nothing an operator set by hand is
 * quietly replaced from underneath.
 */
class GlobalVault
{
    /**
     * `1` keeps every token on the project that was given it -- no global
     * fallback. Absent or `0` (the default) shares the engine's globals.
     */
    public const SETTING_PROJECT_SCOPED = 'vault-project-scoped-tokens';

    /**
     * Whether a project without a credential of its own may fall back to the
     * engine's.
     */
    public static function sharingEnabled(): bool
    {
        return !self::projectScoped();
    }

    public static function projectScoped(): bool
    {
        return self::truthy(Setting::get(self::SETTING_PROJECT_SCOPED));
    }

    public static function setProjectScoped(bool $scoped): void
    {
        Setting::set(self::SETTING_PROJECT_SCOPED, $scoped ? '1' : '0');
    }

    /**
     * The engine's secret for `$type`, or null when there is none, none has
     * been pasted yet, or sharing is off.
     *
     * The read is counted like any other ({@see RequestVault}), so
     * `vault_secret_list` shows a global being used rather than looking
     * abandoned.
     *
     * @param bool $force read it even when sharing is off -- for an explicit
     *        `vault:global`, which is the caller asking for this secret by
     *        name rather than the engine reaching for it on their behalf
     */
    public static function secret(string $type, bool $force = false): ?string
    {
        if (!$force && !self::sharingEnabled()) {
            return null;
        }

        $entry = SecretVaultEntry::globalFor($type);

        if ($entry === null || $entry->filled_at === null) {
            return null;
        }

        $secret = $entry->revealSecret();

        if ($secret === null || $secret === '') {
            // An undecryptable ciphertext (APP_KEY rotated). A fallback has no
            // caller to explain itself to, so it stays quiet and the project
            // behaves as it did before there was a global.
            return null;
        }

        $entry->forceFill(['use_count' => $entry->use_count + 1, 'last_used_at' => now()])->save();

        return $secret;
    }

    /**
     * The secret that will actually be used, given what the caller supplied.
     *
     * For the steps that have to act on a credential before the project that
     * would inherit it exists -- the pre-create remote probe, say. A field the
     * caller left out (null) inherits; anything they sent, including the empty
     * string that means "no credential", is theirs and is returned untouched.
     *
     * This is a read, not a write: the answer is used and dropped. What gets
     * stored on the project stays whatever the caller sent, so the project
     * keeps inheriting and a later rotation still reaches it.
     */
    public static function effective(?string $supplied, string $type): ?string
    {
        return $supplied ?? self::secret($type);
    }

    /**
     * Whether a usable global secret of this type exists, without reading it.
     * For the places that only report presence.
     */
    public static function has(string $type): bool
    {
        if (!self::sharingEnabled()) {
            return false;
        }

        return SecretVaultEntry::globalFor($type)?->filled_at !== null;
    }

    private static function truthy(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
