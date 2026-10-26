<?php

namespace App\Lib\Deploy\Platform\AppConfig;

use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Source\RepoUrl;

/**
 * Parser for a `panelalpha.md` (paemd) page: markdown describing how to host one upstream
 * repo, its blocks named by a backticked filename on the line before the fence. Every
 * named block that is not a special name becomes a file snippet in the project tree.
 */
class PaemdPage
{
    public const FILENAME = 'panelalpha.md';

    public const PRE_CHECK_SCRIPT = 'panelalpha-before-clone-validation.sh';

    public const SETUP_SCRIPT = 'panelalpha-after-clone.sh';

    public const APP_SCRIPT = 'panelalpha-app.sh';

    /**
     * A block of the YAML app config format, embedded in the page.
     *
     * The page's own vocabulary is scripts and files, which cannot say what platform an
     * application is. Rather than grow a second syntax out of markdown, the page carries
     * a block of the format that already has one, parsed by the same code.
     */
    public const CONFIG_BLOCK = 'panelalpha.yaml';

    /**
     * Where the engine kept paemd pages before `resources/sources/`. Still read for
     * pages written on a host by hand.
     */
    private const PAGES_DIR = '/opt/panelalpha/shared-hosting/paemd-pages';

    public static function pagesDirectory(): string
    {
        return self::PAGES_DIR;
    }

    /**
     * Block names the deploy pipeline consumes directly; everything else is a file
     * snippet. Compose filenames are handled by `compose()`.
     *
     * @return list<string>
     */
    public static function specialBlockNames(): array
    {
        return array_merge(
            [self::PRE_CHECK_SCRIPT, self::SETUP_SCRIPT, self::APP_SCRIPT, self::CONFIG_BLOCK],
            ComposeFileInspector::COMPOSE_FILE_CANDIDATES,
        );
    }

    /** Commands to run after clone, before `docker compose up`. */
    public static function setupCommands(string $content): ?string
    {
        return self::bashBlock($content, self::SETUP_SCRIPT);
    }

    /** Validation to run before cloning (e.g. disk space checks). */
    public static function preCheckCommands(string $content): ?string
    {
        return self::bashBlock($content, self::PRE_CHECK_SCRIPT);
    }

    /** The app-management script backing the /app/* endpoints. */
    public static function appScript(string $content): ?string
    {
        return self::bashBlock($content, self::APP_SCRIPT);
    }

    /** The page's embedded app config, if it carries one. */
    public static function config(string $content): ?string
    {
        $pattern = '/`'.preg_quote(self::CONFIG_BLOCK, '/').'`\s*```ya?ml\s*(.+?)\s*```/s';

        return preg_match($pattern, $content, $matches) === 1 ? trim($matches[1]) : null;
    }

    /**
     * Compose file shipped by the paemd page, if any.
     *
     * Named for the file it would be written as, so a page can ship `docker-compose.yml`
     * under whichever conventional name it prefers.
     */
    public static function compose(string $content): ?string
    {
        foreach (ComposeFileInspector::COMPOSE_FILE_CANDIDATES as $candidate) {
            $pattern = '/`'.preg_quote($candidate, '/').'`\s*```yaml\s*(.+?)\s*```/s';
            if (preg_match($pattern, $content, $matches) === 1) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Named code blocks to write verbatim into the project tree. A block is a file
     * snippet unless its name is one of the special names; absolute paths and
     * traversal are rejected and a leading `./` is stripped.
     *
     * @return list<array{path: string, contents: string}>
     */
    public static function fileSnippets(string $content): array
    {
        $pattern = '/`([^`]+)`\s*```[a-zA-Z]*\s*(.+?)\s*```/s';
        if (! preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $special = self::specialBlockNames();
        $snippets = [];
        foreach ($matches as $match) {
            $relativePath = $match[1];
            if (in_array($relativePath, $special, true)) {
                continue;
            }
            if (str_starts_with($relativePath, '/') || str_contains($relativePath, '..')) {
                continue;
            }
            if (str_starts_with($relativePath, './')) {
                $relativePath = ltrim($relativePath, './');
            }
            if ($relativePath === '') {
                continue;
            }
            $snippets[] = [
                'path' => $relativePath,
                'contents' => trim($match[2]),
            ];
        }

        return $snippets;
    }

    /**
     * Path of the engine-shipped paemd page for a repo, or null when the URL cannot be
     * resolved to host/owner/repo.
     */
    public static function localPagePath(string $gitUrl): ?string
    {
        $parsed = self::parseRepoUrl($gitUrl);
        if ($parsed === null) {
            return null;
        }

        return self::PAGES_DIR
            .'/'.strtolower($parsed['host'])
            .'/'.strtolower($parsed['owner'])
            .'/'.strtolower($parsed['repo'])
            .'/'.self::FILENAME;
    }

    /**
     * Host, owner and repo of a git URL, kept as a name here because pages have always
     * been addressed this way; the parsing is shared with the source recipe tree.
     *
     * @return ?array{host: string, owner: string, repo: string}
     */
    public static function parseRepoUrl(string $gitUrl): ?array
    {
        return RepoUrl::parse($gitUrl);
    }

    /** Body of the ```bash fence introduced by a backticked block name. */
    private static function bashBlock(string $content, string $name): ?string
    {
        $pattern = '/`'.preg_quote($name, '/').'`\s*```bash\s*(.+?)\s*```/s';
        if (preg_match($pattern, $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}
