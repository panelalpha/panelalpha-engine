<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Decodes WP-CLI `--format=json` output.
 *
 * A PHP warning, notice or deprecation printed to stdout ahead of the JSON
 * (display_errors on, exit code still 0) would make a strict decode fail.
 * Such lines are dropped before a second attempt; anything else that is not
 * JSON is still an error.
 */
final class JsonOutput
{
    private const NOISE = '/^(?:PHP\s+)?(?:Warning|Notice|Deprecated|Strict Standards|Fatal error|Parse error):\s+.* in .* on line \d+$/i';

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
