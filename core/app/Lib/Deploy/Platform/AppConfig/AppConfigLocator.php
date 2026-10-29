<?php

namespace App\Lib\Deploy\Platform\AppConfig;

use App\Lib\Deploy\Platform\SourceRecipes;
use App\Lib\Deploy\Source\RepoUrl;

/**
 * Where a project's app config lives.
 *
 * Two locations — the repository, then the engine's own directory for that
 * repository — and the repository wins: an upstream that has learned to
 * describe its own hosting knows more than a page written about it from
 * outside.
 *
 * Within a location the `.panelalpha/` directory is tried first, then the
 * older single-file forms. Markdown before YAML there, as it always was: it
 * is the format the existing pages are written in.
 */
final class AppConfigLocator
{
    public const KIND_DIRECTORY = 'directory';

    public const KIND_MARKDOWN = 'markdown';

    public const KIND_YAML = 'yaml';

    public const ORIGIN_REPOSITORY = 'repository';

    public const ORIGIN_ENGINE = 'engine';

    /**
     * Every place an app config could be, most specific first.
     *
     * @return list<array{path: string, kind: string, origin: string}>
     */
    public static function candidates(string $projectDir, ?string $gitUrl = null): array
    {
        $project = rtrim($projectDir, '/');
        $candidates = [
            ['path' => $project.'/'.AppConfigDirectory::DIRNAME, 'kind' => self::KIND_DIRECTORY, 'origin' => self::ORIGIN_REPOSITORY],
            ['path' => $project.'/'.AppConfig::MARKDOWN_FILENAME, 'kind' => self::KIND_MARKDOWN, 'origin' => self::ORIGIN_REPOSITORY],
            ['path' => $project.'/'.AppConfig::YAML_FILENAME, 'kind' => self::KIND_YAML, 'origin' => self::ORIGIN_REPOSITORY],
        ];

        $slug = is_string($gitUrl) && $gitUrl !== '' ? RepoUrl::slug($gitUrl) : null;
        if ($slug === null) {
            return $candidates;
        }

        $candidates[] = [
            'path' => SourceRecipes::defaultDirectory().'/'.$slug,
            'kind' => self::KIND_DIRECTORY,
            'origin' => self::ORIGIN_ENGINE,
        ];
        // The pages the engine shipped before the directories existed. Still
        // read, so a page an operator wrote on a host keeps working.
        $legacy = PaemdPage::pagesDirectory().'/'.$slug;
        $candidates[] = ['path' => $legacy.'/'.AppConfig::MARKDOWN_FILENAME, 'kind' => self::KIND_MARKDOWN, 'origin' => self::ORIGIN_ENGINE];
        $candidates[] = ['path' => $legacy.'/'.AppConfig::YAML_FILENAME, 'kind' => self::KIND_YAML, 'origin' => self::ORIGIN_ENGINE];

        return $candidates;
    }

    /**
     * The first candidate that holds something, as an app config and the origin
     * it came from.
     *
     * @return array{0: ?AppConfig, 1: ?string}
     */
    public static function find(AppConfigSource $source, string $projectDir, ?string $gitUrl = null): array
    {
        $hit = self::findCandidate($source, $projectDir, $gitUrl);

        return $hit === null ? [null, null] : [$hit['config'], $hit['origin']];
    }

    /**
     * The same, with the candidate it came from — for a caller that has to
     * say *which* file spoke.
     *
     * @return array{config: AppConfig, path: string, kind: string, origin: string}|null
     */
    public static function findCandidate(
        AppConfigSource $source,
        string $projectDir,
        ?string $gitUrl = null
    ): ?array {
        foreach (self::candidates($projectDir, $gitUrl) as $candidate) {
            $appConfig = self::read($source, $candidate);
            if ($appConfig !== null) {
                return ['config' => $appConfig] + $candidate;
            }
        }

        return null;
    }

    /**
     * How to name a candidate in a log line: what it is called inside the
     * project, or the repository slug the engine filed it under.
     *
     * @param  array{path: string, origin: string}  $candidate
     */
    public static function describe(array $candidate): string
    {
        $path = $candidate['path'];
        if ($candidate['origin'] === self::ORIGIN_REPOSITORY) {
            return basename($path);
        }

        foreach ([SourceRecipes::defaultDirectory(), PaemdPage::pagesDirectory()] as $root) {
            if (! str_starts_with($path, $root.'/')) {
                continue;
            }
            $relative = substr($path, strlen($root) + 1);

            // A directory is the slug; a legacy page is the slug plus a
            // filename.
            return str_ends_with($relative, '.md') || str_ends_with($relative, '.yaml')
                ? dirname($relative)
                : $relative;
        }

        return $path;
    }

    /**
     * The engine's own recipe for a repository, without the checkout: for a
     * caller that has no project directory to read, or may not read it.
     */
    public static function findShipped(AppConfigSource $source, ?string $gitUrl): ?AppConfig
    {
        foreach (self::candidates('', $gitUrl) as $candidate) {
            if ($candidate['origin'] !== self::ORIGIN_ENGINE) {
                continue;
            }
            $appConfig = self::read($source, $candidate);
            if ($appConfig !== null) {
                return $appConfig;
            }
        }

        return null;
    }

    /**
     * @param  array{path: string, kind: string, origin: string}  $candidate
     */
    private static function read(AppConfigSource $source, array $candidate): ?AppConfig
    {
        if ($candidate['kind'] === self::KIND_DIRECTORY) {
            return AppConfigDirectory::read(
                $source,
                $candidate['path'],
                $candidate['origin'] === self::ORIGIN_ENGINE
            );
        }

        if (! $source->exists($candidate['path'])) {
            return null;
        }
        $content = $source->read($candidate['path']);
        if (! is_string($content) || trim($content) === '') {
            return null;
        }

        return $candidate['kind'] === self::KIND_YAML
            ? AppConfig::fromYaml($content)
            : AppConfig::fromMarkdown($content);
    }
}
