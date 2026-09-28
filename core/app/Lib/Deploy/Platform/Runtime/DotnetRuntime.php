<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * .NET.
 *
 * Version source: `global.json` sdk.version, then the highest `netX.Y`
 * TargetFramework; the SDK is backwards compatible. One image builds and runs.
 */
final class DotnetRuntime implements Runtime
{
    /** Compiled-in fallback for the catalogue's `image.from`. */
    public const IMAGE = 'mcr.microsoft.com/dotnet/sdk:8.0';

    public const VERSION = '8.0';

    /** `dotnet publish` output directory, relative to the project. */
    public const PUBLISH_DIR = 'out';

    /** An MSBuild `<Exec>` running a JS tool, e.g. Memtly.Core's `npm ci` target. */
    private const NODE_EXEC = '/<Exec\b[^>]*\bCommand\s*=\s*"\s*(?:npm|npx|yarn|pnpm|node)\b/i';

    public static function defaultImage(): string
    {
        return self::imageTag(self::VERSION);
    }

    public static function imageTag(string $version): string
    {
        $spec = RuntimeImageCatalog::spec('dotnet', $version);

        return $spec?->from ?? self::IMAGE;
    }

    public function id(): string
    {
        return 'dotnet';
    }

    public function resolve(ProjectContext $context): ?Requirement
    {
        $global = $context->json('global.json');
        if (is_array($global)) {
            $sdk = $global['sdk']['version'] ?? null;
            if (is_string($sdk) && preg_match('/^(\d+)\.(\d+)/', $sdk, $m) === 1) {
                return new Requirement('dotnet', $m[1] . '.0', $sdk, 'global.json sdk.version');
            }
        }

        $target = self::targetFramework($context->projectDir);
        if ($target !== null) {
            return new Requirement('dotnet', $target, 'net' . $target, 'TargetFramework');
        }

        return self::hasProject($context->projectDir)
            ? new Requirement('dotnet', self::VERSION, '', 'engine default')
            : null;
    }

    public function defaultRequirement(): Requirement
    {
        return new Requirement('dotnet', self::VERSION, '', 'engine default');
    }

    /**
     * @return list<string>
     */
    public function supportedVersions(): array
    {
        return ['8.0', '9.0'];
    }

    public function image(Requirement $requirement): string
    {
        return self::imageTag($requirement->version);
    }

    /**
     * A solution listing a C#, F# or VB project, or such a project file.
     * Searched three levels down: many repositories keep sources under src/.
     */
    public static function hasProject(string $projectDir): bool
    {
        return self::findProjectFiles($projectDir) !== [];
    }

    /**
     * @return list<string> paths relative to the project root
     */
    public static function findProjectFiles(string $projectDir): array
    {
        $projectDir = rtrim($projectDir, '/');
        $found = [];

        foreach (['*.sln', '*.slnx', '*.csproj', '*.fsproj', '*.vbproj'] as $pattern) {
            // root, Jellyfin.Server/, src/Foo/
            foreach (['/', '/*/', '/*/*/'] as $depth) {
                foreach (glob($projectDir . $depth . $pattern) ?: [] as $path) {
                    if (str_contains($pattern, '.sln') && !self::solutionIsManaged($path)) {
                        continue;
                    }
                    $relative = ltrim(substr($path, strlen($projectDir)), '/');
                    $found[$relative] = true;
                }
            }
        }

        $names = array_keys($found);
        sort($names);

        return $names;
    }

    /**
     * A C++-only solution (Seafile's holds just a .vcxproj) is not .NET and
     * cannot be published.
     */
    private static function solutionIsManaged(string $path): bool
    {
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            return false;
        }

