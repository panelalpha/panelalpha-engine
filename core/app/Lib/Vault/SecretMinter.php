<?php

namespace App\Lib\Vault;

use App\Models\SecretVaultEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a paste slot, and is the only thing that does.
 *
 * The API and the console both mint, and the rules they have to agree on are
 * the ones it would be worst to get half right: one global per type, and a
 * stored secret is never reachable by a new link. A second copy of that
 * logic is a second chance for one of them to quietly allow an override, so
 * there is one copy and both callers go through it.
 *
 * What stays with the caller is presentation -- the API shapes JSON, the
 * console prints a table -- and the raw ref, which is returned here once and
 * never stored anywhere.
 */
class SecretMinter
{
    /** Bytes of the ref, which doubles as the form URL token. */
    private const REF_BYTES = 48;

    /**
     * A new slot of `$type`, and the raw ref that addresses it.
     *
     * @param string  $scope   {@see SecretVaultEntry::SCOPES}
     * @param ?string $purpose free text, shown on the form and in listings
     * @param ?array<string, mixed> $verifyWith what the paste is checked against, see {@see PasteCheck}
     * @return array{0: SecretVaultEntry, 1: string} the entry and its raw ref
     *
     * @throws ValidationException when a global of this type already holds a
     *         secret -- see {@see mintGlobal()} -- or `$verifyWith` is unusable
     */
    public static function mint(string $type, string $scope, ?string $purpose = null, ?array $verifyWith = null): array
    {
        $verifyWith = PasteCheck::prepare($type, $verifyWith);
        // Browser-URL entropy: this string is the capability.
        $ref = Str::random(self::REF_BYTES);
        $linkExpiresAt = Carbon::now()->addSeconds(SecretVaultEntry::TTL_SECONDS);
        $purpose = self::trimmedOrNull($purpose);

        $entry = $scope === SecretVaultEntry::SCOPE_GLOBAL
            ? self::mintGlobal($type, $ref, $linkExpiresAt, $purpose, $verifyWith)
            : SecretVaultEntry::create([
                'ref_hash' => SecretVaultEntry::hashRef($ref),
                'type' => $type,
                'scope' => SecretVaultEntry::SCOPE_REQUEST,
                'purpose' => $purpose,
                'verify_with' => $verifyWith,
                'link_expires_at' => $linkExpiresAt,
                'expires_at' => $linkExpiresAt,
            ]);

        return [$entry, $ref];
    }

    /**
     * The engine's entry for this type: the existing row if there is one and
     * nothing has been pasted into it yet, otherwise a new one.
     *
     * There is one global per type, so a re-mint reuses the row and rotates
     * its paste link rather than adding a second. What it will not do is
     * reach a secret that is already stored: that entry is sealed, and a new
     * link over the top of it would be an override by another name.
     * Replacing the engine's stored credential means deleting it first,
     * deliberately.
     *
     * @param ?array<string, string> $verifyWith
     * @throws ValidationException when a secret of this type is already set
     */
    private static function mintGlobal(
        string $type,
        string $ref,
        Carbon $linkExpiresAt,
        ?string $purpose,
        ?array $verifyWith
    ): SecretVaultEntry
    {
        $entry = SecretVaultEntry::globalFor($type);

        if ($entry !== null && $entry->isSealed()) {
            throw ValidationException::withMessages([
                'scope' => "A global secret for '{$type}' is already set and cannot be overwritten. "
                    . "Delete it first (ref 'global:{$type}'), then create a new one.",
            ]);
        }

        $entry ??= new SecretVaultEntry(['type' => $type]);

        $entry->forceFill([
            'type' => $type,
            'scope' => SecretVaultEntry::SCOPE_GLOBAL,
            // A re-mint restates why, so an abandoned one does not leave the
            // wrong reason attached to the link somebody actually uses.
            'purpose' => $purpose,
            'verify_with' => $verifyWith,
            'ref_hash' => SecretVaultEntry::hashRef($ref),
            'link_expires_at' => $linkExpiresAt,
            'expires_at' => null,
        ])->save();

        return $entry;
    }

    /** Whitespace is not a purpose; store nothing rather than a blank. */
    private static function trimmedOrNull(?string $value): ?string
    {
        return ($trimmed = trim((string) $value)) !== '' ? $trimmed : null;
    }
}
