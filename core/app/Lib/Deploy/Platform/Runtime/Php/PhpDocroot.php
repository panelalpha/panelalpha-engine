<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

/**
 * The PHP document root: the manifest's, else the first directory holding an index file; a directory alone
 * is not enough (Dotclear's empty public/ served a 403).
 */
final class PhpDocroot
{
    /** The application root, as opposed to '' (nothing found, the image decides). */
    public const ROOT = '.';

    /**
     * Directories whose whole purpose is to be served, in the order worth trying: `web/` is Symfony 2/3,
     * `public_html/` Roundcube, `webroot/` Phorge.
     *
     * @var list<string>
     */
    public const CANDIDATES = ['public', 'web', 'public_html', 'webroot'];

    /**
     * Names that may be a document root but are not reserved for one (`www/`, `htdocs/`, `src/` last), tried after
     * the root and only for `index.php`, so a root front controller and a sources `src/` keep their meaning.
     *
     * @var list<string>
     */
    public const LATE_CANDIDATES = ['www', 'htdocs', 'httpdocs', 'source', 'upload', 'webui', 'src'];

    /**
     * What Apache's DirectoryIndex serves in the base image; a directory holding only index.htm is still a 403.
     *
     * @var list<string>
     */
    public const INDEX_FILES = ['index.php', 'index.html'];

    /**
     * @param  callable(string): bool  $isFile  relative path => is a file in the application
     * @return array<string, string> PA_DOCROOT, or nothing to leave it to the image
     */
    public static function environment(mixed $declared, callable $isFile): array
    {
        $docroot = is_string($declared) ? trim($declared, '/') : '';
        if ($docroot === '') {
            $docroot = self::detect($isFile);
        }

        return match ($docroot) {
            '' => [],
            self::ROOT => ['PA_DOCROOT' => '/app'],
            default => ['PA_DOCROOT' => '/app/'.$docroot],
        };
    }

    /**
     * The first candidate with an index, then the root, then a late candidate with an `index.php`, else ''
     * so the image keeps today's behaviour.
     *
     * @param  callable(string): bool  $isFile
     */
    public static function detect(callable $isFile): string
    {
        foreach ([...self::CANDIDATES, self::ROOT] as $dir) {
            foreach (self::INDEX_FILES as $index) {
                if ($isFile($dir === self::ROOT ? $index : $dir.'/'.$index)) {
                    return $dir;
                }
            }
        }

        foreach (self::LATE_CANDIDATES as $dir) {
            if ($isFile($dir.'/index.php')) {
                return $dir;
            }
        }

        return '';
    }
}
