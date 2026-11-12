<?php

namespace App\Lib\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Python identity from pyproject.toml, the one declarative metadata document with a schema: PEP 621's `[project]`
 * or Poetry's `[tool.poetry]`, versions never resolved.
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

    /** A pyproject.toml holding only build-system and tool config says the project is Python, not which project. */
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
            frameworks: $this->frameworks($toml, $section, $project !== null),
            platform: self::interpreter($toml, $section)
        );
    }

    /** @return list<Framework> */
    private function frameworks(string $toml, string $section, bool $pep621): array
    {
        return self::matchFrameworks(self::FRAMEWORKS, $this->constraintsFrom($toml, $section, $pep621), [], self::FILE);
    }

    /**
     * The interpreter: PEP 621's `requires-python` on the project, or Poetry's `python` dependency in its own table.
     *
     * @return array<string, string>
     */
    private static function interpreter(string $toml, string $section): array
    {
        $requires = self::tomlString($section, 'requires-python');

        if ($requires === null) {
            $dependencies = self::tomlSection($toml, 'tool.poetry.dependencies');
            $requires = $dependencies === null ? null : self::tomlString($dependencies, 'python');
        }

        return $requires === null ? [] : ['python' => $requires];
    }

    /**
     * Declared dependencies, from PEP 621's requirement strings or Poetry's table, names normalised so `Django`
     * and `django` compare as one.
     *
     * @return array<string, string>
     */
    private function constraintsFrom(string $toml, string $section, bool $pep621): array
    {
        if ($pep621) {
            return self::requirementConstraints($section);
        }

        return self::poetryConstraints($toml);
    }

    /**
     * PEP 621: a list of requirement strings, `django>=5.0`.
     *
     * @return array<string, string>
     */
    private static function requirementConstraints(string $section): array
    {
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

    /**
     * Poetry: a `name = constraint` table, the interpreter left out.
     *
     * @return array<string, string>
     */
    private static function poetryConstraints(string $toml): array
    {
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
                $constraints[$name] = self::poetryConstraint($match[2]);
            }
        }

        return $constraints;
    }

    /** A constraint string, or an inline table's `version`; a table with none (git, path) is `*`. */
    private static function poetryConstraint(string $value): string
    {
        $value = trim($value);
        if (str_starts_with($value, '{')) {
            $value = preg_match('/version[ \t]*=[ \t]*["\'](.*?)["\']/', $value, $version) === 1
                ? $version[1]
                : '*';
        }

        return self::text(trim($value, " \t\"'")) ?? '*';
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
