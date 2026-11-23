<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\JsWorkspaceMembers;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;

/**
 * Where a static Astro build writes, without running it: the site's literal `outDir`, else `dist`, under the workspace
 * member holding the astro.config when the root has none, and its `client/` half when the config sets an adapter.
 */
final class AstroOutputDir
{
    private const CONFIG = 'astro.config';

    /** Astro's default `build.client`, where an adapter build puts the pages. */
    private const ADAPTER_CLIENT = 'client';

    /** `pnpm --filter x`, `pnpm -F x`, `npm -w x`, `npm --workspace=x`, `yarn workspace x`. */
    private const WORKSPACE_FILTER = '/(?:--filter|-F|--workspace|-w)(?:=|\s+)[\'"]?([^\s\'"&|;]+)|\byarn\s+workspace\s+([^\s&|;]+)/';

    public static function outputDir(string $projectDir, string $default = 'dist'): string
    {
        $root = rtrim($projectDir, '/');
        if ($root === '') {
            return $default;
        }
        $site = self::siteDirectory($root);
        $config = self::astroOwnPart((string) ProjectContext::firstConfigContents(self::under($root, $site), self::CONFIG));
        $output = self::under($site, self::outDir($config) ?? $default);

        return NodeRuntime::safeOutputDir(self::hasAdapter($config) ? $output.'/'.self::ADAPTER_CLIENT : $output, $default);
    }

    /** Where the astro.config is: the root (''), the only workspace member with one, or the one the build filters to. */
    private static function siteDirectory(string $root): string
    {
        if (ProjectContext::firstConfigContents($root, self::CONFIG) !== null) {
            return '';
        }
        $package = ProjectContext::readPackageJson($root) ?? [];
        $sites = array_values(array_filter(
            JsWorkspaceMembers::directories($package, $root),
            static fn (string $dir): bool => ProjectContext::firstConfigContents($root.'/'.$dir, self::CONFIG) !== null
        ));
        if (count($sites) === 1) {
            return $sites[0];
        }
        $build = $package['scripts']['build'] ?? null;

        return self::filteredSite($root, $sites, is_string($build) ? $build : '') ?? '';
    }

    /**
     * The one site whose package name or directory name the build script filters to.
     *
     * @param  list<string>  $sites
     */
    private static function filteredSite(string $root, array $sites, string $build): ?string
    {
        preg_match_all(self::WORKSPACE_FILTER, $build, $m);
        $names = array_filter([...$m[1], ...$m[2]], static fn (string $name): bool => $name !== '');
        $matching = array_values(array_filter(
            $sites,
            static fn (string $dir): bool => array_intersect($names, [basename($dir), self::packageName($root.'/'.$dir)]) !== []
        ));

        return count($matching) === 1 ? $matching[0] : null;
    }

    private static function packageName(string $dir): string
    {
        $name = (ProjectContext::readPackageJson($dir) ?? [])['name'] ?? null;

        return is_string($name) ? $name : '';
    }

    /** The config without comments and without its `vite` block, whose `build.outDir` is Vite's, not Astro's. */
    private static function astroOwnPart(string $config): string
    {
        $config = (string) preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $config);

        return str_replace(ConfigBlock::of($config, 'vite'), '', $config);
    }

    /** A literal, project-relative `outDir`, or null. */
    private static function outDir(string $config): ?string
    {
        if (preg_match('/\boutDir\s*:\s*([\'"`])([^\'"`$]+)\1/', $config, $m) !== 1 || str_starts_with($m[2], '/')) {
            return null;
        }
        $dir = trim((string) preg_replace('#^(?:\./)+#', '', $m[2]), '/');

        return $dir === '' || $dir === '.' ? null : $dir;
    }

    private static function hasAdapter(string $config): bool
    {
        return preg_match('/\badapter\s*:(?!\s*(?:undefined|null)\b)/', $config) === 1;
    }

    private static function under(string $base, string $path): string
    {
        return $base === '' ? $path : ($path === '' ? $base : $base.'/'.$path);
    }
}
