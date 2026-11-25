<?php

namespace App\Lib\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Template\Template;
use App\Lib\Deploy\Template\TemplateLoader;

/**
 * The nginx configs the static recipes ship: a single-page app rewrites every path that is not a file to index.html, while a
 * site of documents must not, as rewriting a typo to the front page turns a 404 into a page that says the wrong thing.
 */
final class NginxConfig
{
    public const FILENAME = 'panelalpha.nginx.conf';

    /**
     * A path nginx can be handed inside double quotes: relative, no traversal, and none of the characters that would end the
     * string. Spaces and parentheses are allowed: `plan (1).html` is a real deployed filename.
     */
    /** `/docs`, `/a/b`: segments of URL-safe characters, none `.` or `..`. */
    private const SAFE_BASE_PATH = '/^(?:\/(?!\.{1,2}(?:\/|$))[A-Za-z0-9._~-]+)+$/';

    private const SAFE_ENTRY = '/^(?!\/)(?!.*\.\.)[^\x00-\x1f"\'\\\\$;{}]+$/u';

    /** The single-page-app config: same contents for every account. */
    public static function contents(): string
    {
        return TemplateLoader::asset('nginx.conf');
    }

    /** The engine's "Project not configured" page as the answer to every path, with a 404. */
    public static function placeholder(): string
    {
        return TemplateLoader::asset('nginx-placeholder.conf');
    }

    /**
     * `$entry` goes from the customer's repository into a config file, so anything not plainly a relative filename is dropped
     * rather than escaped; nginx then serves index.html as before.
     */
    public static function site(?string $entry = null): string
    {
        return Template::named('nginx-site')->render(['entry' => self::safeEntry($entry)]);
    }

    /** A Next.js export: a page per route, its own 404.html, and `$basePath` stripped when set. */
    public static function nextExport(?string $basePath = null): string
    {
        $basePath = $basePath !== null && preg_match(self::SAFE_BASE_PATH, $basePath) === 1 ? $basePath : null;

        return Template::named('nginx-next-export')->render(['base_path' => $basePath]);
    }

    private static function safeEntry(?string $entry): ?string
    {
        $entry = trim((string) $entry);

        return $entry !== '' && preg_match(self::SAFE_ENTRY, $entry) === 1 ? $entry : null;
    }
}
