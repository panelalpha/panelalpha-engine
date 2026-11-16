<?php

namespace App\System\Project;

/** The entry directly in a home that a path below it lies under: what root makes for the account. */
final class HomeEntry
{
    /** Null when $relative names the home itself. */
    public static function under(string $home, string $relative): ?string
    {
        foreach (explode('/', $relative) as $segment) {
            if ($segment !== '' && $segment !== '.') {
                return rtrim($home, '/').'/'.$segment;
            }
        }

        return null;
    }
}
