<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * .NET, versioned by `global.json` sdk.version (or Arcade's tools.dotnet), then the highest `netX.Y` TargetFramework.
 * The SDK is backwards compatible, so one image builds and runs.
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
        $pinned = self::pinnedSdk($context->json('global.json') ?? []);
        if ($pinned !== null) {
            return $pinned;
        }

        $target = self::targetFramework($context->projectDir);
        if ($target !== null) {
            return new Requirement('dotnet', $target, 'net'.$target, 'TargetFramework');
        }

        return self::hasProject($context->projectDir)
            ? new Requirement('dotnet', self::VERSION, '', 'engine default')
            : null;
    }

    /**
     * The SDK global.json pins: `sdk.version`, else the `tools.dotnet` an Arcade build (dotnet/maui) installs instead.
     *
     * @param  array<mixed>  $global
     */
    private static function pinnedSdk(array $global): ?Requirement
    {
        $pins = [
            'sdk.version' => $global['sdk']['version'] ?? null,
            'tools.dotnet' => $global['tools']['dotnet'] ?? null,
        ];
        foreach ($pins as $key => $sdk) {
            if (is_string($sdk) && preg_match('/^(\d+)\.(\d+)/', $sdk, $m) === 1) {
                return new Requirement('dotnet', $m[1].'.0', $sdk, 'global.json '.$key);
            }
        }

        return null;
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
     * Solutions and projects in the root and two levels below, then the deeper projects a root solution lists,
     * so a project within reach stays ahead of one only the solution names.
     *
     * @return list<string> paths relative to the project root
     */
    public static function findProjectFiles(string $projectDir): array
    {
        $projectDir = rtrim($projectDir, '/');
        $near = self::projectFilesWithinReach($projectDir);
        $deeper = array_values(array_diff(self::rootSolutionProjects($projectDir, $near), $near));
        sort($deeper);

        return [...$near, ...$deeper];
    }

    /**
     * @return list<string>
     */
    private static function projectFilesWithinReach(string $projectDir): array
    {
        $found = [];
        foreach (['*.sln', '*.slnx', '*.csproj', '*.fsproj', '*.vbproj'] as $pattern) {
            foreach (['/', '/*/', '/*/*/'] as $depth) {
                foreach (glob($projectDir.$depth.$pattern) ?: [] as $path) {
                    if (str_contains($pattern, '.sln') && ! self::solutionIsManaged($path)) {
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
     * The existing projects the root solutions list (BootstrapAdmin's web project is four folders down).
     *
     * @param  list<string>  $found
     * @return list<string>
     */
    private static function rootSolutionProjects(string $projectDir, array $found): array
    {
        $projects = [];
        foreach ($found as $relative) {
            if (str_contains($relative, '/') || ! self::isSolution($relative)) {
                continue;
            }
            $contents = AccountHomeRead::contents($projectDir.'/'.$relative);
            foreach (self::projectsListedIn($relative, is_string($contents) ? $contents : '') as $path) {
                if (is_file($projectDir.'/'.$path)) {
                    $projects[$path] = true;
                }
            }
        }

        return array_keys($projects);
    }

    /**
     * The C#, F# and VB projects a root .sln or .slnx lists, relative to the root. Their paths are written relative,
     * often with backslashes; one that leads out of the repository is dropped.
     *
     * @return list<string>
     */
    public static function projectsListedIn(string $solution, string $contents): array
    {
        $projects = [];
        foreach (self::listedProjects($solution, $contents) as $listed) {
            $path = self::normalizePath(str_replace('\\', '/', $listed));
            if ($path !== null) {
                $projects[$path] = true;
            }
        }

        return array_keys($projects);
    }

    public static function isSolution(string $relative): bool
    {
        return str_ends_with($relative, '.sln') || str_ends_with($relative, '.slnx');
    }

    /**
     * A C++-only solution (Seafile's holds just a .vcxproj) is not .NET and
     * cannot be published.
     */
    private static function solutionIsManaged(string $path): bool
    {
        $contents = AccountHomeRead::contents($path);

        return is_string($contents) && self::listedProjects($path, $contents) !== [];
    }

    /**
     * The C#, F# and VB project paths a .sln or .slnx names, as written.
     *
     * @return list<string>
     */
    private static function listedProjects(string $solution, string $contents): array
    {
        $pattern = str_ends_with($solution, '.slnx')
            ? '/Path\s*=\s*"([^"]*\.(?:cs|fs|vb)proj)"/i'
            : '/^\s*Project\("[^"]*"\)\s*=\s*"[^"]*"\s*,\s*"([^"]*\.(?:cs|fs|vb)proj)"/mi';
        preg_match_all($pattern, $contents, $m);

        return $m[1];
    }

    /**
     * The one project to publish: a web SDK project, else an Exe that serves HTTP; a solution fails `NETSDK1194`.
     * Tests, libraries (Sonarr.SignalR) and Windows-only projects are skipped; null when nothing is an app.
     */
    public static function entryProject(string $projectDir): ?string
    {
        return self::candidates($projectDir)['entry'];
    }

    /**
     * No project that could answer on a port, only workers, desktop apps, libraries and tests. A .NET Framework one is
     * left to later strategies too, and refused with that reason only when none claims the repository.
     */
    public static function servesNothing(string $projectDir): bool
    {
        return self::candidates($projectDir)['entry'] === null;
    }

    /**
     * A project that could serve HTTP but names its target framework only through an MSBuild property the repository's
     * own build sets (dotnet/maui's sample servers), with that framework as written; null when none was passed over.
     *
     * @return array{file: string, framework: string}|null
     */
    public static function unresolvedFrameworkProject(string $projectDir): ?array
    {
        $file = self::candidates($projectDir)['unresolved'];
        if ($file === null) {
            return null;
        }
        $contents = AccountHomeRead::contents(rtrim($projectDir, '/').'/'.$file);
        preg_match('/<TargetFrameworks?>\s*([^<]*?)\s*<\/TargetFrameworks?>/i', is_string($contents) ? $contents : '', $m);

        return ['file' => $file, 'framework' => $m[1] ?? ''];
    }

    /**
     * classic: a .NET Framework web app; unresolved: a server passed over for a framework only a property names.
     *
     * @return array{entry: ?string, console: ?string, classic: ?string, unresolved: ?string}
     */
    private static function candidates(string $projectDir): array
    {
        $webSdk = null;
        $executable = null;
        $console = null;
        $classic = null;
        $unresolvedWeb = null;
        $unresolvedExecutable = null;

        foreach (self::findProjectFiles($projectDir) as $relative) {
            if (! preg_match('/\.(cs|fs|vb)proj$/', $relative) || self::looksLikeTests($relative)) {
                continue;
            }
            $contents = AccountHomeRead::contents(rtrim($projectDir, '/').'/'.$relative);
            if (! is_string($contents)) {
                continue;
            }
            if (self::isFrameworkWebApp($contents)) {
                $classic ??= $relative;

                continue;
            }
            if (self::isLibrary($contents)
                || self::targetsOnlyWindows($contents)
                || self::looksLikeBenchmark($relative, $contents)
            ) {
                continue;
            }
            if (self::targetsOnlyAProperty($contents)) {
                if (self::usesWebSdk($contents)) {
                    $unresolvedWeb ??= $relative;
                } elseif (self::isServingExecutable(rtrim($projectDir, '/'), $relative, $contents)) {
                    $unresolvedExecutable ??= $relative;
                }

                continue;
            }
            if (self::usesWebSdk($contents)) {
                $webSdk ??= $relative;

                continue;
            }
            if (self::isDesktopApp($contents)) {
                $console ??= $relative;

                continue;
            }
            if (self::isExecutable($contents)) {
                if (self::servesHttp(rtrim($projectDir, '/'), $relative)) {
                    $executable ??= $relative;
                } else {
                    $console ??= $relative;
                }
            }
        }

        return [
            'entry' => $webSdk ?? $executable,
            'console' => $console,
            'classic' => $classic,
            'unresolved' => $unresolvedWeb ?? $unresolvedExecutable,
        ];
    }

    private static function isExecutable(string $contents): bool
    {
        return preg_match('/<OutputType>\s*Exe\s*<\/OutputType>/i', $contents) === 1;
    }

    private static function isServingExecutable(string $root, string $relative, string $contents): bool
    {
        return self::isExecutable($contents) && self::servesHttp($root, $relative);
    }

    /**
     * Whether a project, or one it references (Prowlarr.Console via Prowlarr.Host), hosts a web server:
     * the web SDK, ASP.NET Core, another HTTP server package, or `HttpListener` in its sources.
     *
     * @param  array<string, true>  $seen
     */
    private static function servesHttp(string $root, string $relative, array &$seen = []): bool
    {
        if (isset($seen[$relative]) || count($seen) >= 50) {
            return false;
        }
        $seen[$relative] = true;
        $contents = AccountHomeRead::contents($root.'/'.$relative);
        if (! is_string($contents)) {
            return false;
        }
        if (self::usesWebSdk($contents)
            || preg_match('/<FrameworkReference\s+Include\s*=\s*"Microsoft\.AspNetCore\.App"/i', $contents) === 1
            || preg_match(self::HTTP_SERVER_PACKAGES, $contents) === 1
        ) {
            return true;
        }

        $dir = dirname($relative);
        preg_match_all('/<ProjectReference\s+Include\s*=\s*"([^"]+)"/i', $contents, $refs);
        foreach ($refs[1] as $ref) {
            $path = self::normalizePath(($dir === '.' ? '' : $dir.'/').str_replace('\\', '/', $ref));
            if ($path !== null && self::servesHttp($root, $path, $seen)) {
                return true;
            }
        }

        return self::sourcesUseHttpListener($root.($dir === '.' ? '' : '/'.$dir));
    }

    private const HTTP_SERVER_PACKAGES = '/<PackageReference\s+Include\s*=\s*"[^"]*'
        .'(?:AspNetCore|Kestrel|EmbedIO|Nancy|ServiceStack|Suave|Giraffe|Saturn|WatsonWebserver|Owin)[^"]*"/i';

    /** VpnHood's browser UI is Microsoft.NET.Sdk.WebAssembly, which publishes a page and serves nothing. */
    private static function usesWebSdk(string $contents): bool
    {
        return preg_match('/Microsoft\.NET\.Sdk\.Web(?![\w.])/i', $contents) === 1;
    }

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
        if (! is_dir($dir)) {
            return false;
        }
        $checked = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if (! $file->isFile() || ! preg_match('/\.(cs|fs|vb)$/', $file->getFilename())) {
                continue;
            }
            if (++$checked > 200) {
                break;
            }
            $source = AccountHomeRead::contents($file->getPathname());
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
     * The highest `netX.Y` any project's `<TargetFramework(s)>` names; the SDK building it builds the rest.
     * Other text does not count: Emby's HintPath `sqlite3.net45.1.1.11` read as sdk:45.1.
     */
    public static function targetFramework(string $projectDir): ?string
    {
        $best = null;
        foreach (self::findProjectFiles($projectDir) as $relative) {
            if (self::isSolution($relative)) {
                continue;
            }
            $contents = AccountHomeRead::contents(rtrim($projectDir, '/').'/'.$relative);
            if (! is_string($contents)) {
                continue;
            }
            foreach (self::frameworksOf(rtrim($projectDir, '/'), $relative, $contents) as $version) {
                if ($best === null || version_compare($version, $best, '>')) {
                    $best = $version;
                }
            }
        }

        return $best;
    }

    /**
     * The project to publish, or with none a .NET Framework web app, and the .NET Framework version it targets
     * (`v4.7`) when it is a .NET Framework one, which `dotnet publish` on Linux cannot build.
     *
     * @return array{file: string, framework: string, sdk_style: bool}|null
     */
    public static function legacyFrameworkEntry(string $projectDir): ?array
    {
        $found = self::candidates($projectDir);
        $web = $found['entry'] ?? $found['classic'];

        return $web === null
            ? self::classicConsole($projectDir, $found['console'])
            : self::legacyFrameworkOf($projectDir, $web);
    }

    /**
     * A console or desktop program that is a classic .NET Framework project; an SDK-style `net4x` one only serves
     * nothing.
     *
     * @return array{file: string, framework: string, sdk_style: bool}|null
     */
    private static function classicConsole(string $projectDir, ?string $console): ?array
    {
        $legacy = $console === null ? null : self::legacyFrameworkOf($projectDir, $console);

        return $legacy !== null && ! $legacy['sdk_style'] ? $legacy : null;
    }

    /**
     * @return array{file: string, framework: string, sdk_style: bool}|null
     */
    private static function legacyFrameworkOf(string $projectDir, string $entry): ?array
    {
        $contents = AccountHomeRead::contents(rtrim($projectDir, '/').'/'.$entry);
        if (! is_string($contents)) {
            return null;
        }
        $classic = self::classicFrameworkVersion($contents);
        $version = $classic ?? self::sdkFrameworkVersion($contents);

        return $version === null ? null : ['file' => $entry, 'framework' => $version, 'sdk_style' => $classic === null];
    }

    /** The .NET Framework version a classic or an SDK-style project targets; null for one targeting .NET at all. */
    private static function frameworkVersion(string $contents): ?string
    {
        return self::classicFrameworkVersion($contents) ?? self::sdkFrameworkVersion($contents);
    }

    /** The `<TargetFrameworkVersion>` of a classic .NET Framework project; null for an SDK-style one. */
    private static function classicFrameworkVersion(string $contents): ?string
    {
        return preg_match('/<TargetFrameworks?>/i', $contents) !== 1
            && preg_match('/<TargetFrameworkVersion>\s*([^<]+?)\s*<\/TargetFrameworkVersion>/i', $contents, $m) === 1
            ? $m[1]
            : null;
    }

    /**
     * `v4.8` for an SDK-style project whose `<TargetFramework(s)>` name only .NET Framework (`net48`, `net472`), the
     * newest when several; null when it names any other.
     */
    private static function sdkFrameworkVersion(string $contents): ?string
    {
        preg_match_all('/<TargetFrameworks?>\s*([^<]*?)\s*<\/TargetFrameworks?>/i', $contents, $elements);
        $best = null;
        foreach ($elements[1] as $list) {
            foreach (explode(';', $list) as $framework) {
                $framework = strtolower(trim($framework));
                if ($framework === '') {
                    continue;
                }
                if (preg_match('/^net([1-4]\d{1,2})$/', $framework, $m) !== 1) {
                    return null;
                }
                $version = implode('.', str_split($m[1]));
                if ($best === null || version_compare($version, $best, '>')) {
                    $best = $version;
                }
            }
        }

        return $best === null ? null : 'v'.$best;
    }

    /**
     * An ASP.NET app on .NET Framework, which no Linux SDK runs: System.Web MVC or Web Forms, or ASP.NET Core targeting
     * `net4x`. It does not claim the repository, which another recipe may serve.
     */
    private static function isFrameworkWebApp(string $contents): bool
    {
        return self::frameworkVersion($contents) !== null
            && (preg_match(self::SYSTEM_WEB_APP, $contents) === 1
                || (self::usesWebSdk($contents) && ! self::isLibrary($contents)));
    }

    private const SYSTEM_WEB_APP = '/Microsoft\.WebApplication\.targets|\{349c5851-65df-11da-9384-00065b846f21\}'
        .'|MSBuild\.SDK\.SystemWeb/i';

    private static function isLibrary(string $contents): bool
    {
        return preg_match('/<OutputType>\s*Library\s*<\/OutputType>/i', $contents) === 1;
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
                    $versions[] = $m[1].'.'.$m[2];
                }
            }
        }

        return $versions;
    }

    /**
     * The Node image a build needs beside the SDK, or null: a target running `npm ci` fails MSB3073 without it.
     * Chosen from the package.json beside the project that runs it, else the engine's default Node.
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
                foreach (glob($root.$depth.$pattern) ?: [] as $path) {
                    $files[] = ltrim(substr($path, strlen($root)), '/');
                }
            }
        }

        foreach ($files as $relative) {
            if (self::isSolution($relative)) {
                continue;
            }
            $contents = AccountHomeRead::contents($root.'/'.$relative);
            if (! is_string($contents) || preg_match(self::NODE_EXEC, $contents) !== 1) {
                continue;
            }
            $dir = dirname($root.'/'.$relative);

            return NodeRuntime::imageFor(is_file($dir.'/package.json') ? $dir : '');
        }

        return null;
    }

    /**
     * `dotnet publish`, which gathers one runnable directory where `dotnet build` scatters each project's bin/.
     * No `--no-restore`: a clean checkout fails without the restore.
     */
    public static function buildCommand(string $projectDir = ''): string
    {
        $target = $projectDir === '' ? null : self::entryProject($projectDir);
        $framework = $target === null ? null : self::publishFramework($projectDir, $target);
        $solutionDir = $target === null ? null : self::solutionDir($projectDir, $target);

        return 'echo '.escapeshellarg(self::AUDIT_TARGETS).' > '.self::AUDIT_TARGETS_FILE.' && '
            .'dotnet publish'.($target === null ? '' : ' '.escapeshellarg($target))
            .($framework === null ? '' : ' -f '.$framework)
            .($solutionDir === null ? '' : ' -p:SolutionDir="$PWD/'.($solutionDir === '.' ? '' : $solutionDir.'/').'"')
            .' -c Release -o '.self::PUBLISH_DIR.' --nologo'
            .' -p:CustomAfterMicrosoftCommonTargets='.self::AUDIT_TARGETS_FILE;
    }

    /**
     * Keeps NuGet audit (NU1900-NU1904) a warning under TreatWarningsAsErrors (Flink failed on NU1903).
     * Appended from a targets file: `-p:WarningsNotAsErrors=` would replace the project's own list.
     */
    private const AUDIT_TARGETS = '<Project><PropertyGroup><WarningsNotAsErrors>'
        .'$(WarningsNotAsErrors);NU1900;NU1901;NU1902;NU1903;NU1904'
        .'</WarningsNotAsErrors></PropertyGroup></Project>';

    private const AUDIT_TARGETS_FILE = '/tmp/panelalpha-nuget-audit.targets';

    /**
     * The nearest solution's directory above a project ('.' for the root), or null outside any solution.
     * Publishing one project leaves `$(SolutionDir)` unset, and Prowlarr's props need it to find stylecop.json.
     */
    public static function solutionDir(string $projectDir, string $project): ?string
    {
        $root = rtrim($projectDir, '/');
        $dir = dirname($project);
        while (true) {
            $path = $root.($dir === '.' ? '' : '/'.$dir);
            if ((glob($path.'/*.sln') ?: []) !== [] || (glob($path.'/*.slnx') ?: []) !== []) {
                return $dir;
            }
            if ($dir === '.' || $dir === '/' || $dir === '') {
                return null;
            }
            $dir = dirname($dir);
        }
    }

    /**
     * The newest plain `netX.Y` of a `<TargetFrameworks>` list (here or in a Directory.Build.props above), or null.
     * With the plural element publish refuses to guess (`NETSDK1129`), even for one entry.
     */
    public static function publishFramework(string $projectDir, string $project): ?string
    {
        $root = rtrim($projectDir, '/');
        $contents = AccountHomeRead::contents($root.'/'.$project);
        if (! is_string($contents)) {
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
                && ($best === null || version_compare($m[1].'.'.$m[2], substr($best, 3), '>'))
            ) {
                $best = $framework;
            }
        }

        return $best;
    }

    /** The nearest Directory.Build.props that sets a framework decides. */
    private static function inheritedFrameworkList(string $root, string $dir): ?string
    {
        $props = self::frameworkProps($root, $dir);

        return $props === null ? null : self::frameworkList($props);
    }

    /** The nearest Directory.Build.props at or above $dir that sets a framework, as read. */
    private static function frameworkProps(string $root, string $dir): ?string
    {
        while (true) {
            $props = AccountHomeRead::contents($root.($dir === '.' ? '' : '/'.$dir).'/Directory.Build.props');
            if (is_string($props) && preg_match('/<TargetFrameworks?>/i', $props) === 1) {
                return $props;
            }
            if ($dir === '.' || $dir === '/' || $dir === '') {
                return null;
            }
            $dir = dirname($dir);
        }
    }

    /**
     * A project naming no framework builds for the one its Directory.Build.props sets (BootstrapAdmin's web project).
     *
     * @return list<string>
     */
    private static function frameworksOf(string $root, string $relative, string $contents): array
    {
        if (preg_match('/<TargetFrameworks?>|<TargetFrameworkVersion>/i', $contents) === 1) {
            return self::declaredFrameworks($contents);
        }
        $props = self::frameworkProps($root, dirname($relative));

        return $props === null ? [] : self::declaredFrameworks($props);
    }

    private static function frameworkList(string $contents): ?string
    {
        return preg_match('/<TargetFrameworks>\s*([^<]+?)\s*<\/TargetFrameworks>/i', $contents, $m) === 1
            ? $m[1]
            : null;
    }

    /** A WinForms, WPF or Avalonia window (PotatoNV), which has no port to answer on. */
    private static function isDesktopApp(string $contents): bool
    {
        return preg_match('/<OutputType>\s*WinExe\s*<\/OutputType>/i', $contents) === 1;
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
     * dotnet/maui's sample servers target `$(_MauiDotNetTfm)`: a framework named only through a property the
     * repository's own build sets is a framework repository's sample, for which no SDK can be chosen.
     */
    private static function targetsOnlyAProperty(string $contents): bool
    {
        preg_match_all('/<TargetFrameworks?>\s*([^<]*?)\s*<\/TargetFrameworks?>/i', $contents, $elements);
        $properties = 0;
        foreach ($elements[1] as $list) {
            foreach (explode(';', $list) as $framework) {
                if (trim($framework) === '') {
                    continue;
                }
                if (! str_contains($framework, '$(')) {
                    return false;
                }
                $properties++;
            }
        }

        return $properties > 0;
    }

    /**
     * Runs the assembly with a runtimeconfig beside it, from the publish directory (ASP.NET's content root).
     * With nothing runnable it exits instead of restart-looping behind a 502.
     */
    public static function startCommand(): string
    {
        $dir = self::PUBLISH_DIR;

        return 'cfg=$(ls '.$dir.'/*.runtimeconfig.json 2>/dev/null | head -n 1);'
            .' [ -n "$cfg" ]'
            .' || { echo "PANELALPHA: dotnet publish produced no runnable assembly in '.$dir.'/";'
            .' echo "PANELALPHA: published files: $(ls '.$dir.' 2>/dev/null | head -n 20)";'
            .' exit 1; };'
            .' cd '.$dir.' && exec dotnet "$(basename "${cfg%.runtimeconfig.json}").dll"';
    }
}
