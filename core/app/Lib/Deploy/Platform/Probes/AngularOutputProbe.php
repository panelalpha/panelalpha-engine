<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Where an Angular build actually writes.
 *
 * Angular states its own output path inside angular.json, under a project
 * name nobody can predict, and modern versions split it into `base` plus a
 * `browser` subdirectory. Serving the wrong one serves an empty directory,
 * which looks exactly like a successful deploy.
 *
 * Contributes `output_directory`; always matches when angular.json is there,
 * so the manifest can use it as its output resolver and as part of detection.
 */
final class AngularOutputProbe implements PlatformProbe
{
    private const DEFAULT_BUILD = 'npx ng build';

    public function id(): string
    {
        return 'angular-output';
    }

    public function evaluate(ProjectContext $context): bool|array
    {
        return ['output_directory' => self::outputDir($context->projectDir)];
    }

    public static function outputDir(string $projectDir): string
    {
        $project = self::buildProject(self::projects($projectDir));

        return $project === null ? 'dist' : (self::projectOutput($project[1]) ?? 'dist');
    }

    /**
     * The engine's default `npx ng build`, naming the project whose output is
     * served: in a workspace with several projects `ng` refuses to guess.
     */
    public static function buildCommand(string $projectDir, string $default): string
    {
        if (trim($default) !== self::DEFAULT_BUILD) {
            return $default;
        }
        $projects = self::projects($projectDir);
        $project = count($projects) > 1 ? self::buildProject($projects) : null;
        if ($project === null) {
            return $default;
        }
        $name = preg_match('/^[A-Za-z0-9._-]+$/', $project[0]) === 1 ? $project[0] : escapeshellarg($project[0]);

        return self::DEFAULT_BUILD . ' ' . $name;
    }

    /**
     * @return array<string, array<string, mixed>> angular.json's projects, `defaultProject` first
     */
    private static function projects(string $projectDir): array
    {
        $path = rtrim($projectDir, '/') . '/angular.json';
        if (!is_file($path)) {
            return [];
        }
        $json = json_decode((string) @file_get_contents($path), true);
        if (!is_array($json) || empty($json['projects']) || !is_array($json['projects'])) {
            return [];
        }

        $projects = array_filter($json['projects'], 'is_array');
        $default = $json['defaultProject'] ?? null;
        if (is_string($default) && isset($projects[$default])) {
            // Moved to the front, so it wins wherever it has an output.
            $projects = [$default => $projects[$default]] + $projects;
        }

        return $projects;
    }

    /**
     * The project whose build is served: `defaultProject`, else the first
     * application, else the first project at all that states an output path.
     *
     * @param array<string, array<string, mixed>> $projects
     * @return ?array{0: string, 1: array<string, mixed>}
     */
    private static function buildProject(array $projects): ?array
    {
        $fallback = null;
        foreach ($projects as $name => $project) {
            if (self::projectOutput($project) === null) {
                continue;
            }
            if (($project['projectType'] ?? 'application') === 'application') {
                return [(string) $name, $project];
            }
            $fallback ??= [(string) $name, $project];
        }

        return $fallback;
    }

    /** @param array<string, mixed> $project */
    private static function projectOutput(array $project): ?string
    {
        $build = $project['architect']['build'] ?? $project['targets']['build'] ?? null;
        $output = is_array($build) ? ($build['options']['outputPath'] ?? null) : null;
        if (is_string($output) && $output !== '') {
            // The `application` builder (Angular 17+) writes the client bundle
            // into `<outputPath>/browser` even for a string outputPath; the
            // legacy `browser` builder writes straight into it.
            $builder = is_string($build['builder'] ?? null) ? $build['builder'] : '';

            return str_ends_with($builder, ':application') ? rtrim($output, '/') . '/browser' : $output;
        }
        if (is_array($output)) {
            $base = is_string($output['base'] ?? null) ? $output['base'] : 'dist';
            $browser = is_string($output['browser'] ?? null) ? $output['browser'] : 'browser';

            // `browser: ''` flattens the client bundle into `base`.
            return trim($browser, '/') === ''
                ? rtrim($base, '/')
                : rtrim($base, '/') . '/' . ltrim($browser, '/');
        }

        return null;
    }
}
