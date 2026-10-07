<?php

namespace App\Lib\Deploy\Sidecar;

/**
 * The password a database sidecar gets when nobody chose one.
 *
 * It used to be the literal `app`, for the user and root alike. Now it is
 * derived per account and per variable, so MYSQL_PASSWORD and
 * MYSQL_ROOT_PASSWORD differ and neither is guessable from outside.
 *
 * An account whose databases were initialised under the old default keeps
 * `app` ({@see legacy()}): a data directory ignores the MYSQL_ and POSTGRES_
 * variables once it exists, so a new password would lock the app out of its
 * own database.
 */
final class SidecarPasswords
{
    public const LEGACY = 'app';

    /** User details key that records which of the two an account uses. */
    public const DETAILS_KEY = 'sidecar_passwords';

    public const MODE_LEGACY = 'legacy';

    public const MODE_DERIVED = 'derived';

    private function __construct(private readonly ?string $seed)
    {
    }

    public static function legacy(): self
    {
        return new self(null);
    }

    public static function derived(string $seed): self
    {
        return new self($seed);
    }

    /**
     * Which mode an account uses, and whether that is a new decision to store.
     *
     * Decided once and then kept: after the first derived deploy the account
     * has volumes too, and re-deciding from that would flip it to legacy.
     * An account that already holds volumes was deployed under `app`; one
     * whose volumes could not be listed stays legacy without storing it, so
     * the next deploy asks again.
     *
     * @return array{mode: string, store: bool}
     */
    public static function decideMode(?string $stored, ?bool $accountHasVolumes): array
    {
        if ($stored === self::MODE_LEGACY || $stored === self::MODE_DERIVED) {
            return ['mode' => $stored, 'store' => false];
        }
        if ($accountHasVolumes === null) {
            return ['mode' => self::MODE_LEGACY, 'store' => false];
        }

        return ['mode' => $accountHasVolumes ? self::MODE_LEGACY : self::MODE_DERIVED, 'store' => true];
    }

    public static function forMode(string $mode, string $seed): self
    {
        return $mode === self::MODE_DERIVED ? self::derived($seed) : self::legacy();
    }

    public function isLegacy(): bool
    {
        return $this->seed === null;
    }

    /** The value for an init variable (MYSQL_PASSWORD, POSTGRES_PASSWORD, …) nobody set. */
    public function for(string $variable): string
    {
        if ($this->seed === null) {
            return self::LEGACY;
        }

        return substr(hash_hmac('sha256', 'sidecar-password:' . strtoupper($variable), $this->seed), 0, 32);
    }

    /**
     * MySQL's root password beside a non-root user. Legacy gave root the
     * user's own password; derived gives it one of its own.
     */
    public function mysqlRoot(string $userPassword): string
    {
        return $this->seed === null ? $userPassword : $this->for('MYSQL_ROOT_PASSWORD');
    }
}