        return str_ends_with($path, '.slnx')
            ? preg_match('/Path\s*=\s*"[^"]*\.(cs|fs|vb)proj"/i', $contents) === 1
            : preg_match('/^\s*Project\("[^"]*"\)\s*=\s*"[^"]*"\s*,\s*"[^"]*\.(cs|fs|vb)proj"/mi', $contents) === 1;
    }

    /**
     * The one project to publish: a web SDK project, else one declaring
     * `OutputType Exe`. Publishing a solution instead fails with `NETSDK1194`
     * (test projects error and MSBuild's exit code is the build's). Tests are
     * excluded by path and name, and so is a web SDK project that says it is a
     * `Library` (Sonarr.SignalR) or targets only Windows. Null when nothing
     * looks like an application.
     */
    public static function entryProject(string $projectDir): ?string
    {
        $webSdk = null;
        $executable = null;

        foreach (self::findProjectFiles($projectDir) as $relative) {
            if (!preg_match('/\.(cs|fs|vb)proj$/', $relative) || self::looksLikeTests($relative)) {
                continue;
            }
            $contents = @file_get_contents(rtrim($projectDir, '/') . '/' . $relative);
            if (!is_string($contents)) {
                continue;
            }
            if (preg_match('/<OutputType>\s*Library\s*<\/OutputType>/i', $contents) === 1
                || self::targetsOnlyWindows($contents)
            ) {
                continue;
            }
            if (stripos($contents, 'Microsoft.NET.Sdk.Web') !== false) {
                $webSdk ??= $relative;
                continue;
            }
            if (preg_match('/<OutputType>\s*Exe\s*<\/OutputType>/i', $contents) === 1) {
                $executable ??= $relative;
            }
        }

        return $webSdk ?? $executable;
    }

    /**
     * A test project, by the two conventions every .NET repository follows.
     */
    private static function looksLikeTests(string $relative): bool
    {
        $lower = strtolower($relative);

        return str_contains($lower, 'tests/')
            || str_contains($lower, 'test/')
            || (bool) preg_match('/\.tests?\.(cs|fs|vb)proj$/', $lower);
    }

    /**
     * The highest `net<major>.<minor>` any project file targets, solution-wide
     * -- the SDK that builds the newest builds the rest. `netstandard2.0` and
     * `net48` are library targets and are ignored.
     */
    public static function targetFramework(string $projectDir): ?string
    {
        $best = null;
        foreach (self::findProjectFiles($projectDir) as $relative) {
            if (str_ends_with($relative, '.sln') || str_ends_with($relative, '.slnx')) {
                continue;
            }
            $contents = @file_get_contents(rtrim($projectDir, '/') . '/' . $relative);
            if (!is_string($contents)) {
                continue;
            }
            if (preg_match_all('/net(\d+)\.(\d+)/i', $contents, $matches, PREG_SET_ORDER) < 1) {
                continue;
            }
            foreach ($matches as $m) {
                $version = $m[1] . '.' . $m[2];
                if ($best === null || version_compare($version, $best, '>')) {
                    $best = $version;
                }
            }
        }

        return $best;
    }

    /**
     * The Node image a build needs beside the SDK, or null. The SDK image has
     * no Node, and an ASP.NET project whose targets run `npm ci` during
     * publish fails with MSB3073 / exit 127. Chosen from the package.json next
     * to the project that runs it, else the engine's default Node.
     */
    public static function nodeBuildImage(string $projectDir): ?string
    {
        $root = rtrim($projectDir, '/');
        if ($root === '') {
            return null;
        }

        $files = self::findProjectFiles($root);
        foreach (['/', '/*/', '/*/*/'] as $depth) {
            foreach (['*.props', '*.targets'] as $pattern) {
                foreach (glob($root . $depth . $pattern) ?: [] as $path) {
                    $files[] = ltrim(substr($path, strlen($root)), '/');
                }
            }
        }

        foreach ($files as $relative) {
            if (str_ends_with($relative, '.sln') || str_ends_with($relative, '.slnx')) {
                continue;
            }
            $contents = @file_get_contents($root . '/' . $relative);
            if (!is_string($contents) || preg_match(self::NODE_EXEC, $contents) !== 1) {
                continue;
            }
            $dir = dirname($root . '/' . $relative);

            return NodeRuntime::imageFor(is_file($dir . '/package.json') ? $dir : '');
        }

        return null;
    }

    /**
     * `dotnet build` scatters output across each project's bin/; publish
     * gathers one runnable directory. No `--no-restore`: restore is skipped
     * nowhere and omitting it fails a clean checkout.
     */
    public static function buildCommand(string $projectDir = ''): string
    {
        $target = $projectDir === '' ? null : self::entryProject($projectDir);
        $framework = $target === null ? null : self::publishFramework($projectDir, $target);
        $solutionDir = $target === null ? null : self::solutionDir($projectDir, $target);

        return 'dotnet publish' . ($target === null ? '' : ' ' . escapeshellarg($target))
            . ($framework === null ? '' : ' -f ' . $framework)
            . ($solutionDir === null ? '' : ' -p:SolutionDir="$PWD/' . ($solutionDir === '.' ? '' : $solutionDir . '/') . '"')
            . ' -c Release -o ' . self::PUBLISH_DIR . ' --nologo';
    }

    /**
     * The directory of the nearest solution above a project, relative to the
     * project root ('.' for the root), or null outside any solution.
     *
     * Publishing one project leaves `$(SolutionDir)` undefined, where building
     * the solution sets it; Prowlarr's props find stylecop.json through it, and
     * without it every file failed SA1200 under TreatWarningsAsErrors.
     */
    public static function solutionDir(string $projectDir, string $project): ?string
    {
        $root = rtrim($projectDir, '/');
        $dir = dirname($project);
        while (true) {
            $path = $root . ($dir === '.' ? '' : '/' . $dir);
            if ((glob($path . '/*.sln') ?: []) !== [] || (glob($path . '/*.slnx') ?: []) !== []) {
                return $dir;
            }
            if ($dir === '.' || $dir === '/' || $dir === '') {
                return null;
            }
            $dir = dirname($dir);
        }
    }

    /**
     * The framework to publish a project for, or null when the SDK needs no
     * `-f`. `<TargetFrameworks>` -- plural, even with one entry -- makes
     * publish refuse to guess (`NETSDK1129`; Prowlarr, Sonarr, Radarr), so the
     * newest plain `netX.Y` in it is named. The list may come from a
     * Directory.Build.props above the project.
     */
    public static function publishFramework(string $projectDir, string $project): ?string
    {
        $root = rtrim($projectDir, '/');
        $contents = @file_get_contents($root . '/' . $project);
        if (!is_string($contents)) {
            return null;
        }

        $list = self::frameworkList($contents);
        if ($list === null && preg_match('/<TargetFramework>/i', $contents) !== 1) {
            $list = self::inheritedFrameworkList($root, dirname($project));
        }
        if ($list === null) {
            return null;
        }

        $best = null;
        foreach (explode(';', $list) as $framework) {
            $framework = strtolower(trim($framework));
            if (preg_match('/^net(\d+)\.(\d+)$/', $framework, $m) === 1
                && ($best === null || version_compare($m[1] . '.' . $m[2], substr($best, 3), '>'))
            ) {
                $best = $framework;
            }
        }

        return $best;
    }

    /** The nearest Directory.Build.props that sets a framework decides. */
    private static function inheritedFrameworkList(string $root, string $dir): ?string
    {
        while (true) {
            $props = @file_get_contents($root . ($dir === '.' ? '' : '/' . $dir) . '/Directory.Build.props');
            if (is_string($props) && preg_match('/<TargetFrameworks?>/i', $props) === 1) {
                return self::frameworkList($props);
            }
            if ($dir === '.' || $dir === '/' || $dir === '') {
                return null;
            }
            $dir = dirname($dir);
        }
    }

    private static function frameworkList(string $contents): ?string
    {
        return preg_match('/<TargetFrameworks>\s*([^<]+?)\s*<\/TargetFrameworks>/i', $contents, $m) === 1
            ? $m[1]
            : null;
    }

    /** Radarr.csproj targets net8.0-windows: a WinForms tray app, not a server. */
    private static function targetsOnlyWindows(string $contents): bool
    {
        if (preg_match('/<TargetFrameworks?>\s*([^<]+?)\s*<\/TargetFrameworks?>/i', $contents, $m) !== 1) {
            return false;
        }
        foreach (explode(';', $m[1]) as $framework) {
            if (trim($framework) !== '' && stripos($framework, '-windows') === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * The entry assembly is the one with a runtimeconfig beside it -- a publish
     * directory holds dozens of library DLLs and no name distinguishes them.
     * With nothing runnable this exits instead of restart-looping behind a 502.
     */
    public static function startCommand(): string
    {
        $dir = self::PUBLISH_DIR;

        return 'cfg=$(ls ' . $dir . '/*.runtimeconfig.json 2>/dev/null | head -n 1);'
            . ' [ -n "$cfg" ]'
            . ' || { echo "PANELALPHA: dotnet publish produced no runnable assembly in ' . $dir . '/";'
            . ' echo "PANELALPHA: published files: $(ls ' . $dir . ' 2>/dev/null | head -n 20)";'
            . ' exit 1; };'
            . ' exec dotnet "${cfg%.runtimeconfig.json}.dll"';
    }
}
