<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Next.js in a workspace under `apps/`, `packages/`, `clients/` or `web/` rather than the root, the best by
 * package name when several qualify. Yields `workspace_relative`.
 */
final class NextWorkspaceProbe implements PlatformProbe
{
    /**
     * Preferred workspace package basenames when several Next apps exist; higher wins (storefront/web over docs/admin).
     *
     * @var array<string, int>
     */
    private const WORKSPACE_NAME_SCORE = [
        'web' => 100,
        'storefront' => 95,
        'next' => 90,
        'app' => 85,
        'site' => 80,
        'frontend' => 75,
        'dashboard' => 70,
        'website' => 65,
        'marketing' => 40,
        'docs' => 20,
        'admin' => 15,
    ];

    public function id(): string
    {
        return 'next-workspace';
    }

    /** A root declaring Next itself is the app, and a workspace hit is then a second Next app. */
    public function evaluate(ProjectContext $context): bool|array
    {
        $rootHasConfig = $context->hasConfigStem('next.config');
        $rootHasNext = $context->hasDep('next');
        $workspace = self::findWorkspaceApp($context->projectDir);

        if (! $rootHasConfig && ! $rootHasNext && $workspace === null) {
            return false;
        }

        if ($rootHasConfig || $rootHasNext) {
            return ['workspace_relative' => ''];
        }

        $relative = (string) ($workspace['relative'] ?? '');
        $data = ['workspace_relative' => $relative];

        $name = trim((string) ($workspace['package']['name'] ?? ''));
        if ($name !== '') {
            $data['workspace_package'] = $name;
        }
        $slug = basename($relative);
        if ($slug !== '' && $slug !== '.' && $slug !== '/') {
            $data['workspace_slug'] = $slug;
        }

        return $data;
    }

    public static function findWorkspaceApp(string $projectDir): ?array
    {
        $projectDir = rtrim($projectDir, '/');
        $best = null;
        $bestScore = PHP_INT_MIN;

        foreach (['apps', 'packages', 'clients', 'web'] as $group) {
            $base = $projectDir.'/'.$group;
            if (! is_dir($base)) {
                continue;
            }
            foreach (self::groupCandidates($base, $group) as [$score, $candidate]) {
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    /**
     * Every Next package in one group with its score, child directories first in listing order, then `web` itself.
     *
     * @return list<array{0: int, 1: array{dir: string, relative: string, package: array<mixed>}}>
     */
    private static function groupCandidates(string $base, string $group): array
    {
        $candidates = [];
        $entries = ProjectContext::entries($base);
        foreach ($entries as $name) {
            $candidate = self::childCandidate($base, $group, $name);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        $self = $group === 'web' ? self::webPackageCandidate($base) : null;
        if ($self !== null) {
            $candidates[] = $self;
        }

        return $candidates;
    }

    /** @return ?array{0: int, 1: array{dir: string, relative: string, package: array<mixed>}} */
    private static function childCandidate(string $base, string $group, string $name): ?array
    {
        if ($name === '.' || $name === '..' || ! is_dir($base.'/'.$name)) {
            return null;
        }
        $dir = $base.'/'.$name;
        $package = ProjectContext::readPackageJson($dir);
        if ($package === null) {
            return null;
        }
        $hasNext = ProjectContext::packageHasDep($package, 'next');
        $hasConfig = ProjectContext::firstConfigContents($dir, 'next.config') !== null;
        if (! $hasNext && ! $hasConfig) {
            return null;
        }

        return [
            self::childScore($name, $hasNext, $hasConfig),
            [
                'dir' => $dir,
                'relative' => $group.'/'.$name,
                'package' => $package,
            ],
        ];
    }

    /**
     * Declaring next beats a config-only stub, and both beat next alone: Teable's nestjs-backend imports next to
     * serve the app in apps/nextjs-app, and the listing order picked the backend.
     */
    private static function childScore(string $name, bool $hasNext, bool $hasConfig): int
    {
        $score = self::WORKSPACE_NAME_SCORE[strtolower($name)] ?? 50;
        if ($hasNext) {
            $score += 5;
        }
        if ($hasNext && $hasConfig) {
            $score += 2;
        }

        return $score;
    }

    /**
     * A directory named `web` that is itself the Next package, with no `web/web` child.
     *
     * @return ?array{0: int, 1: array{dir: string, relative: string, package: array<mixed>}}
     */
    private static function webPackageCandidate(string $base): ?array
    {
        if (is_file($base.'/package.json') && ! is_dir($base.'/web')) {
            $package = ProjectContext::readPackageJson($base);
            if ($package !== null
                && (ProjectContext::packageHasDep($package, 'next')
                    || ProjectContext::firstConfigContents($base, 'next.config') !== null)
            ) {
                $score = self::WORKSPACE_NAME_SCORE['web']
                    + (ProjectContext::packageHasDep($package, 'next') ? 5 : 0);

                return [
                    $score,
                    [
                        'dir' => $base,
                        'relative' => 'web',
                        'package' => $package,
                    ],
                ];
            }
        }

        return null;
    }
}
