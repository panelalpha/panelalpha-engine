<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\AccountHomeRead;

/**
 * `VITE_X || 'http://localhost:3001'` built as the app's own address: Vite bakes it in, and localhost is the visitor's machine.
 */
final class ViteOwnUrlEnv
{
    private const SOURCE_DIRECTORY = 'src';

    private const EXTENSIONS = ['js', 'jsx', 'mjs', 'ts', 'tsx', 'mts', 'vue', 'svelte'];

    private const MAX_FILES = 3000;

    private const MAX_BYTES = 524288;

    /** A VITE_ name and, before the statement ends, a localhost address in quotes. */
    private const FALLBACK = '/\b(VITE_[A-Z0-9_]+)\b[^;]{0,300}?([\'"`])((?:https?|wss?):\/\/(?:localhost|127\.0\.0\.1|0\.0\.0\.0)(?::\d+)?(\/[^\'"`\s$]*)?)\2/s';

    private const LOCAL_ADDRESS = '#^(?:https?|wss?)://(?:localhost|127\.0\.0\.1|0\.0\.0\.0)(?::\d+)?(?:/|$)#i';

    /**
     * The fallback's path and ws scheme kept; a real address the project gives wins.
     *
     * @param  array<string, mixed>  $existing  the values the build sees already
     * @return array<string, string>
     */
    public static function values(string $appDir, ?string $publicUrl, array $existing): array
    {
        $own = self::origin($publicUrl);
        if ($own === null) {
            return [];
        }

        $values = [];
        foreach (self::fallbacks($appDir) as $name => $fallback) {
            $current = trim((string) ($existing[$name] ?? ''));
            if ($current !== '' && preg_match(self::LOCAL_ADDRESS, $current) !== 1) {
                continue;
            }
            $values[$name] = self::ownAddress($own, $fallback);
        }
        ksort($values);

        return $values;
    }

    /**
     * @return array<string, string> the first localhost fallback each name has
     */
    public static function fallbacks(string $appDir): array
    {
        $fallbacks = [];
        foreach (self::sourceFiles(rtrim($appDir, '/').'/'.self::SOURCE_DIRECTORY) as $file) {
            $source = AccountHomeRead::contents($file, self::MAX_BYTES);
            if (! is_string($source) || ! str_contains($source, 'VITE_') || preg_match_all(self::FALLBACK, $source, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }
            foreach ($matches as $match) {
                $fallbacks[$match[1]] ??= $match[3];
            }
        }

        return $fallbacks;
    }

    /**
     * @return array{scheme: string, origin: string}|null
     */
    private static function origin(?string $publicUrl): ?array
    {
        $url = trim((string) $publicUrl);
        if (preg_match('#^(https?)://([^/?\#]+)#i', $url, $m) !== 1) {
            return null;
        }

        return ['scheme' => strtolower($m[1]), 'origin' => strtolower($m[1]).'://'.$m[2]];
    }

    /**
     * @param  array{scheme: string, origin: string}  $own
     */
    private static function ownAddress(array $own, string $fallback): string
    {
        $path = (string) preg_replace('#^[a-z]+://[^/]+#i', '', $fallback);
        if (! str_starts_with(strtolower($fallback), 'ws')) {
            return $own['origin'].$path;
        }
        $scheme = $own['scheme'] === 'https' ? 'wss' : 'ws';

        return $scheme.substr($own['origin'], strlen($own['scheme'])).$path;
    }

    /**
     * @return list<string>
     */
    private static function sourceFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (count($files) >= self::MAX_FILES) {
                break;
            }
            if ($file instanceof \SplFileInfo && $file->isFile()
                && in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)
            ) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
