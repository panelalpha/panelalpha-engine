<?php

namespace App\Lib\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * JavaScript identity, from package.json and package-lock.json; pnpm and Yarn lockfiles are not read, so those
 * projects report the declared constraint.
 */
final class NpmMetadata implements PackageMetadata
{
    use ReadsPackageFiles;

    public const FILE = 'package.json';

    public const LOCK = 'package-lock.json';

    /**
     * JS frameworks worth naming, most specific first: a meta-framework sits above the view library it is built on.
     *
     * @var array<string, string>
     */
    private const FRAMEWORKS = [
        'next' => 'Next.js',
        'nuxt' => 'Nuxt',
        'nuxt3' => 'Nuxt',
        '@sveltejs/kit' => 'SvelteKit',
        '@remix-run/react' => 'Remix',
        '@tanstack/react-start' => 'TanStack Start',
        '@angular/core' => 'Angular',
        '@nestjs/core' => 'NestJS',
        '@adonisjs/core' => 'AdonisJS',
        '@strapi/strapi' => 'Strapi',
        'gatsby' => 'Gatsby',
        'astro' => 'Astro',
        'react-scripts' => 'Create React App',
        'express' => 'Express',
        'fastify' => 'Fastify',
        'koa' => 'Koa',
        'hono' => 'Hono',
        'svelte' => 'Svelte',
        'vue' => 'Vue',
        'react' => 'React',
        'vite' => 'Vite',
    ];

    public function id(): string
    {
        return 'node';
    }

    public function read(ProjectContext $context): ?AppPackage
    {
        if (! $context->isFile(self::FILE)) {
            return null;
        }
        $package = self::map($context->package());

        $dependencies = self::map($package['dependencies'] ?? null);
        $devDependencies = self::map($package['devDependencies'] ?? null);
        $name = self::text($package['name'] ?? null);
        $locked = $this->lockedVersions($context);

        return new AppPackage(
            ecosystem: $this->id(),
            file: self::FILE,
            name: $name ?? self::directoryName($context),
            nameSource: $name !== null ? self::FILE.' name' : AppPackage::NAME_FROM_DIRECTORY,
            description: self::text($package['description'] ?? null),
            version: self::text($package['version'] ?? null),
            license: implode(', ', self::stringList($package['license'] ?? null, 'type')) ?: null,
            homepage: self::text($package['homepage'] ?? null),
            repository: self::repository($package['repository'] ?? null),
            authors: self::authors($package),
            keywords: self::stringList($package['keywords'] ?? null),
            private: ($package['private'] ?? false) === true,
            scripts: self::keys($package['scripts'] ?? null),
            entrypoints: self::entrypoints($package),
            workspaces: self::workspaces($package['workspaces'] ?? null),
            frameworks: self::frameworks($dependencies, $devDependencies, $locked),
            dependencyCounts: ['dependencies' => count($dependencies), 'devDependencies' => count($devDependencies)],
            platform: self::engines($package)
        );
    }

    /**
     * @param  array<string, mixed>  $dependencies
     * @param  array<string, mixed>  $devDependencies
     * @param  array<string, string>  $locked
     * @return list<Framework>
     */
    private static function frameworks(array $dependencies, array $devDependencies, array $locked): array
    {
        return self::matchFrameworks(
            self::FRAMEWORKS,
            self::constraints($dependencies, $devDependencies),
            $locked,
            self::FILE,
            self::LOCK
        );
    }

    /**
     * `engines`, npm's platform requirement, every key kept: a pinned `pnpm` fails for a reason `node` cannot explain.
     *
     * @param  array<string, mixed>  $package
     * @return array<string, string>
     */
    private static function engines(array $package): array
    {
        $engines = [];
        foreach (self::map($package['engines'] ?? null) as $name => $constraint) {
            $range = self::text($constraint);
            if (! is_string($name) || $range === null) {
                continue;
            }
            $engines[strtolower($name)] = $range;
        }
        ksort($engines);

        return $engines;
    }

    /**
     * Installed versions from package-lock.json: v2 and v3's flat `packages` map, then v1's `dependencies` tree.
     *
     * @return array<string, string>
     */
    private function lockedVersions(ProjectContext $context): array
    {
        $lock = self::map($context->json(self::LOCK));

        return self::treeVersions($lock, self::installPathVersions($lock));
    }

    /**
     * Top-level `node_modules/<name>` entries only: the root project is keyed by '', and a nested second copy
     * (`node_modules/vitest/node_modules/vite`) is not what the project builds against.
     *
     * @param  array<mixed>  $lock
     * @return array<string, string>
     */
    private static function installPathVersions(array $lock): array
    {
        $versions = [];
        foreach (self::map($lock['packages'] ?? null) as $path => $entry) {
            $path = self::text($path);
            $version = is_array($entry) ? self::text($entry['version'] ?? null) : null;
            if ($path === null || $version === null || ! str_starts_with($path, 'node_modules/')) {
                continue;
            }
            $name = substr($path, strlen('node_modules/'));
            if ($name === '' || str_contains($name, 'node_modules/')) {
                continue;
            }
            $versions[$name] = $version;
        }

        return $versions;
    }

    /**
     * @param  array<mixed>  $lock
     * @param  array<string, string>  $versions
     * @return array<string, string>
     */
    private static function treeVersions(array $lock, array $versions): array
    {
        foreach (self::map($lock['dependencies'] ?? null) as $name => $entry) {
            $name = self::text($name);
            $version = is_array($entry) ? self::text($entry['version'] ?? null) : null;
            if ($name !== null && $version !== null && ! isset($versions[$name])) {
                $versions[$name] = $version;
            }
        }

        return $versions;
    }

    /**
     * `author` is a string or an object; `contributors` is a list of either.
     *
     * @param  array<string, mixed>  $package
     * @return list<string>
     */
    private static function authors(array $package): array
    {
        $authors = self::stringList($package['author'] ?? null);
        foreach (self::stringList($package['contributors'] ?? null) as $contributor) {
            if (! in_array($contributor, $authors, true)) {
                $authors[] = $contributor;
            }
        }

        return $authors;
    }

    /** `repository` is a URL, an object with one, or a "github:owner/repo" shorthand. */
    private static function repository(mixed $repository): ?string
    {
        if (is_array($repository)) {
            return self::text($repository['url'] ?? null);
        }

        return self::text($repository);
    }

    /**
     * @param  array<string, mixed>  $package
     * @return list<string>
     */
    private static function entrypoints(array $package): array
    {
        $bin = $package['bin'] ?? null;
        if (is_array($bin)) {
            return self::keys($bin);
        }

        return array_values(array_filter([
            self::text($bin),
            self::text($package['main'] ?? null),
        ], static fn (?string $v): bool => $v !== null));
    }

    /**
     * Workspace globs, from either shape npm accepts.
     *
     * @return list<string>
     */
    private static function workspaces(mixed $workspaces): array
    {
        if (is_array($workspaces) && isset($workspaces['packages'])) {
            return self::stringList($workspaces['packages']);
        }

        return self::stringList($workspaces);
    }
}
