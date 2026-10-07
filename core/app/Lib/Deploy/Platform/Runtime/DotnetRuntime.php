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
     * The one project to publish: a web SDK project, else an `OutputType Exe`
     * that serves HTTP ({@see servesHttp()}). Publishing a solution instead fails with `NETSDK1194`
     * (test projects error and MSBuild's exit code is the build's). Tests are
     * excluded by path and name, and so is a web SDK project that says it is a
     * `Library` (Sonarr.SignalR) or targets only Windows. Null when nothing
     * looks like an application.
     */
    public static function entryProject(string $projectDir): ?string
    {
        return self::candidates($projectDir)['entry'];
    }

    /**
     * Executables, but none that serves HTTP: a console worker (the voting
     * app's Redis-to-Postgres mover), a desktop app, a build tool. Publishing
     * one deploys something that can never answer on a port.
     */
    public static function onlyConsoleExecutables(string $projectDir): bool
    {
        $found = self::candidates($projectDir);

        // A .NET Framework one stays claimed, so the deploy is refused with that reason.
        return $found['entry'] === null && $found['console'] !== null
            && self::legacyFrameworkOf($projectDir, $found['console']) === null;
    }

    /** @return array{entry: ?string, console: ?string} */
    private static function candidates(string $projectDir): array
    {
        $webSdk = null;
        $executable = null;
        $console = null;

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
                || self::looksLikeBenchmark($relative, $contents)
            ) {
                continue;
            }
            if (stripos($contents, 'Microsoft.NET.Sdk.Web') !== false) {
                $webSdk ??= $relative;
                continue;
            }
            if (preg_match('/<OutputType>\s*Exe\s*<\/OutputType>/i', $contents) === 1) {
                if (self::servesHttp(rtrim($projectDir, '/'), $relative)) {
                    $executable ??= $relative;
                } else {
                    $console ??= $relative;
                }
            }
        }

        return ['entry' => $webSdk ?? $executable, 'console' => $console];
    }

    /**
     * Whether a project, or one it references, hosts a web server: the web
     * SDK, ASP.NET Core (Prowlarr.Console reaches it through Prowlarr.Host),
     * another HTTP server package, or `HttpListener` in its own sources.
     *
     * @param array<string, true> $seen
     */
    private static function servesHttp(string $root, string $relative, array &$seen = []): bool
    {
        if (isset($seen[$relative]) || count($seen) >= 50) {
            return false;
        }
        $seen[$relative] = true;
        $contents = @file_get_contents($root . '/' . $relative);
        if (!is_string($contents)) {
            return false;
        }
        if (stripos($contents, 'Microsoft.NET.Sdk.Web') !== false
            || preg_match('/<FrameworkReference\s+Include\s*=\s*"Microsoft\.AspNetCore\.App"/i', $contents) === 1
            || preg_match(self::HTTP_SERVER_PACKAGES, $contents) === 1
        ) {
            return true;
        }

        $dir = dirname($relative);
        preg_match_all('/<ProjectReference\s+Include\s*=\s*"([^"]+)"/i', $contents, $refs);
        foreach ($refs[1] as $ref) {
            $path = self::normalizePath(($dir === '.' ? '' : $dir . '/') . str_replace('\\', '/', $ref));
            if ($path !== null && self::servesHttp($root, $path, $seen)) {
                return true;
            }
        }

        return self::sourcesUseHttpListener($root . ($dir === '.' ? '' : '/' . $dir));
    }

    private const HTTP_SERVER_PACKAGES = '/<PackageReference\s+Include\s*=\s*"[^"]*'
        . '(?:AspNetCore|Kestrel|EmbedIO|Nancy|ServiceStack|Suave|Giraffe|Saturn|WatsonWebserver|Owin)[^"]*"/i';

    /** `a/b/../c/x.csproj` -> `a/c/x.csproj`; null when it climbs out of the project. */
    private static function normalizePath(string $path): ?string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return $parts === [] ? null : implode('/', $parts);
    }

    /** A bounded look at a console project's own sources for a hand-rolled server. */
    private static function sourcesUseHttpListener(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        $checked = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if (!$file->isFile() || !preg_match('/\.(cs|fs|vb)$/', $file->getFilename())) {
                continue;
            }
            if (++$checked > 200) {
                break;
            }
            $source = @file_get_contents($file->getPathname());
            if (is_string($source) && preg_match('/\bHttpListener\b/', $source) === 1) {
                return true;
            }
        }

        return false;
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
     * A BenchmarkDotNet harness is an Exe but never the app: Kavita.Benchmark
     * sorted before Kavita.Server and was published instead.
     */
    private static function looksLikeBenchmark(string $relative, string $contents): bool
    {
        return preg_match('/(^|[\/.])benchmarks?([\/.]|$)/i', $relative) === 1
            || preg_match('/<PackageReference\s+Include\s*=\s*"BenchmarkDotNet"/i', $contents) === 1;
    }

    /**
     * The highest `net<major>.<minor>` any project file targets, solution-wide
     * -- the SDK that builds the newest builds the rest. `netstandard2.0` and
     * `net48` are library targets and are ignored. Only `<TargetFramework(s)>`
     * values count: Emby's HintPath `sqlite3.net45.1.1.11` read as sdk:45.1.
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
            foreach (self::declaredFrameworks($contents) as $version) {
                if ($best === null || version_compare($version, $best, '>')) {
                    $best = $version;
                }
            }
        }

        return $best;
    }

    /**
     * "<project> targets .NET Framework v4.7" when the project that would be
     * published is a classic .NET Framework one (`<TargetFrameworkVersion>`, no
     * `<TargetFramework>`), which `dotnet publish` on Linux cannot build.
     */
    public static function legacyFrameworkEntry(string $projectDir): ?string
    {
        $found = self::candidates($projectDir);
        $entry = $found['entry'] ?? $found['console'];

        return $entry === null ? null : self::legacyFrameworkOf($projectDir, $entry);
    }

    private static function legacyFrameworkOf(string $projectDir, string $entry): ?string
    {
        $contents = @file_get_contents(rtrim($projectDir, '/') . '/' . $entry);
        if (!is_string($contents)
            || preg_match('/<TargetFrameworks?>/i', $contents) === 1
            || preg_match('/<TargetFrameworkVersion>\s*([^<]+?)\s*<\/TargetFrameworkVersion>/i', $contents, $m) !== 1
        ) {
            return null;
        }

        return $entry . ' targets .NET Framework ' . $m[1];
    }

    /**
     * `X.Y` of every `netX.Y` (or `netX.Y-<platform>`) a project file's
     * `<TargetFramework>` / `<TargetFrameworks>` names.
     *
     * @return list<string>
     */
    private static function declaredFrameworks(string $contents): array
    {
        preg_match_all('/<TargetFrameworks?>\s*([^<]*?)\s*<\/TargetFrameworks?>/i', $contents, $elements);

        $versions = [];
        foreach ($elements[1] as $list) {
            foreach (explode(';', $list) as $framework) {
                if (preg_match('/^net(\d+)\.(\d+)(?:-|$)/i', trim($framework), $m) === 1) {
                    $versions[] = $m[1] . '.' . $m[2];
                }
            }
        }

        return $versions;
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

        return 'echo ' . escapeshellarg(self::AUDIT_TARGETS) . ' > ' . self::AUDIT_TARGETS_FILE . ' && '
            . 'dotnet publish' . ($target === null ? '' : ' ' . escapeshellarg($target))
            . ($framework === null ? '' : ' -f ' . $framework)
            . ($solutionDir === null ? '' : ' -p:SolutionDir="$PWD/' . ($solutionDir === '.' ? '' : $solutionDir . '/') . '"')
            . ' -c Release -o ' . self::PUBLISH_DIR . ' --nologo'
            . ' -p:CustomAfterMicrosoftCommonTargets=' . self::AUDIT_TARGETS_FILE;
    }

    /**
     * NuGet audit (NU1900-NU1904) stays a warning under the project's
     * TreatWarningsAsErrors: Flink stopped at restore on `error NU1903: Warning
     * As Error` for a transitive package. Appended to the project's own
     * WarningsNotAsErrors from a targets file, because `-p:WarningsNotAsErrors=`
     * would replace that list and turn its codes back into errors.
     */
    private const AUDIT_TARGETS = '<Project><PropertyGroup><WarningsNotAsErrors>'
        . '$(WarningsNotAsErrors);NU1900;NU1901;NU1902;NU1903;NU1904'
        . '</WarningsNotAsErrors></PropertyGroup></Project>';

    private const AUDIT_TARGETS_FILE = '/tmp/panelalpha-nuget-audit.targets';

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
     * It runs from inside the publish directory: ASP.NET takes its content root,
     * and so wwwroot, from the working directory.
     */
    public static function startCommand(): string
    {
        $dir = self::PUBLISH_DIR;

        return 'cfg=$(ls ' . $dir . '/*.runtimeconfig.json 2>/dev/null | head -n 1);'
            . ' [ -n "$cfg" ]'
            . ' || { echo "PANELALPHA: dotnet publish produced no runnable assembly in ' . $dir . '/";'
            . ' echo "PANELALPHA: published files: $(ls ' . $dir . ' 2>/dev/null | head -n 20)";'
            . ' exit 1; };'
            . ' cd ' . $dir . ' && exec dotnet "$(basename "${cfg%.runtimeconfig.json}").dll"';
    }
}
