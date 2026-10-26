<?php

namespace App\Lib\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * JavaScript identity, from package.json and package-lock.json. Versions resolve from
 * the lockfile only: pnpm and Yarn keep their own formats, so those projects report the
 * declared constraint in `source`, and `name` as declared (often `my-app`).
 */
final class NpmMetadata implements PackageMetadata
{
    use ReadsPackageFiles;

    public const FILE = 'package.json';

    public const LOCK = 'package-lock.json';

    /**
     * JS frameworks worth naming, most specific first.
     *
     * Order is the design: every Next.js project depends on React, every Nuxt
     * on Vue, so the meta-framework has to sit above the view library it is
     * built on or every application here reports as "React".
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
            license: implode(', ', self::stringList($package['license'] ?? null)) ?: null,
            homepage: self::text($package['homepage'] ?? null),
            repository: self::repository($package['repository'] ?? null),
            authors: self::authors($package),
            keywords: self::stringList($package['keywords'] ?? null),
            private: ($package['private'] ?? false) === true,
            scripts: self::keys($package['scripts'] ?? null),
            entrypoints: self::entrypoints($package),
            workspaces: self::workspaces($package['workspaces'] ?? null),
            frameworks: self::matchFrameworks(
                self::FRAMEWORKS,
                self::constraints($dependencies, $devDependencies),
                $locked,
                self::FILE,
                self::LOCK
            ),
            dependencyCounts: [
                'dependencies' => count($dependencies),
                'devDependencies' => count($devDependencies),
            ],
            platform: self::engines($package)
        );
    }

    /**
     * `engines`, npm's spelling of a platform requirement. Every key is kept,
     * not just `node`: a project that pins `pnpm` fails for a reason its `node`
     * constraint cannot explain.
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
     * Installed versions from package-lock.json, keyed by package.
     *
     * Two layouts: lockfileVersion 1 keys a `dependencies` tree by bare name, 2
     * and 3 key a flat `packages` map by install path. Both are read.
     *
     * @return array<string, string>
     */
    private function lockedVersions(ProjectContext $context): array
    {
        $lock = self::map($context->json(self::LOCK));

        $versions = [];
        foreach (self::map($lock['packages'] ?? null) as $path => $entry) {
            $path = self::text($path);
            $version = is_array($entry) ? self::text($entry['version'] ?? null) : null;
            // The root project is keyed by the empty string, and its version
            // is the application's own — not a dependency's.
            if ($path === null || $version === null || ! str_starts_with($path, 'node_modules/')) {
                continue;
            }
            $name = substr($path, strlen('node_modules/'));
            // `node_modules/vitest/node_modules/vite` is a nested second copy, not the
            // version this project builds against: taking it reported Vite 5 for an
            // application whose package.json asks for Vite 6.
            if ($name === '' || str_contains($name, 'node_modules/')) {
                continue;
            }
            $versions[$name] = $version;
        }

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
