<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

/**
 * The PHP document root: the manifest's when it declares one, otherwise the
 * first directory that actually holds an index file.
 *
 * A directory alone is not enough. Dotclear ships an empty public/ beside its
 * root index.php, and the base image's `-d /app/public` test serves that as a 403.
 */
final class PhpDocroot
{
    /** The application root, as opposed to '' (nothing found, the image decides). */
    public const ROOT = '.';

    /**
     * In the order worth trying. `web/` is Symfony 2/3 (wallabag); `public_html/`
     * is Roundcube, whose root index.php only says to point the server there.
     * `webroot/` is Phorge. Each names a directory whose whole purpose is to be
     * served, so finding an index in one is evidence enough.
     *
     * @var list<string>
     */
    public const CANDIDATES = ['public', 'web', 'public_html', 'webroot'];

    /**
     * Tried only after the project root, and only for `index.php`.
     *
     * These are names that *may* be a document root but, unlike the four
     * above, are not reserved for one: `www/` (ZenTao, Group Office,
     * Bluecherry), `htdocs/` (DAViCal), `source/` (OXID eShop), `upload/`
     * (ClipBucket), `webui/` (piler). Each was serving a 403 because nothing
     * probed it and Apache got the project root with no index.
     *
     * `src/` stays last, and is the reason this list is late-ranked at all.
     * It is WackoWiki's document root, but unlike the four above it is the
     * conventional name for *source* — and ranked with them it outranked the
     * root and matched `index.html` too. Two ordinary shapes broke on that: a
     * PHP app whose frontend sources live in `src/` with a `src/index.html`
     * had Apache pointed at the unbuilt source tree, serving `.ts`, `.env` and
     * config files as plaintext; and the blank "silence is golden" `index.php`
     * people drop into a directory to stop listings silently replaced the real
     * root front controller with an empty page. A project carrying both
     * `www/index.php` and `src/index.php` should get `www/`, hence the order.
     *
     * After the root, so a project that has its own front controller keeps it,
     * and `index.php` only, so a directory of documents is not mistaken for a
     * document root. That last rule is what keeps Perl applications out: Sympa
     * ships `www/` and AWStats `wwwroot/`, and neither holds an index file.
     *
     * @var list<string>
     */
    public const LATE_CANDIDATES = ['www', 'htdocs', 'httpdocs', 'source', 'upload', 'webui', 'src'];

    /**
     * What Apache's DirectoryIndex serves in the base image. index.htm is not
     * on it, so a directory holding only that would still be a 403.
     *
     * @var list<string>
     */
    public const INDEX_FILES = ['index.php', 'index.html'];

    /**
     * @param callable(string): bool $isFile relative path => is a file in the application
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
            default => ['PA_DOCROOT' => '/app/' . $docroot],
        };
    }

    /**
     * The first candidate with an index one level down, then the root when it
     * has one, else '' so the image keeps today's behaviour.
     *
     * @param callable(string): bool $isFile
     */
    public static function detect(callable $isFile): string
    {
        foreach ([...self::CANDIDATES, self::ROOT] as $dir) {
            foreach (self::INDEX_FILES as $index) {
                if ($isFile($dir === self::ROOT ? $index : $dir . '/' . $index)) {
                    return $dir;
                }
            }
        }

        // Only once the root has had its turn, and only on a front controller.
        foreach (self::LATE_CANDIDATES as $dir) {
            if ($isFile($dir . '/index.php')) {
                return $dir;
            }
        }

        return '';
    }
}
