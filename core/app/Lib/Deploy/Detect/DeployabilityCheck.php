<?php

namespace App\Lib\Deploy\Detect;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Compose\AppRoot;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Platform\Probes\SvelteKitAdapter;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\DotnetRuntime;
use App\Lib\Deploy\Platform\Strategies;

/**
 * Refuses a deploy the chosen strategy cannot carry out, before an account is provisioned: a missing root manifest
 * fails here as a message, later as a container that restart-loops.
 */
final class DeployabilityCheck
{
    public const PROJECT_EMPTY = 'project_empty';

    public const DOCKERFILE_MISSING = 'dockerfile_missing';

    public const COMPOSE_FILE_SKIPPED = 'compose_file_skipped';

    public const COMPOSE_FILE_MISSING = 'compose_file_missing';

    public const COMPOSE_BUILD_FILE_MISSING = 'compose_build_file_missing';

    public const DOTNET_PROJECT_LEGACY = 'dotnet_project_legacy';

    public const STATIC_INDEX_MISSING = 'static_index_missing';

    public const ROOT_FILE_MISSING = 'root_file_missing';

    public const SVELTEKIT_ADAPTER_UNSUPPORTED = 'sveltekit_adapter_unsupported';

    public const PHP_EXTENSION_UNAVAILABLE = 'php_extension_unavailable';

    /**
     * Root file a strategy cannot deploy without, as [basename, label].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const REQUIRED_ROOT_FILE = [
        Strategies::LARAVEL => ['composer.json', 'PHP'],
        Strategies::PHP => ['composer.json', 'PHP'],
        Strategies::RAILS => ['Gemfile', 'Ruby'],
        Strategies::RUBY => ['Gemfile', 'Ruby'],
    ];

    /** @var list<string> */
    private const STATIC_INDEXES = ['index.html', 'index.htm'];

    private readonly string $strategy;

    /**
     * @param  array<string, mixed>  $decision
     */
    public function __construct(private readonly array $decision, private readonly string $projectDir)
    {
        $this->strategy = (string) ($decision['strategy'] ?? Strategies::FALLBACK);
    }

    /**
     * @param  array<string, mixed>  $decision
     *
     * @throws DeployRefusal
     */
    public static function assert(array $decision, string $projectDir): void
    {
        (new self($decision, rtrim($projectDir, '/')))->run();
    }

    /**
     * @throws DeployRefusal
     */
    public function run(): void
    {
        $this->assertNotEmpty();

        match (true) {
            $this->strategy === Strategies::DOCKERFILE => $this->assertDockerfile(),
            $this->strategy === Strategies::COMPOSE => $this->assertCompose(),
            $this->strategy === Strategies::STATIC => $this->assertStaticEntry(),
            $this->strategy === Strategies::DOTNET, $this->strategy === Strategies::FALLBACK => $this->assertModernDotnet(),
            Strategies::isJsFramework($this->strategy) => $this->assertRootFile('package.json', 'Framework'),
            isset(self::REQUIRED_ROOT_FILE[$this->strategy]) && ! $this->namedByItsOwnManifest() => $this->assertRootFile(...self::REQUIRED_ROOT_FILE[$this->strategy]),
            default => null,
        };

        if ($this->strategy === Strategies::SVELTEKIT) {
            $this->assertSvelteKitAdapter();
        }
        $this->assertPhpExtensionsAvailable();
    }

    /**
     * adapter-auto and the platform adapters write nothing nginx or Node can serve here: an empty build/ answered 403,
     * a missing build/index.js restart-looped.
     *
     * @throws DeployRefusal
     */
    private function assertSvelteKitAdapter(): void
    {
        $adapter = SvelteKitAdapter::configured(ProjectContext::at(AppRoot::path($this->projectDir, $this->decision)));
        $refusal = $adapter === null ? null : SvelteKitAdapter::refusal($adapter);
        if ($refusal !== null) {
            throw new DeployRefusal(self::SVELTEKIT_ADAPTER_UNSUPPORTED, $refusal, ['adapter' => $adapter]);
        }
    }

    /**
     * An `ext-*` the resolved image will not have fails the dependency stage, so it is refused before the clone is built.
     * composer.json is read under app_root when the manifest declares one (phpBB uses phpBB/).
     *
     * @throws DeployRefusal
     */
    private function assertPhpExtensionsAvailable(): void
    {
        if (! isset(self::REQUIRED_ROOT_FILE[$this->strategy])
            || self::REQUIRED_ROOT_FILE[$this->strategy][1] !== 'PHP') {
            return;
        }

        $root = AppRoot::path($this->projectDir, $this->decision);
        $json = AccountHomeRead::contents($root.'/composer.json');
        if ($json === false) {
            return;
        }
        $lock = AccountHomeRead::contents($root.'/composer.lock');

        $issue = PhpExtensionAvailability::issue($json, $lock === false ? null : $lock);
        if ($issue !== null) {
            throw new DeployRefusal(self::PHP_EXTENSION_UNAVAILABLE, $issue);
        }
    }

