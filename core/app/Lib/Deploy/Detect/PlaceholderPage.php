<?php

namespace App\Lib\Deploy\Detect;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Platform\Dockerfile\NginxConfig;

/**
 * The pages the engine drops into an empty ~/project. Detection is
 * title-based, so they must carry these titles verbatim -- that is how the
 * engine's own placeholder is told apart from a real index.html.
 */
final class PlaceholderPage
{
    public const WELCOME_TITLE = 'PanelAlpha — Ready';

    public const NOT_CONFIGURED_TITLE = 'PanelAlpha — Project not configured';

    /** The staged entrypoint the engine writes beside the compose file. */
    private const ENTRYPOINT = 'panelalpha-entrypoint.sh';

    /** The nginx config the generated static compose file mounts. */
    private const NGINX_CONF = NginxConfig::FILENAME;

    /** Run-file names reserved for the engine: no client compose file is ever named one. */
    private const ENGINE_RUN_FILES = [
        EngineArtifacts::RUN_COMPOSE,
        EngineArtifacts::RUN_COMPOSE_OVERRIDE,
        EngineArtifacts::RUN_CLIENT_OVERRIDE,
        EngineArtifacts::APP_CONFIG_COMPOSE,
        EngineArtifacts::RUN_PASSWD,
        EngineArtifacts::RUN_GROUP,
    ];

    public static function isOneOf(string $path): bool
    {
        $raw = is_file($path) ? @file_get_contents($path) : null;
        if (!is_string($raw) || $raw === '') {
            return false;
        }

        return str_contains($raw, self::WELCOME_TITLE)
            || str_contains($raw, self::NOT_CONFIGURED_TITLE);
    }

    /**
     * The placeholder index in $projectDir, once the project has outgrown it.
     * Ingesting a source removes it (clone starts empty, archive rsyncs with
     * --delete); files written in one at a time are never ingested. Null while
     * the directory holds only the engine's own scaffolding.
     */
    public static function supersededIndex(string $projectDir): ?string
    {
        $projectDir = rtrim($projectDir, '/');
        $index = $projectDir . '/index.html';

        if (!self::isOneOf($index)) {
            return null;
        }

        foreach (scandir($projectDir) ?: [] as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }
            if (!self::isScaffolding($projectDir, $entry)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Files the engine put in ~/project itself. Public so this class does not
     * delete a welcome page from an otherwise empty account, and so HtmlSite
     * does not read the engine's own compose file and nginx config as the
     * user's application.
     */
    public static function isScaffolding(string $dir, string $entry): bool
    {
        $name = strtolower($entry);

        if ($name === 'index.html' || $name === self::ENTRYPOINT || $name === self::NGINX_CONF) {
            return true;
        }

        if (in_array($name, array_map('strtolower', self::ENGINE_RUN_FILES), true)) {
            return true;
        }

        if (in_array($name, ComposeFileInspector::COMPOSE_FILE_CANDIDATES, true)) {
            return ComposeFileInspector::isGeneratedBootstrapCompose($dir . '/' . $entry);
        }

        return false;
    }
}
