<?php

namespace App\Lib\Deploy;

use App\Http\Requests\UserStoreRequest;

/**
 * Hosting-account usernames as {@see UserStoreRequest}
 * accepts them: lowercase ASCII letters and digits, starting with a letter,
 * 3–15 characters. Hyphens and dots from a Git repo name are stripped, not
 * kept — a 16-character `saas-starter-ts` is not a valid account name, and
 * appending a 4-digit suffix to a 13-character slug would overflow the cap.
 *
 * No Laravel dependencies — unit-testable with a string.
 */
class HostingUsername
{
    public const MIN_LENGTH = 3;

    public const MAX_LENGTH = 15;

    public const SUFFIX_LENGTH = 4;

    /**
     * Last path segment of a clone URL, normalised to a legal username.
     */
    public static function fromGitRepo(string $repo): ?string
    {
        $repo = strtolower(rtrim($repo, '/'));
        if (strlen($repo) >= 4 && substr($repo, -4) === '.git') {
            $repo = substr($repo, 0, -4);
        }
        $slash = strrpos($repo, '/');
        $base = $slash === false ? $repo : substr($repo, $slash + 1);

        return self::normalize($base);
    }

    /**
     * Strip everything that is not `[a-z0-9]`, drop leading digits so the
     * name still matches `/^[a-z]/`, then cap at {@see MAX_LENGTH}.
     */
    public static function normalize(string $raw): ?string
    {
        $name = strtolower($raw);
        $stripped = preg_replace('/[^a-z0-9]/', '', $name);
        if (! is_string($stripped) || $stripped === '') {
            return null;
        }
        $name = ltrim($stripped, '0123456789');
        if ($name === '') {
            return null;
        }
        if (strlen($name) > self::MAX_LENGTH) {
            $name = substr($name, 0, self::MAX_LENGTH);
        }
        if (strlen($name) < self::MIN_LENGTH) {
            return null;
        }

        return $name;
    }

    /**
     * Truncate so a numeric suffix of {@see SUFFIX_LENGTH} still fits in
     * {@see MAX_LENGTH} — the same rule as clone usernames.
     */
    public static function stemForSuffix(string $base): string
    {
        $maxStem = self::MAX_LENGTH - self::SUFFIX_LENGTH;
        if (strlen($base) <= $maxStem) {
            return $base;
        }

        return substr($base, 0, $maxStem);
    }
}
