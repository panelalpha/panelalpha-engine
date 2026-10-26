<?php

namespace App\Lib\Deploy\Inspect\Report;

/**
 * Root files worth naming back to the caller: the ones that decide the stack.
 *
 * Checked case-sensitively, because that is how they are written.
 */
final class MarkerFiles
{
    /** @var list<string> */
    private const NAMES = [
        'package.json',
        'package-lock.json',
        'pnpm-lock.yaml',
        'yarn.lock',
        'bun.lockb',
        'bun.lock',
        'composer.json',
        'composer.lock',
        'artisan',
        'requirements.txt',
        'pyproject.toml',
        'Pipfile',
        'uv.lock',
        'manage.py',
        'Gemfile',
        'Gemfile.lock',
        'go.mod',
        'Cargo.toml',
        'pom.xml',
        'build.gradle',
        'build.gradle.kts',
        'Dockerfile',
        'compose.yaml',
        'compose.yml',
        'docker-compose.yml',
        'docker-compose.yaml',
        'Procfile',
        'railpack.json',
        'nixpacks.toml',
        '.nvmrc',
        '.node-version',
        '.python-version',
        '.ruby-version',
        '.env.example',
        'index.html',
    ];

    /**
     * @return list<string>
     */
    public static function presentIn(string $projectDir): array
    {
        $root = rtrim($projectDir, '/');

        return array_values(array_filter(
            self::NAMES,
            static fn (string $name): bool => is_file($root.'/'.$name)
        ));
    }
}
