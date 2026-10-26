<?php

namespace App\Lib\Deploy\Platform\Metadata;

/**
 * What one ecosystem's package file says this application *is*: the union of what
 * the ecosystems can say, so most fields are nullable. `platform` is the runtime
 * asked for (`php: ^8.2`, the `ext-*` list) as declared, never as resolved.
 */
final class AppPackage
{
    /** The name was taken from the directory, not from any file. */
    public const NAME_FROM_DIRECTORY = 'directory';

    /**
     * @param  list<string>  $authors
     * @param  list<string>  $keywords
     * @param  list<string>  $scripts  names only — a script body can hold a token
     * @param  list<string>  $entrypoints
     * @param  list<string>  $workspaces
     * @param  list<Framework>  $frameworks
     * @param  array<string, int>  $dependencyCounts
     * @param  array<string, string>  $platform  requirement id => declared constraint
     */
    public function __construct(
        public readonly string $ecosystem,
        public readonly string $file,
        public readonly ?string $name,
        public readonly string $nameSource,
        public readonly ?string $description = null,
        public readonly ?string $version = null,
        public readonly ?string $license = null,
        public readonly ?string $homepage = null,
        public readonly ?string $repository = null,
        public readonly array $authors = [],
        public readonly array $keywords = [],
        public readonly bool $private = false,
        public readonly array $scripts = [],
        public readonly array $entrypoints = [],
        public readonly array $workspaces = [],
        public readonly array $frameworks = [],
        public readonly array $dependencyCounts = [],
        public readonly array $platform = []
    ) {}

    /** The framework this project is built on, when it declares one. */
    public function framework(): ?Framework
    {
        return $this->frameworks[0] ?? null;
    }

    /** True when a file named this application rather than the folder. */
    public function isNamed(): bool
    {
        return $this->name !== null && $this->nameSource !== self::NAME_FROM_DIRECTORY;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ecosystem' => $this->ecosystem,
            'file' => $this->file,
            'name' => $this->name,
            'name_source' => $this->nameSource,
            'description' => $this->description,
            'version' => $this->version,
            'license' => $this->license,
            'homepage' => $this->homepage,
            'repository' => $this->repository,
            'authors' => $this->authors,
            'keywords' => $this->keywords,
            'private' => $this->private,
            'scripts' => $this->scripts,
            'entrypoints' => $this->entrypoints,
            'workspaces' => $this->workspaces,
            'frameworks' => array_map(static fn (Framework $f): array => $f->toArray(), $this->frameworks),
            'dependency_counts' => $this->dependencyCounts,
            'platform' => $this->platform,
        ];
    }
}
