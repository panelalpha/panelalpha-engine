<?php

namespace App\Lib\Deploy\Source;

/**
 * What {@see GitRemoteProbe::check()} learned. `problem()` callers only need
 * the problem; the vault form also needs "verified" told apart from "not
 * checked", which a null problem cannot say.
 */
final class GitProbeResult
{
    /** The remote handed over its refs. */
    public const VERIFIED = 'verified';

    /** The remote refused or does not have it: {@see $problem} says which. */
    public const REFUSED = 'refused';

    /** Nothing was learned: host unreachable, timeout, or an engine-side failure. */
    public const UNCHECKED = 'unchecked';

    /** @param ?array<string, mixed> $problem */
    private function __construct(
        public readonly string $outcome,
        public readonly ?array $problem = null,
    ) {
    }

    public static function verified(): self
    {
        return new self(self::VERIFIED);
    }

    /** @param ?array<string, mixed> $problem set when the host did not answer */
    public static function unchecked(?array $problem = null): self
    {
        return new self(self::UNCHECKED, $problem);
    }

    /** @param array<string, mixed> $problem */
    public static function refused(array $problem): self
    {
        return new self(self::REFUSED, $problem);
    }
}
