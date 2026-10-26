<?php

namespace App\System\Project\Git;

final class Path
{
    public static function key(string $absoluteOrRelative, string $homeDir): string
    {
        $home = rtrim(str_replace('\\', '/', $homeDir), '/');
        $path = str_replace('\\', '/', $absoluteOrRelative);
        if ($path !== '' && ($path[0] ?? '') === '/') {
            if ($path === $home || str_starts_with($path, $home.'/')) {
                $path = substr($path, strlen($home));
            }
        } elseif (preg_match('#^[A-Za-z]:/#', $path) && ($path === $home || str_starts_with($path, $home.'/'))) {
            $path = substr($path, strlen($home));
        }
        $path = trim($path, '/');

        return $path;
    }
}
