<?php

namespace App\Lib\Vault;

use App\Http\Requests\UserStoreRequest;
use App\Models\SecretVaultEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a paste slot, and is the only thing that does -- the API and the
 * console both mint, and must not drift on what a new entry is.
 *
 * The raw ref (the paste URL's token) is returned here once and never stored.
 */
class SecretMinter
{
    /** Bytes of the ref, which doubles as the form URL token. */
    private const REF_BYTES = 48;

    /** Shorter than this and the secret could expire before anyone pastes it. */
    public const MIN_EXPIRY_SECONDS = 60;

    /**
     * A new slot of `$type`, and the raw ref of its paste URL.
     *
     * @param string  $scope   {@see SecretVaultEntry::SCOPES}
     * @param ?string $purpose free text, shown on the form and in listings
     * @param ?array<string, mixed> $verifyWith what the paste is checked against, see {@see PasteCheck}
     * @param ?string $project a project entry's owner from the start; null lets the first project that reads it own it
     * @param ?int    $expiresIn seconds until the secret expires and is purged; null never
     * @return array{0: SecretVaultEntry, 1: string} the entry and its raw ref
     *
     * @throws ValidationException when `$verifyWith` or `$project` is unusable
     */
    public static function mint(
        string $type,
        string $scope,
        ?string $purpose = null,
        ?array $verifyWith = null,
        ?string $project = null,
        ?int $expiresIn = null
    ): array {
        if ($expiresIn !== null && $expiresIn < self::MIN_EXPIRY_SECONDS) {
            throw ValidationException::withMessages([
                'expires_in' => 'A secret must live at least ' . self::MIN_EXPIRY_SECONDS . ' seconds.',
            ]);
        }
        $project = self::trimmedOrNull($project);
        if ($project !== null) {
            self::assertProject($project, $scope);
        }
        $verifyWith = PasteCheck::prepare($type, $verifyWith);
        // Browser-URL entropy: this string is the capability.
        $ref = Str::random(self::REF_BYTES);

        $entry = SecretVaultEntry::create([
            'ref_hash' => SecretVaultEntry::hashRef($ref),
            'type' => $type,
            'scope' => $scope,
            'purpose' => self::trimmedOrNull($purpose),
            'verify_with' => $verifyWith,
            'project' => $project,
            'expires_at' => $expiresIn === null ? null : Carbon::now()->addSeconds($expiresIn),
            'link_expires_at' => Carbon::now()->addSeconds(SecretVaultEntry::TTL_SECONDS),
        ]);

        return [$entry, $ref];
    }

    /**
     * A project name as project_create would accept it. The project need not
     * exist yet: the secret is often asked for before the project is created.
     */
    private static function assertProject(string $project, string $scope): void
    {
        if ($scope !== SecretVaultEntry::SCOPE_PROJECT) {
            throw ValidationException::withMessages([
                'project' => 'Only a `project` secret belongs to a project; a `global` one is usable by any.',
            ]);
        }

        Validator::make(['project' => $project], ['project' => (new UserStoreRequest())->rules()['username']])->validate();
    }

    /** Whitespace is not a purpose; store nothing rather than a blank. */
    private static function trimmedOrNull(?string $value): ?string
    {
        return ($trimmed = trim((string) $value)) !== '' ? $trimmed : null;
    }
}
