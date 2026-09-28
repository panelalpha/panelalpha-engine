<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Next.js in a workspace under `apps/`, `packages/`, `clients/` or `web/`,
 * not at the repository root. Several candidates may qualify, so the tree is
 * walked and package names scored. Yields `workspace_relative`.
 */
final class NextWorkspaceProbe implements PlatformProbe
{
    /**
     * Preferred workspace package basenames when several Next apps exist.
     * Higher wins (storefront/web over docs/admin).
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

    public function evaluate(ProjectContext $context): bool|array
    {
        $rootHasConfig = $context->hasConfigStem('next.config');
        $rootHasNext = $context->hasDep('next');
        $workspace = self::findWorkspaceApp($context->projectDir);

        if (!$rootHasConfig && !$rootHasNext && $workspace === null) {
            return false;
        }

        // A root declaring Next itself is the app; a workspace hit is a
        // second Next app.
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
            $base = $projectDir . '/' . $group;
            if (!is_dir($base)) {
                continue;
            }
            $entries = ProjectContext::entries($base);
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..' || !is_dir($base . '/' . $name)) {
                    continue;
                }
                $dir = $base . '/' . $name;
                $package = ProjectContext::readPackageJson($dir);
                if ($package === null) {
                    continue;
                }
                $hasNext = ProjectContext::packageHasDep($package, 'next');
                $hasConfig = ProjectContext::firstConfigContents($dir, 'next.config') !== null;
                if (!$hasNext && !$hasConfig) {
                    continue;
                }

                $score = self::WORKSPACE_NAME_SCORE[strtolower($name)] ?? 50;
                // Prefer packages that declare next over config-only stubs.
                if ($hasNext) {
                    $score += 5;
                }
                // And one with its own next.config over one that only depends
                // on next: Teable's nestjs-backend imports next to serve the
                // app, apps/nextjs-app is the app, and the scandir order picked
                // the backend.
                if ($hasNext && $hasConfig) {
                    $score += 2;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = [
                        'dir' => $dir,
                        'relative' => $group . '/' . $name,
                        'package' => $package,
                    ];
                }
            }

            // Directory named `web` that is itself the Next package (no child).
            if ($group === 'web' && is_file($base . '/package.json') && !is_dir($base . '/web')) {
                $package = ProjectContext::readPackageJson($base);
                if ($package !== null
                    && (ProjectContext::packageHasDep($package, 'next')
                        || ProjectContext::firstConfigContents($base, 'next.config') !== null)
                ) {
                    $score = self::WORKSPACE_NAME_SCORE['web']
                        + (ProjectContext::packageHasDep($package, 'next') ? 5 : 0);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = [
                            'dir' => $base,
                            'relative' => 'web',
                            'package' => $package,
                        ];
                    }
                }
            }
        }

        return $best;
    }
}
