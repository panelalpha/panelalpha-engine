<?php

namespace App\Lib\Deploy\Telemetry;

/**
 * A stable grouping key for a deploy failure, so fifty installs hitting one broken recipe collapse into one group: the
 * rule, the strategy and the shape of the error text, not what differs per machine. No Laravel dependencies.
 */
class Fingerprint
{
    public const LENGTH = 32;

    /** Longest normalised signature that contributes to the hash. */
    private const SIGNATURE_BUDGET = 400;

    /** One DNS label, as it appears in a hostname this class may fold. */
    private const HOST_LABEL = '[a-z0-9_][a-z0-9_-]*';

    /** A dotted name ending in something that reads as a top-level domain. */
    private const HOST_NAME = '(?:'.self::HOST_LABEL.'\.)+[a-z]{2,63}';

    /** A two-letter last label reads as a country-code top-level domain. */
    private const COUNTRY_CODE_LENGTH = 2;

    /**
     * Longer last labels that read as a top-level domain. Kept short on purpose: a
     * dotted code identifier (`module.exports`, `logging.info`) must not fold as a host.
     *
     * @var list<string>
     */
    private const GENERIC_TLDS = [
        'app', 'arpa', 'biz', 'cloud', 'com', 'dev', 'edu', 'gov', 'internal',
        'invalid', 'localhost', 'net', 'online', 'org', 'pro', 'site', 'tech', 'test',
        'tools', 'xyz',
    ];

    /**
     * A last label in this list is a file extension, so two files stay two signatures.
     *
     * @var list<string>
     */
    private const FILE_EXTENSIONS = [
        'a', 'c', 'cc', 'cfg', 'cjs', 'class', 'conf', 'cpp', 'cs', 'css', 'csv',
        'db', 'dll', 'eot', 'env', 'gif', 'go', 'gz', 'h', 'hpp', 'htm', 'html',
        'ico', 'ini', 'jar', 'java', 'jpeg', 'jpg', 'js', 'json', 'jsx', 'kt',
        'less', 'lock', 'log', 'map', 'md', 'mjs', 'mp3', 'mp4', 'o', 'pdf',
        'php', 'png', 'py', 'rb', 'rs', 'sass', 'scss', 'sh', 'so', 'sql', 'svg',
        'tar', 'tgz', 'toml', 'ts', 'tsx', 'ttf', 'txt', 'wasm', 'webm', 'webp',
        'woff', 'woff2', 'xml', 'xz', 'yaml', 'yml', 'zip',
    ];

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
     * Reduce a build failure's text to the part that is the same everywhere: no BuildKit step prefixes, digests and other
     * per-build ids, per-account paths, durations, sizes, ports or line numbers.
     */
    public static function normalize(string $signature): string
    {
        $text = (string) preg_replace('/\x1B(?:\[[0-9;?]*[A-Za-z]|\][^\x07]*\x07)/', '', $signature);

        $text = (string) preg_replace('/^#\d+\s+(?:[\d.]+\s+)?/m', '', $text);

        $text = (string) preg_replace('/\bsha(?:256|512):[A-Fa-f0-9]+/', '<digest>', $text);
        $text = (string) preg_replace(
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i',
            '<uuid>',
            $text
        );
        $text = (string) preg_replace('/\b[A-Fa-f0-9]{8,}\b/', '<hex>', $text);

        $text = self::stripUrls($text);
        $text = self::stripHosts($text);
        $text = self::stripAddresses($text);

        $text = (string) preg_replace('#\B/(?:home|tmp|var|usr|opt|root|proc)/[^\s"\',:;)]*#', '<path>', $text);

        $text = (string) preg_replace('/\b\d+(?:\.\d+)?(?:ms|s|kb|mb|gb|kib|mib|gib)\b/i', '<size>', $text);
        $text = (string) preg_replace('/\b\d+\b/', '<n>', $text);

        $text = strtolower(trim((string) preg_replace('/\s+/', ' ', $text)));

        if (strlen($text) > self::SIGNATURE_BUDGET) {
            $text = substr($text, 0, self::SIGNATURE_BUDGET);
        }

        return $text;
    }

    /**
     * URLs name the site's own domain and path, except a registry API URL, where
     * which registry refused the image is what the failure is about.
     */
    private static function stripUrls(string $text): string
    {
        return (string) preg_replace_callback(
            '#\b([a-z][a-z0-9+.-]*)://([^\s"\'<>`]+)#i',
            static function (array $match): string {
                if (preg_match('#^([^\s"\'<>`/]+)/v2/#i', $match[2], $registry) === 1) {
                    return $match[1].'://'.$registry[1].'/<path>';
                }

                return '<url>';
            },
            $text
        );
    }

    /**
     * A hostname is per-install noise like a path, but in an image or repository
     * reference the host says which registry refused, so it stays.
     */
    private static function stripHosts(string $text): string
    {
        $text = (string) preg_replace_callback(
            '#(?<![\w@/.-])('.self::HOST_NAME.')((?::\d+)?)/[^\s"\'<>`]+#i',
            static fn (array $match): string => self::isHostName($match[1])
                ? $match[1].$match[2].'/<path>'
                : $match[0],
            $text
        );

        return (string) preg_replace_callback(
            '#(?<![\w@/.-])'.self::HOST_NAME.'(?![\w/-]|\.\w|:\d+/)#i',
            static fn (array $match): string => self::isHostName($match[0]) ? '<host>' : $match[0],
            $text
        );
    }

    /**
     * A lowercase dotted name whose last label is a top-level domain rather than a
     * file extension; mixed case is a class or namespace (`Microsoft.NET.Sdk`).
     */
    private static function isHostName(string $candidate): bool
    {
        if ($candidate !== strtolower($candidate)) {
            return false;
        }
        $labels = explode('.', $candidate);
        $tld = (string) end($labels);

        return (strlen($tld) === self::COUNTRY_CODE_LENGTH || in_array($tld, self::GENERIC_TLDS, true))
            && ! in_array($tld, self::FILE_EXTENSIONS, true);
    }

    /**
     * An address literal identifies the host the report came from, and folding an
     * IPv4 one into four numbers still leaves two installs in two groups.
     */
    private static function stripAddresses(string $text): string
    {
        $text = (string) preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '<ip>', $text);
        $text = (string) preg_replace('/\b(?:[0-9a-f]{1,4}:){3,7}[0-9a-f]{1,4}\b/i', '<ip>', $text);

        return (string) preg_replace_callback(
            '/(?<![\w:.])(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4})*)?::(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4})*)?(?![0-9a-f:.])/i',
            static fn (array $match): string => preg_match('/[0-9a-f]/i', $match[0]) === 1 ? '<ip>' : $match[0],
            $text
        );
    }

    /**
     * A per-account correlation key that is not a username: "the same account failed nine times" without saying who. Salted
     * with the install id, so one username on two installs does not collide and a dictionary of usernames cannot reverse it.
     */
    public static function account(string $installId, string $username): string
    {
        return substr(hash('sha256', $installId.'|'.$username), 0, 16);
    }
}
