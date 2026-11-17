<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Decodes WP-CLI `--format=json` output. A PHP warning, notice or deprecation printed ahead of the JSON (exit code
 * still 0) is dropped before a second attempt; anything else that is not JSON is still an error.
 */
final class JsonOutput
{
    /** Warnings, notices and deprecations: the levels PHP prints for a harmless diagnostic. */
    private const HARMLESS_LEVELS = 'Warning|Notice|Deprecated|Strict Standards';

    /** The shared tail of a PHP diagnostic line: `: message in /path on line N`. */
    private const LINE_TAIL = '):\s+.* in .* on line \d+$/i';

    /** Lines decode() drops before the second JSON attempt: the harmless levels plus fatal and parse errors. */
    private const NOISE = '/^(?:PHP\s+)?(?:'.self::HARMLESS_LEVELS.'|Fatal error|Parse error'.self::LINE_TAIL;

    /**
     * Warnings, notices and deprecations only, with an optional `[timestamp] ` prefix from PHP's error log.
     * A fatal or parse error is a real failure, not noise.
     */
    private const HARMLESS = '/^(?:\[[^\]]*\]\s*)?(?:PHP\s+)?(?:'.self::HARMLESS_LEVELS.self::LINE_TAIL;

    /**
     * Removes blank lines and PHP warning, notice and deprecation lines (with or without a timestamp prefix) from
     * WP-CLI output, so callers can tell whether a real message is left. Fatal and parse errors are kept.
     */
    public static function stripNoise(string $output): string
    {
        $kept = array_filter(
            preg_split('/\r?\n/', $output) ?: [],
            static fn (string $line): bool => trim($line) !== '' && preg_match(self::HARMLESS, trim($line)) !== 1,
        );

        return implode("\n", $kept);
    }

    /**
     * @return array<mixed>
     */
    public static function decode(string $stdout): array
    {
        $decoded = json_decode($stdout, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $lines = preg_split('/\r?\n/', $stdout) ?: [];
        $kept = array_filter(
            $lines,
            static fn (string $line): bool => trim($line) !== '' && preg_match(self::NOISE, trim($line)) !== 1,
        );
        $decoded = json_decode(implode("\n", $kept), true);
        if (! is_array($decoded)) {
            throw new Exception('WP-CLI returned invalid JSON.', 500);
        }

        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function decodeList(string $stdout): array
    {
        /** @var list<array<string, mixed>> */
        return array_values(self::decode($stdout));
    }
}