    /**
     * An application manifest matched, not the generic platform its strategy is named after: it has named the application,
     * which the root-file rules need not second-guess (osTicket vendors its dependencies with no composer.json).
     */
    private function namedByItsOwnManifest(): bool
    {
        $platform = $this->decision['platform'] ?? null;

        return is_string($platform) && $platform !== '' && $platform !== $this->strategy;
    }

    private function assertNotEmpty(): void
    {
        if (! is_dir($this->projectDir) || ProjectContext::listRootFiles($this->projectDir) === []) {
            throw new DeployRefusal(self::PROJECT_EMPTY, 'Project directory is empty; nothing to deploy.');
        }
    }

    private function assertDockerfile(): void
    {
        $name = $this->decision['dockerfile'] ?? 'Dockerfile';
        if (! is_file($this->path((string) $name))) {
            throw new DeployRefusal(self::DOCKERFILE_MISSING, 'Dockerfile strategy selected but Dockerfile is missing.', ['file' => (string) $name]);
        }
    }

    /** A compose file that is there but was passed over is a different fault from one that is absent, and says which. */
    private function assertCompose(): void
    {
        $path = $this->decision['compose_path'] ?? null;
        if (! is_string($path) || ! is_file($path)) {
            foreach (ComposeFileInspector::COMPOSE_FILE_CANDIDATES as $name) {
                if (is_file($this->path($name))) {
                    throw new DeployRefusal(
                        self::COMPOSE_FILE_SKIPPED,
                        "Compose strategy selected but {$name} was not accepted as a runnable compose file"
                            .' (a workstation, sidecars-only or engine-generated file is skipped).',
                        ['file' => $name],
                    );
                }
            }
            throw new DeployRefusal(self::COMPOSE_FILE_MISSING, 'Compose strategy selected but compose file is missing.');
        }

        $missing = ComposeFileInspector::missingComposeDockerfileRefs($path, $this->projectDir);
        if ($missing !== [] && ! is_file($this->path('Dockerfile'))) {
            throw new DeployRefusal(
                self::COMPOSE_BUILD_FILE_MISSING,
                'Compose file builds from '.$missing[0].' which does not exist.',
                ['file' => $missing[0]],
            );
        }
    }

    /**
     * Otherwise the SDK image is picked from nothing and the build fails without saying why, or a classic ASP.NET app
     * no recipe claims (Orchard 1.x) gets the placeholder page.
     */
    private function assertModernDotnet(): void
    {
        $legacy = DotnetRuntime::legacyFrameworkEntry(AppRoot::path($this->projectDir, $this->decision));
        if ($legacy !== null) {
            throw new DeployRefusal(
                self::DOTNET_PROJECT_LEGACY,
                self::legacyDotnetMessage($legacy),
                ['file' => $legacy['file']],
            );
        }
    }

    /**
     * @param  array{file: string, framework: string, sdk_style: bool}  $legacy
     */
    private static function legacyDotnetMessage(array $legacy): string
    {
        return $legacy['file'].' targets .NET Framework '.$legacy['framework']
            .($legacy['sdk_style'] ? '.' : ' (a <TargetFrameworkVersion> project).')
            .' The .NET SDK on Linux builds only SDK-style projects targeting .NET 5 or newer'
            .' (<TargetFramework>net8.0</TargetFramework>).';
    }

    private function assertStaticEntry(): void
    {
        if ($this->hasDeclaredStaticIndex() || $this->hasConventionalIndex()) {
            return;
        }

        throw new DeployRefusal(self::STATIC_INDEX_MISSING, 'Static strategy selected but no HTML file is present.');
    }

    private function hasDeclaredStaticIndex(): bool
    {
        $entry = $this->decision['static_index'] ?? null;

        return is_string($entry) && $entry !== '' && is_file($this->path($entry));
    }

    private function hasConventionalIndex(): bool
    {
        foreach (self::STATIC_INDEXES as $name) {
            if (is_file($this->path($name))) {
                return true;
            }
        }

        return false;
    }

    private function assertRootFile(string $name, string $label): void
    {
        if (! is_file($this->path($name))) {
            throw new DeployRefusal(self::ROOT_FILE_MISSING, "{$label} strategy selected but {$name} is missing.", ['file' => $name, 'strategy' => $this->strategy]);
        }
    }

    /** Resolved inside app_root when the manifest declares one (phpBB). */
    private function path(string $relative): string
    {
        $appRoot = $this->decision['app_root'] ?? '';
        $base = is_string($appRoot) && $appRoot !== ''
            ? $this->projectDir.'/'.trim($appRoot, '/')
            : $this->projectDir;

        return $base.'/'.$relative;
    }
}
