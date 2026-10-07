<?php

namespace App\Lib\Deploy\Detect;

use App\Lib\Deploy\Compose\AppRoot;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Probes\SvelteKitAdapter;
use App\Lib\Deploy\Platform\Runtime\DotnetRuntime;
use App\Lib\Deploy\Platform\Strategies;
use InvalidArgumentException;

/**
 * Refuses a deploy the chosen strategy cannot carry out, before an account is
 * provisioned: a missing root manifest fails here as a message, later as a
 * container that restart-loops.
 */
final class DeployabilityCheck
{
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
     * @param array<string, mixed> $decision
     */
    public function __construct(private readonly array $decision, private readonly string $projectDir)
    {
        $this->strategy = (string) ($decision['strategy'] ?? Strategies::FALLBACK);
    }

    /**
     * @param array<string, mixed> $decision
     * @throws InvalidArgumentException
     */
    public static function assert(array $decision, string $projectDir): void
    {
        (new self($decision, rtrim($projectDir, '/')))->run();
    }

    /**
     * @throws InvalidArgumentException
     */
    public function run(): void
    {
        $this->assertNotEmpty();

        match (true) {
            $this->strategy === Strategies::DOCKERFILE => $this->assertDockerfile(),
            $this->strategy === Strategies::COMPOSE => $this->assertCompose(),
            $this->strategy === Strategies::STATIC => $this->assertStaticEntry(),
            $this->strategy === Strategies::DOTNET => $this->assertModernDotnet(),
            Strategies::isJsFramework($this->strategy) => $this->assertRootFile('package.json', 'Framework'),
            isset(self::REQUIRED_ROOT_FILE[$this->strategy]) && !$this->namedByItsOwnManifest()
                => $this->assertRootFile(...self::REQUIRED_ROOT_FILE[$this->strategy]),
            default => null,
        };

        if ($this->strategy === Strategies::SVELTEKIT) {
            $this->assertSvelteKitAdapter();
        }
        $this->assertPhpExtensionsAvailable();
    }

    /**
     * adapter-auto and the platform adapters write nothing nginx or Node can
     * serve here: an empty build/ answered 403, a missing build/index.js
     * restart-looped.
     *
     * @throws InvalidArgumentException
     */
    private function assertSvelteKitAdapter(): void
    {
        $adapter = SvelteKitAdapter::configured(ProjectContext::at(AppRoot::path($this->projectDir, $this->decision)));
        $refusal = $adapter === null ? null : SvelteKitAdapter::refusal($adapter);
        if ($refusal !== null) {
            throw new InvalidArgumentException($refusal);
        }
    }

    /**
     * An `ext-*` the resolved image will not have fails the dependency stage,
     * so it is refused before the clone is built.
     *
     * @throws InvalidArgumentException
     */
    private function assertPhpExtensionsAvailable(): void
    {
        if (!isset(self::REQUIRED_ROOT_FILE[$this->strategy])
            || self::REQUIRED_ROOT_FILE[$this->strategy][1] !== 'PHP') {
            return;
        }

        // Under app_root when the manifest declares one -- phpBB uses phpBB/.
        $root = AppRoot::path($this->projectDir, $this->decision);
        $json = @file_get_contents($root . '/composer.json');
        if ($json === false) {
            return;
        }
        $lock = @file_get_contents($root . '/composer.lock');

        $issue = PhpExtensionAvailability::issue($json, $lock === false ? null : $lock);
        if ($issue !== null) {
            throw new InvalidArgumentException($issue);
        }
    }

    /**
     * An application manifest matched, not the generic platform its strategy
     * is named after. The root-file rules catch a guess; a manifest that
     * matched its own detect rules has named the application, and osTicket
     * vendors its dependencies with no composer.json at all.
     */
    private function namedByItsOwnManifest(): bool
    {
        $platform = $this->decision['platform'] ?? null;

        return is_string($platform) && $platform !== '' && $platform !== $this->strategy;
    }

    private function assertNotEmpty(): void
    {
        if (!is_dir($this->projectDir) || ProjectContext::listRootFiles($this->projectDir) === []) {
            throw new InvalidArgumentException('Project directory is empty; nothing to deploy.');
        }
    }

    private function assertDockerfile(): void
    {
        $name = $this->decision['dockerfile'] ?? 'Dockerfile';
        if (!is_file($this->path((string) $name))) {
            throw new InvalidArgumentException('Dockerfile strategy selected but Dockerfile is missing.');
        }
    }

    private function assertCompose(): void
    {
        $path = $this->decision['compose_path'] ?? null;
        if (!is_string($path) || !is_file($path)) {
            // A file that is there but was passed over is a different fault
            // from one that is absent, and the message has to say which.
            foreach (ComposeFileInspector::COMPOSE_FILE_CANDIDATES as $name) {
                if (is_file($this->path($name))) {
                    throw new InvalidArgumentException(
                        "Compose strategy selected but {$name} was not accepted as a runnable compose file"
                        . ' (a workstation, sidecars-only or engine-generated file is skipped).'
                    );
                }
            }
            throw new InvalidArgumentException('Compose strategy selected but compose file is missing.');
        }

        $missing = ComposeFileInspector::missingComposeDockerfileRefs($path, $this->projectDir);
        if ($missing !== [] && !is_file($this->path('Dockerfile'))) {
            throw new InvalidArgumentException(
                'Compose file builds from ' . $missing[0] . ' which does not exist.'
            );
        }
    }

    /** Otherwise the SDK image is picked from nothing and the build fails without saying why. */
    private function assertModernDotnet(): void
    {
        $legacy = DotnetRuntime::legacyFrameworkEntry(AppRoot::path($this->projectDir, $this->decision));
        if ($legacy !== null) {
            throw new InvalidArgumentException(
                $legacy . ' (a <TargetFrameworkVersion> project). The .NET SDK on Linux builds only'
                . ' SDK-style projects targeting .NET 5 or newer (<TargetFramework>net8.0</TargetFramework>).'
            );
        }
    }

    private function assertStaticEntry(): void
    {
        if ($this->hasDeclaredStaticIndex() || $this->hasConventionalIndex()) {
            return;
        }

        throw new InvalidArgumentException('Static strategy selected but no HTML file is present.');
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
        if (!is_file($this->path($name))) {
            throw new InvalidArgumentException("{$label} strategy selected but {$name} is missing.");
        }
    }

    /** Resolved inside app_root when the manifest declares one (phpBB). */
    private function path(string $relative): string
    {
        $appRoot = $this->decision['app_root'] ?? '';
        $base = is_string($appRoot) && $appRoot !== ''
            ? $this->projectDir . '/' . trim($appRoot, '/')
            : $this->projectDir;

        return $base . '/' . $relative;
    }
}
