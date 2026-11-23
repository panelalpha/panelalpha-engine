<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Template\Template;

/**
 * A Vite app beside an API server that never serves it: the script serves the build and forwards the rest to the server.
 */
final class SpaApiServe
{
    public const FILENAME = 'panelalpha.spa-api.mjs';

    public const SERVER_PORT = 3001;

    public const DECISION_KEY = 'spa_api';

    private const DEFAULT_PREFIX = '/api';

    private const VITE_CONFIGS = ['vite.config.ts', 'vite.config.js', 'vite.config.mjs', 'vite.config.mts', 'vite.config.cjs'];

    private const SOURCE_EXTENSIONS = ['js', 'mjs', 'cjs', 'ts', 'mts', 'cts'];

    private const MAX_SERVER_FILES = 200;

    /** What a server that serves the built frontend itself calls. */
    private const SERVES_FRONTEND = '/express\.static\s*\(|serve-static|serveStatic\s*\(|\.sendFile\s*\(|res\.render\s*\(/';

    private const PROXY_KEY = '/[\'"](\/[\w\-.\/]*)[\'"]\s*:/';

    /** A Vite app with a build to serve and a server whose files serve nothing of it. */
    public static function applies(ProjectContext $context, string $entry): bool
    {
        return $context->script('build') !== ''
            && $context->isFile('index.html')
            && self::viteConfig($context) !== null
            && ! self::servesFrontend($context, $entry);
    }

    /**
     * `/api` and what the Vite dev server proxies: page loads there are not index.html.
     *
     * @return list<string>
     */
    public static function apiPrefixes(ProjectContext $context): array
    {
        $prefixes = [self::DEFAULT_PREFIX];
        $config = self::viteConfig($context);
        $source = $config === null ? null : $context->contents($config);
        $block = $source === null ? '' : strstr($source, 'proxy');
        if (is_string($block) && preg_match_all(self::PROXY_KEY, $block, $matches) > 0) {
            foreach ($matches[1] as $prefix) {
                $prefix = rtrim($prefix, '/');
                if ($prefix !== '' && ! in_array($prefix, $prefixes, true)) {
                    $prefixes[] = $prefix;
                }
            }
        }

        return $prefixes;
    }

    /** A bun lockfile's runtime image has no node. */
    public static function startCommand(string $packageManager): string
    {
        return ($packageManager === 'bun' ? 'bun ' : 'node ').self::FILENAME;
    }

    /**
     * @param  list<string>  $apiPrefixes
     */
    public static function script(string $serverCommand, array $apiPrefixes, string $outputDirectory): string
    {
        return Template::named('script/spa-api-serve')->render([
            'server_port' => (string) self::SERVER_PORT,
            'server_command' => self::literal($serverCommand),
            'api_prefixes' => self::literal($apiPrefixes),
            'output' => self::literal(NodeRuntime::safeOutputDir($outputDirectory)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $decision
     * @return array{server: string, api_prefixes: list<string>}|null
     */
    public static function fromDecision(array $decision): ?array
    {
        $spa = $decision[self::DECISION_KEY] ?? null;
        if (! is_array($spa) || ! is_string($spa['server'] ?? null) || trim($spa['server']) === '') {
            return null;
        }
        $prefixes = array_values(array_filter(
            is_array($spa['api_prefixes'] ?? null) ? $spa['api_prefixes'] : [],
            static fn (mixed $prefix): bool => is_string($prefix) && str_starts_with($prefix, '/')
        ));

        return ['server' => $spa['server'], 'api_prefixes' => $prefixes === [] ? [self::DEFAULT_PREFIX] : $prefixes];
    }

    private static function viteConfig(ProjectContext $context): ?string
    {
        foreach (self::VITE_CONFIGS as $config) {
            if ($context->isFile($config)) {
                return $config;
            }
        }

        return null;
    }

    /** The entry's whole directory: server/index.ts often only mounts what server/app.ts serves. */
    private static function servesFrontend(ProjectContext $context, string $entry): bool
    {
        foreach (self::serverFiles($context, $entry) as $file) {
            $source = $context->contents($file);
            if ($source !== null && preg_match(self::SERVES_FRONTEND, $source) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function serverFiles(ProjectContext $context, string $entry): array
    {
        $directory = dirname($entry);
        if ($directory === '.' || ! is_dir($context->path($directory))) {
            return [$entry];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($context->path($directory), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (count($files) >= self::MAX_SERVER_FILES) {
                break;
            }
            if ($file instanceof \SplFileInfo && $file->isFile()
                && in_array(strtolower($file->getExtension()), self::SOURCE_EXTENSIONS, true)
                && ! str_contains($file->getPathname(), '/node_modules/')
            ) {
                $files[] = $directory.'/'.substr($file->getPathname(), strlen($context->path($directory)) + 1);
            }
        }

        return $files === [] ? [$entry] : $files;
    }

    private static function literal(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
