<?php

namespace App\Lib\Deploy\Platform\AppConfig;

use App\Lib\Deploy\Platform\SourceRecipes;
use App\Lib\Deploy\Source\RepoUrl;

/**
 * Where a project's app config lives: the repository wins over the engine's directory for it, as an upstream describing its own
 * hosting knows more. In each, `.panelalpha/` comes first, then markdown before YAML, the format existing pages are written in.
 */
final class AppConfigLocator
{
    public const KIND_DIRECTORY = 'directory';

    public const KIND_MARKDOWN = 'markdown';

    public const KIND_YAML = 'yaml';

    public const ORIGIN_REPOSITORY = 'repository';

    public const ORIGIN_ENGINE = 'engine';

    /** The engine's legacy host pages, in the words a deploy log names them with. */
    public const ORIGIN_PAEMD = 'paemd';

    /**
     * Every place an app config could be, most specific first.
     * The engine's pages from before the directories are still read, so one an operator wrote on a host keeps working.
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
     * project, or the repository slug the engine filed it under (a legacy page's directory, not its file).
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
     * How an error names the app config behind a candidate: the file inside the
     * project, or the engine's recipe for the repository it was cloned from.
     *
     * @param  array{path: string, kind: string, origin: string}  $candidate
     */
    private static function subject(array $candidate): string
    {
        if ($candidate['origin'] !== self::ORIGIN_ENGINE) {
            return $candidate['kind'] === self::KIND_DIRECTORY
                ? AppConfigDirectory::DIRNAME.'/'.AppConfigDirectory::CONFIG
                : basename($candidate['path']);
        }

        return self::isLegacyPage($candidate)
            ? 'engine page '.self::describe($candidate).'/'.basename($candidate['path'])
            : self::recipeSubject(self::describe($candidate));
    }

    /**
     * How an error names the `panelalpha.yaml` a recipe directory ships, under the
     * slug the engine filed the repository under.
     */
    public static function recipeSubject(string $name): string
    {
        return 'engine recipe '.$name.'/'.AppConfigDirectory::CONFIG;
    }

    /**
     * Where a candidate came from, in the words a deploy log uses: the repository,
     * the engine's recipe tree, or the legacy pages an operator kept on the host.
     *
     * @param  array{path: string, kind: string, origin: string}  $candidate
     */
    public static function origin(array $candidate): string
    {
        return self::isLegacyPage($candidate) ? self::ORIGIN_PAEMD : $candidate['origin'];
    }

    /**
     * A page under the engine's legacy pages directory, written before the
     * recipe directories existed.
     *
     * @param  array{path: string, origin: string}  $candidate
     */
    private static function isLegacyPage(array $candidate): bool
    {
        return $candidate['origin'] === self::ORIGIN_ENGINE
            && str_starts_with($candidate['path'], PaemdPage::pagesDirectory().'/');
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
                $candidate['origin'] === self::ORIGIN_ENGINE,
                self::subject($candidate)
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
            ? AppConfig::fromYaml($content, self::subject($candidate))
            : AppConfig::fromMarkdown($content, self::subject($candidate));
    }
}
