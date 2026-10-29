<?php

namespace App\Lib\Deploy\Telemetry;

/**
 * A stable grouping key for a deploy failure.
 *
 * Fifty installs hitting one broken recipe must collapse into one issue on the
 * receiving end, or the inbox is useless. What makes two failures "the same"
 * is the rule that recognised them, the strategy they were built with, and the
 * shape of the error text — not the digests, paths, ports and durations that
 * differ on every machine. Normalisation strips exactly those.
 *
 * No Laravel dependencies — unit-testable.
 */
class Fingerprint
{
    public const LENGTH = 32;

    /** Longest normalised signature that contributes to the hash. */
    private const SIGNATURE_BUDGET = 400;

    public static function of(
        ?string $rule,
        ?string $strategy,
        ?string $runtime,
        string $signature
    ): string {
        $parts = [
            $rule ?? 'unexplained',
            $strategy ?? 'unknown',
            $runtime ?? 'none',
            self::normalize($signature),
        ];

        return substr(hash('sha256', implode('|', $parts)), 0, self::LENGTH);
    }

    /**
     * Reduce a build failure's text to the part that is the same everywhere.
     */
    public static function normalize(string $signature): string
    {
        $text = (string) preg_replace('/\x1B(?:\[[0-9;?]*[A-Za-z]|\][^\x07]*\x07)/', '', $signature);

        // BuildKit step/duration prefixes: "#12 34.5 " and "#12 ".
        $text = (string) preg_replace('/^#\d+\s+(?:[\d.]+\s+)?/m', '', $text);

        // Content addresses and other per-build identifiers.
        $text = (string) preg_replace('/\bsha(?:256|512):[A-Fa-f0-9]+/', '<digest>', $text);
        $text = (string) preg_replace(
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i',
            '<uuid>',
            $text
        );
        $text = (string) preg_replace('/\b[A-Fa-f0-9]{8,}\b/', '<hex>', $text);

        // Filesystem paths differ per account and per temp dir.
        $text = (string) preg_replace('#\B/(?:home|tmp|var|usr|opt|root|proc)/[^\s"\',:;)]*#', '<path>', $text);

        // Durations, sizes, ports, line numbers.
        $text = (string) preg_replace('/\b\d+(?:\.\d+)?(?:ms|s|kb|mb|gb|kib|mib|gib)\b/i', '<size>', $text);
        $text = (string) preg_replace('/\b\d+\b/', '<n>', $text);

        $text = strtolower(trim((string) preg_replace('/\s+/', ' ', $text)));

        if (strlen($text) > self::SIGNATURE_BUDGET) {
            $text = substr($text, 0, self::SIGNATURE_BUDGET);
        }

        return $text;
    }

    /**
     * A per-account correlation key that is not a username.
     *
     * Lets the receiving end see "the same account failed nine times in a row"
     * without learning who that account is. Salted with the install id so the
     * same username on two different installs does not collide, and so the
     * value cannot be reversed with a dictionary of common usernames.
     */
    public static function account(string $installId, string $username): string
    {
        return substr(hash('sha256', $installId.'|'.$username), 0, 16);
    }
}
