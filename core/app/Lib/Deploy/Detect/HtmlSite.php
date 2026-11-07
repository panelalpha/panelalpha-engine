<?php

namespace App\Lib\Deploy\Detect;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * A site that is nothing but web documents and the assets they reference: every file must be on an
 * allowlist of things a browser is handed verbatim.
 */
final class HtmlSite
{
    /**
     * What a browser is served without anything running first: documents, data, images, fonts and media.
     * `.js` counts, since any manifest that reads package.json has already had its turn.
     *
     * @var list<string>
     */
    private const ASSET_EXTENSIONS = [
        'html', 'htm', 'xhtml', 'css', 'js', 'mjs', 'map',
        'json', 'xml', 'txt', 'md', 'csv', 'webmanifest', 'vtt',
        'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'bmp', 'apng', 'cur',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'mp4', 'webm', 'ogv', 'mp3', 'ogg', 'wav', 'm4a', 'flac', 'pdf',
    ];

    /**
     * Extensionless files a repository carries for itself, not the browser (GitHub Pages' CNAME too).
     *
     * @var list<string>
     */
    private const ALLOWED_NAMES = [
        'license', 'licence', 'readme', 'changelog', 'authors', 'notice',
        'contributing', 'copyright', 'cname', 'version',
    ];

    /** Someone else's tree, or a copy of this one. */
    private const SKIPPED_DIRECTORIES = ['node_modules', 'vendor', 'bower_components'];

    /** Root is depth 0. Three levels covers `assets/fonts/inter/`. */
    private const MAX_DEPTH = 3;

    /** Bounds the walk on an unexpectedly large tree. */
    private const MAX_DIRECTORIES = 300;

    /**
     * Basenames a front page is called, in preference order; `index` is the only one a webserver finds itself.
     *
     * @var list<string>
     */
    private const ENTRY_NAMES = ['index', 'home', 'main', 'default', 'start', 'welcome'];

    /** @var list<string> relative paths, shallowest first, then alphabetical */
    private array $documents = [];

    private function __construct(private readonly string $projectDir) {}

    /** The document this site should be served from; null for a program, no HTML, or only the engine's placeholder. */
    public static function entry(string $projectDir): ?string
    {
        return (new self(rtrim($projectDir, '/')))->locate();
    }

    private function locate(): ?string
    {
        if (! is_dir($this->projectDir) || ! $this->walk()) {
            return null;
        }

        return $this->named() ?? $this->first();
    }

    /** A front page by name, in preference order, and only at the root. */
    private function named(): ?string
    {
        foreach (self::ENTRY_NAMES as $name) {
            foreach ($this->documents as $document) {
                if (! str_contains($document, '/') && self::basenameOf($document) === $name) {
                    return $document;
                }
            }
        }

        return null;
    }

    /** Nothing was named like a front page, so the shallowest one is it. */
    private function first(): ?string
    {
        return $this->documents[0] ?? null;
    }

    /**
     * Collect the HTML and refuse on the first file that is not something a browser is handed as-is.
     * Breadth first, so `documents` is shallowest first, ties broken by scandir's alphabetical order.
     */
    private function walk(): bool
    {
        $queue = [['', 0]];
        $scanned = 0;

        while ($queue !== []) {
            [$relative, $depth] = array_shift($queue);
            if (++$scanned > self::MAX_DIRECTORIES) {
                return false;
            }

            if (! $this->walkDirectory($relative, $depth, $queue)) {
                return false;
            }
        }

        return $this->documents !== [];
    }

    /**
     * Dotfiles are the repository's housekeeping, never the reason a tree is a site.
     *
     * @param  list<array{0: string, 1: int}>  $queue
     */
    private function walkDirectory(string $relative, int $depth, array &$queue): bool
    {
        $absolute = $relative === '' ? $this->projectDir : $this->projectDir.'/'.$relative;
        foreach (ProjectContext::entries($absolute) as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $child = $relative === '' ? $entry : $relative.'/'.$entry;
            $path = $this->projectDir.'/'.$child;

            if (is_dir($path)) {
                if (in_array(strtolower($entry), self::SKIPPED_DIRECTORIES, true)) {
                    continue;
                }
                if ($depth < self::MAX_DEPTH) {
                    $queue[] = [$child, $depth + 1];
                }

                continue;
            }

            if (! $this->acceptsFile($entry, $path, $child, $absolute, $depth)) {
                return false;
            }
        }

        return true;
    }

    /**
     * An engine placeholder page is never the front page, and the engine's own scaffolding at the root
     * is not evidence either way.
     */
    private function acceptsFile(string $entry, string $path, string $child, string $absolute, int $depth): bool
    {
        if (self::isDocument($entry)) {
            if (! PlaceholderPage::isOneOf($path)) {
                $this->documents[] = $child;
            }

            return true;
        }

        if ($depth === 0 && PlaceholderPage::isScaffolding($absolute, $entry)) {
            return true;
        }

        return self::isAsset($entry);
    }

    private static function isAsset(string $entry): bool
    {
        $extension = self::extensionOf($entry);

        return $extension === ''
            ? in_array(strtolower($entry), self::ALLOWED_NAMES, true)
            : in_array($extension, self::ASSET_EXTENSIONS, true);
    }

    private static function isDocument(string $entry): bool
    {
        return in_array(self::extensionOf($entry), ['html', 'htm', 'xhtml'], true);
    }

    private static function extensionOf(string $entry): string
    {
        $dot = strrpos($entry, '.');

        return $dot === false || $dot === 0 ? '' : strtolower(substr($entry, $dot + 1));
    }

    private static function basenameOf(string $document): string
    {
        $name = basename($document);
        $dot = strrpos($name, '.');

        return strtolower($dot === false ? $name : substr($name, 0, $dot));
    }
}
