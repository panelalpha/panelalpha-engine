<?php

namespace App\Lib\Deploy\Health\Explainers;

use App\Lib\Deploy\Health\Explainer;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Why a PHP application's `/` was refused (403) rather than served: an entry point one directory
 * down, an unreadable root index, or none at all. Unlike {@see PhpDocrootExplainer}, nothing is disclosed.
 */
final class PhpEntryExplainer implements Explainer
{
    /**
     * Where PHP applications keep their entry point, in the order worth reporting; root last.
     *
     * @var list<string>
     */
    private const ENTRY_POINTS = [
        'public/index.php',
        'pub/index.php',
        'web/index.php',
        'html/index.php',
        'webroot/index.php',
        'src/index.php',
        'index.php',
    ];

    /** How many directory levels below the root are searched when no usual place has one. */
    private const SEARCH_DEPTH = 3;

    /** Never a front page: dependencies installed into the tree. Dot directories are skipped too. */
    private const NOT_SEARCHED = ['vendor', 'node_modules'];

    public function id(): string
    {
        return 'php-entry';
    }

    public function explain(ProjectContext $context, ProbedResponse $response): array
    {
        foreach (self::ENTRY_POINTS as $entry) {
            if (! $context->isFile($entry)) {
                continue;
            }

            return self::foundEntry($context, $entry);
        }

        return self::searchedEntry($context);
    }

    /**
     * An index-less public/ is what the image serves when nothing is declared, so a root index is not looked for.
     *
     * @return array{detail: string, fix: string}
     */
    private static function foundEntry(ProjectContext $context, string $entry): array
    {
        if (str_contains($entry, '/')) {
            $dir = dirname($entry);

            return [
                'detail' => 'The entry point is '.$entry.', so the document root should be '
                    .$dir.' rather than the project root, which has no index to serve.',
                'fix' => self::docrootFix($context, $dir),
            ];
        }

        if (is_dir($context->path('public')) && ! $context->isFile('public/index.html')) {
            return [
                'detail' => 'The document root has no index file: public/ exists without one, '
                    .'while index.php is at the project root.',
                'fix' => 'Redeploy, which serves the project root when public/ has no index, '
                    .'or set `docroot` in panelalpha.yaml to the directory holding the entry point.',
            ];
        }

        return [
            'detail' => 'index.php is at the project root, where the server is looking, '
                .'so the file is not missing -- it cannot be read.',
            'fix' => 'Check the permissions on ~/project and index.php, then redeploy.',
        ];
    }

    /**
     * No usual place holds one; a deeper one still makes the docroot hint the remedy.
     *
     * @return array{detail: string, fix: string}
     */
    private static function searchedEntry(ProjectContext $context): array
    {
        $dir = self::shallowestIndexDir($context->projectDir);
        if ($dir !== null) {
            return [
                'detail' => 'index.php is not in any of the usual places, but '.$dir.'/index.php exists, '
                    .'so the document root should most likely be '.$dir.' rather than the project root.',
                'fix' => self::docrootFix($context, $dir),
            ];
        }

        return [
            'detail' => 'There is no index.php anywhere in ~/project, so nothing in this project '
                .'is a front page.',
            'fix' => 'Add an index.php, or set `docroot` in panelalpha.yaml to the directory that '
                .'holds the application, with `extends: '.self::platformOf($context).'` when the file has no `id` '
                .'or `extends` of its own. A repository that is a library rather than a site has no '
                .'front page to serve.',
        ];
    }

    /** A panelalpha.yaml with `docroot:` alone is refused: it needs `extends` naming the platform to start from. */
    public static function docrootFix(ProjectContext $context, string $dir): string
    {
        return 'Set `docroot: '.$dir.'` in panelalpha.yaml and redeploy. A file with no `id` or `extends` '
            .'of its own also needs `extends: '.self::platformOf($context).'`, the platform to start from.';
    }

    /** The shipped PHP platform this project deploys as: Composer or not. */
    private static function platformOf(ProjectContext $context): string
    {
        return $context->isFile('composer.json') ? 'php' : 'php-plain';
    }

    /** The shallowest directory below $root holding an index.php, alphabetical within a level; symlinks skipped. */
    private static function shallowestIndexDir(string $root): ?string
    {
        $root = rtrim($root, '/');
        $level = [''];
        for ($depth = 0; $depth < self::SEARCH_DEPTH && $level !== []; $depth++) {
            $next = [];
            foreach ($level as $relative) {
                $entries = @scandir($relative === '' ? $root : $root.'/'.$relative);
                if ($entries === false) {
                    continue;
                }
                foreach ($entries as $entry) {
                    if ($entry[0] === '.' || in_array($entry, self::NOT_SEARCHED, true)) {
                        continue;
                    }
                    $child = $relative === '' ? $entry : $relative.'/'.$entry;
                    $path = $root.'/'.$child;
                    if (is_link($path) || ! is_dir($path)) {
                        continue;
                    }
                    if (is_file($path.'/index.php')) {
                        return $child;
                    }
                    $next[] = $child;
                }
            }
            $level = $next;
        }

        return null;
    }
}
