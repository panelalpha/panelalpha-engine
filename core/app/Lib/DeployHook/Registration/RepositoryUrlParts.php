<?php

namespace App\Lib\DeployHook\Registration;

/** The pieces of an https repository URL every registrar reads the same way. */
trait RepositoryUrlParts
{
    /** @return array<string, mixed>|null the parsed URL, when it is https */
    private static function httpsParts(string $repoUrl): ?array
    {
        $parts = parse_url(trim($repoUrl));
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }

        return $parts;
    }

    /** @return array<string, mixed>|null the parsed URL, when it is https on a named host and carries only a path */
    private static function plainHttpsParts(string $repoUrl): ?array
    {
        $parts = self::httpsParts($repoUrl);
        if ($parts === null || self::lowerHost($parts) === '' || self::carriesMoreThanAPath($parts)) {
            return null;
        }

        return $parts;
    }

    /** @param  array<string, mixed>  $parts */
    private static function lowerHost(array $parts): string
    {
        return strtolower((string) ($parts['host'] ?? ''));
    }

    /** @param  array<string, mixed>  $parts */
    private static function carriesMoreThanAPath(array $parts): bool
    {
        return isset($parts['user']) || isset($parts['query']) || isset($parts['fragment']);
    }

    /**
     * @param  array<string, mixed>  $parts
     * @return ?string `owner/name` from a two-segment path, without `.git` or a trailing slash
     */
    private static function ownerAndName(array $parts): ?string
    {
        if (preg_match('#^/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#', (string) ($parts['path'] ?? ''), $m) !== 1) {
            return null;
        }

        return $m[1].'/'.$m[2];
    }
}
