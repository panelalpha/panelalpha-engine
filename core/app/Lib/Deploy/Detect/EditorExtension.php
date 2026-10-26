<?php

namespace App\Lib\Deploy\Detect;

/**
 * Editor extensions (VS Code / Cursor / Theia) are Node packages but not HTTP
 * servers. Railpack builds them happily, then the container crashes on
 * `require('vscode')`.
 */
final class EditorExtension
{
    private const ENTRYPOINT_PATTERN = '#(?:^|/)extension\.js$#i';

    /**
     * @param  array<string, mixed>  $package  parsed package.json
     */
    public static function describes(array $package): bool
    {
        return $package !== []
            && (self::declaresEditorEngine($package) || self::hasExtensionEntrypoint($package));
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private static function declaresEditorEngine(array $package): bool
    {
        $vscode = $package['engines']['vscode'] ?? null;

        return $vscode !== null && $vscode !== '';
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private static function hasExtensionEntrypoint(array $package): bool
    {
        $main = $package['main'] ?? null;

        return is_string($main) && preg_match(self::ENTRYPOINT_PATTERN, $main) === 1;
    }
}
