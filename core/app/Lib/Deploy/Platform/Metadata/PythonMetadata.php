<?php

namespace App\Lib\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Python identity from pyproject.toml only — of the five places this ecosystem
 * keeps metadata it is the one declarative document with a schema. Both PEP 621's
 * `[project]` and Poetry's `[tool.poetry]` are read; versions are never resolved.
 */
final class PythonMetadata implements PackageMetadata
{
    use ReadsPackageFiles;

    public const FILE = 'pyproject.toml';

    /**
     * Python web frameworks worth naming, most specific first.
     *
     * @var array<string, string>
     */
    private const FRAMEWORKS = [
        'django' => 'Django',
        'fastapi' => 'FastAPI',
        'flask' => 'Flask',
        'litestar' => 'Litestar',
        'sanic' => 'Sanic',
        'pyramid' => 'Pyramid',
        'tornado' => 'Tornado',
        'aiohttp' => 'aiohttp',
        'bottle' => 'Bottle',
        'starlette' => 'Starlette',
    ];

    public function id(): string
    {
        return 'python';
    }

    public function read(ProjectContext $context): ?AppPackage
    {
        $toml = $context->contents(self::FILE);
        if ($toml === null) {
            return null;
        }

        $project = self::tomlSection($toml, 'project');
        $poetry = self::tomlSection($toml, 'tool.poetry');
        $section = $project ?? $poetry;
        if ($section === null) {
            // A pyproject.toml holding only build-system and tool config says the project
            // is Python but not which project.
            return null;
        }

        $name = self::tomlString($section, 'name');

        return new AppPackage(
            ecosystem: $this->id(),
            file: self::FILE,
            name: $name ?? self::directoryName($context),
            nameSource: $name !== null ? self::FILE.' name' : AppPackage::NAME_FROM_DIRECTORY,
            description: self::tomlString($section, 'description'),
            version: self::tomlString($section, 'version'),
            license: self::license($section),
            homepage: self::tomlString($section, 'homepage'),
            repository: self::tomlString($section, 'repository'),
            authors: self::authors($section),
            keywords: self::tomlList($section, 'keywords'),
            entrypoints: self::scriptNames($toml),
            frameworks: self::matchFrameworks(
                self::FRAMEWORKS,
                $this->constraintsFrom($toml, $section, $project !== null),
                [],
                self::FILE
            ),
            // PEP 621 spells it `requires-python`; Poetry keeps it as an
            // ordinary `python` dependency. Both mean the interpreter.
            platform: self::interpreter($toml, $section)
        );
    }

    /**
     * The interpreter this project asks for, under either spelling.
     *
     * @return array<string, string>
     */
    private static function interpreter(string $toml, string $section): array
    {
        // PEP 621 states it on the project itself; Poetry lists it among the
        // dependencies, in its own table, so the whole document is consulted.
        $requires = self::tomlString($section, 'requires-python');

        if ($requires === null) {
            $dependencies = self::tomlSection($toml, 'tool.poetry.dependencies');
            $requires = $dependencies === null ? null : self::tomlString($dependencies, 'python');
        }

        return $requires === null ? [] : ['python' => $requires];
    }

    /**
     * Declared dependencies, from whichever of the two spellings is in use:
     * PEP 621 writes a list of requirement strings (`django>=5.0`), Poetry a
     * table of `name = constraint`. Names are normalised the way the packaging
     * spec does, so `Django` and `django` compare as one thing.
     *
     * @return array<string, string>
     */
    private function constraintsFrom(string $toml, string $section, bool $pep621): array
    {
        if ($pep621) {
            $constraints = [];
            foreach (self::tomlList($section, 'dependencies') as $requirement) {
                if (preg_match('/^\s*([A-Za-z0-9._-]+)\s*(.*)$/', $requirement, $matches) !== 1) {
                    continue;
                }
                $name = self::normalize($matches[1]);
                $constraints[$name] = self::text(trim($matches[2], " \t()")) ?? '*';
            }

            return $constraints;
        }

        $dependencies = self::tomlSection($toml, 'tool.poetry.dependencies');
        if ($dependencies === null) {
            return [];
        }

        $constraints = [];
        if (preg_match_all('/^[ \t]*([A-Za-z0-9._-]+)[ \t]*=[ \t]*(.*)$/m', $dependencies, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $name = self::normalize($match[1]);
                if ($name === 'python') {
                    continue;
                }
                $constraints[$name] = self::text(trim($match[2], " \t\"'")) ?? '*';
            }
        }

        return $constraints;
    }

    /** PEP 503 name normalisation: `Flask_Login` and `flask-login` are one package. */
    private static function normalize(string $name): string
    {
        return strtolower((string) preg_replace('/[-_.]+/', '-', $name));
    }

    /** `license` is a string, or a table with `text` or `file`. */
    private static function license(string $section): ?string
    {
        $direct = self::tomlString($section, 'license');
        if ($direct !== null) {
            return $direct;
        }
        if (preg_match('/^[ \t]*license[ \t]*=[ \t]*\{(.*?)\}/ms', $section, $matches) !== 1) {
            return null;
        }

        return preg_match('/text[ \t]*=[ \t]*["\'](.*?)["\']/s', $matches[1], $text) === 1
            ? self::text($text[1])
            : null;
    }

    /**
     * `authors` is a list of strings for Poetry and a list of inline tables for
     * PEP 621, whose email fields must not be mistaken for names.
     *
     * @return list<string>
     */
    private static function authors(string $section): array
    {
        if (preg_match('/^[ \t]*authors[ \t]*=[ \t]*\[(.*?)\]/ms', $section, $matches) !== 1) {
            return [];
        }
        if (! str_contains($matches[1], '{')) {
            return self::tomlList($section, 'authors');
        }

        $names = [];
        if (preg_match_all('/name[ \t]*=[ \t]*["\'](.*?)["\']/s', $matches[1], $found) !== false) {
            foreach ($found[1] ?? [] as $name) {
                $name = self::text($name);
                if ($name !== null) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * Console entry points — the closest Python has to a `bin`.
     *
     * @return list<string>
     */
    private static function scriptNames(string $toml): array
    {
        $section = self::tomlSection($toml, 'project.scripts')
            ?? self::tomlSection($toml, 'tool.poetry.scripts');
        if ($section === null) {
            return [];
        }

        $names = [];
        if (preg_match_all('/^[ \t]*([A-Za-z0-9._-]+)[ \t]*=/m', $section, $matches) !== false) {
            foreach ($matches[1] ?? [] as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
