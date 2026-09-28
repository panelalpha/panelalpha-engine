<?php

namespace App\Lib\Deploy\Platform;

/**
 * The project under inspection: its directory, its root listing, and the
 * handful of reads that detection keeps repeating.
 *
 * package.json is parsed once per run rather than once per asker — a dozen
 * manifests, probes and runtimes putting the same question to the same file
 * was the main cost of splitting detection up in the first place.
 *
 * No Laravel dependencies — unit-testable with a temp directory.
 */
class ProjectContext
{
    /** @var array<string, mixed>|null */
    private ?array $package = null;

    private bool $packageLoaded = false;

    /** @var array<string, array<string, mixed>|null> decoded JSON by relative path */
    private array $json = [];

    /**
     * @param array<string, true> $files lowercase basename => true
     * @param ?string $sourceUrl the repository this checkout came from, when
     *        it came from one. Not a fact about the files, which is why
     *        nothing on this class reads it — but it is a fact about the
     *        project, and {@see SourceRecipes} answers "what is this?" from
     *        the URL alone, faster and more exactly than any file rule can.
     *        Null for an uploaded archive.
     */
    public function __construct(
        public readonly string $projectDir,
        public readonly array $files,
        public readonly ?string $sourceUrl = null
    ) {
    }

    /**
     * @param array<string, true> $files lowercase basename => true
     */
    public static function make(string $projectDir, array $files, ?string $sourceUrl = null): self
    {
        return new self(rtrim($projectDir, '/'), $files, $sourceUrl);
    }

    /** The project at $projectDir, with its root listed for you. */
    public static function at(string $projectDir, ?string $sourceUrl = null): self
    {
        $projectDir = rtrim($projectDir, '/');

        return new self($projectDir, self::listRootFiles($projectDir), $sourceUrl);
    }

    /**
     * Everything in the project root, lowercased so a caller can ask for
     * `gemfile` without knowing how the repository spelled it.
     *
     * @return array<string, true> lowercase basename => true
     */
    public static function listRootFiles(string $projectDir): array
    {
        $projectDir = rtrim($projectDir, '/');
        if (!is_dir($projectDir)) {
            return [];
        }

        $files = [];
        foreach (self::entries($projectDir) as $entry) {
            if ($entry !== '.' && $entry !== '..' && file_exists($projectDir . '/' . $entry)) {
                $files[strtolower($entry)] = true;
            }
        }

        return $files;
    }

    /**
     * scandir() that lists an unreadable directory as empty. The account owns
     * its checkout and may chmod 700 anything in it (#191); a walk skips it.
     *
     * @return list<string>
     */
    public static function entries(string $dir): array
    {
        return @scandir($dir) ?: [];
    }

    /** Is $name in the root listing? Pass a lowercase basename. */
    public function hasFile(string $name): bool
    {
        return isset($this->files[$name]);
    }

    /** Does $relative exist on disk under the project root? */
    public function isFile(string $relative): bool
    {
        return is_file($this->path($relative));
    }

    public function path(string $relative): string
    {
        return $this->projectDir . '/' . ltrim($relative, '/');
    }

    /** Contents of $relative, or null when it is missing or unreadable. */
    public function contents(string $relative): ?string
    {
        $path = $this->path($relative);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);

        return is_string($raw) ? $raw : null;
    }

    /**
     * Parsed root package.json, cached for the run.
     *
     * @return array<string, mixed>|null
     */
    public function package(): ?array
    {
        if (!$this->packageLoaded) {
            $this->package = self::readPackageJson($this->projectDir);
            $this->packageLoaded = true;
        }

        return $this->package;
    }

    /**
     * Parsed root composer.json, cached for the run.
     *
     * The same bargain package.json already had. composer.lock in particular
     * is a file several megabytes long that three separate callers decode
     * during one detection pass, and it does not change between them.
     *
     * @return array<string, mixed>|null
     */
    public function composer(): ?array
    {
        return $this->json('composer.json');
    }

    /**
     * Parsed root composer.lock, cached for the run.
     *
     * @return array<string, mixed>|null
     */
    public function composerLock(): ?array
    {
        return $this->json('composer.lock');
    }

    /**
     * Any JSON file under the project root, decoded once per run.
     *
     * Null covers missing, unreadable and malformed alike: a caller reading a
     * project's own package-lock.json has nothing useful to do with the
     * difference, and every one of them would otherwise write the same three
     * guards.
     *
     * @return array<string, mixed>|null
     */
    public function json(string $relative): ?array
    {
        if (array_key_exists($relative, $this->json)) {
            return $this->json[$relative];
        }

        $raw = $this->contents($relative);
        $decoded = $raw === null || $raw === '' ? null : json_decode($raw, true);

        return $this->json[$relative] = is_array($decoded) ? $decoded : null;
    }

    /**
     * `scripts` from the root package.json, empty when absent or malformed.
     *
     * @return array<string, mixed>
     */
    public function scripts(): array
    {
        $package = $this->package();
        $scripts = $package['scripts'] ?? null;

        return is_array($scripts) ? $scripts : [];
    }

    /** One `scripts` entry as a trimmed string, or '' when it is not one. */
    public function script(string $name): string
    {
        $raw = $this->scripts()[$name] ?? null;

        return is_string($raw) ? trim($raw) : '';
    }

    /** Is $name a dependency or devDependency of the root package.json? */
    public function hasDep(string $name): bool
    {
        $package = $this->package();

        return $package !== null && self::packageHasDep($package, $name);
    }

    /**
     * Is there a root file whose name starts with "$stem." — next.config.js,
     * next.config.mjs, next.config.ts, all of them?
     */
    public function hasConfigStem(string $stem): bool
    {
        $prefix = strtolower($stem) . '.';
        foreach (array_keys($this->files) as $name) {
            if (str_starts_with((string) $name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Contents of the first "$stem.*" file in $dir (the project root by
     * default), '' when it exists but cannot be read, null when there is none.
     */
    public function configContents(string $stem, ?string $dir = null): ?string
    {
        return self::firstConfigContents($dir ?? $this->projectDir, $stem);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function readPackageJson(string $projectDir): ?array
    {
        $path = rtrim($projectDir, '/') . '/package.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $json = json_decode($raw, true);

        return is_array($json) ? $json : null;
    }

    /**
     * @param array<string, mixed> $package
     */
    public static function packageHasDep(array $package, string $name): bool
    {
        foreach (['dependencies', 'devDependencies'] as $key) {
            if (isset($package[$key]) && is_array($package[$key]) && array_key_exists($name, $package[$key])) {
                return true;
            }
        }

        return false;
    }

    public static function firstConfigContents(string $projectDir, string $stem): ?string
    {
        $root = rtrim($projectDir, '/');
        $prefix = strtolower($stem) . '.';
        foreach (self::entries($root) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!str_starts_with(strtolower($entry), $prefix)) {
                continue;
            }
            $path = $root . '/' . $entry;
            if (!is_file($path)) {
                continue;
            }
            $contents = @file_get_contents($path);

            return is_string($contents) ? $contents : '';
        }

        return null;
    }
}
