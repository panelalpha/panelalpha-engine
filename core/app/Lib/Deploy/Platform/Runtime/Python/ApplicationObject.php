<?php

namespace App\Lib\Deploy\Platform\Runtime\Python;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * A module-level WSGI or ASGI application object found anywhere in the first levels of a project, such as FastAPI's
 * `backend/app/main.py`: the layout a declared server is pointed at from the project root, which a root-only
 * search never sees. Several equally likely candidates are a guess, and none is returned.
 */
final class ApplicationObject
{
    public const ASGI = 'asgi';

    public const WSGI = 'wsgi';

    /** Directory levels searched below the root, so `src/<package>/api/app.py` is the deepest file looked at. */
    private const MAX_DEPTH = 3;

    /** Bytes of a module read; an application module binds its object well before this. */
    private const MAX_BYTES = 262144;

    /**
     * File names in the order a project's application module is likeliest to have; any other name ranks after them.
     *
     * @var list<string>
     */
    private const NAMES = ['app.py', 'main.py', 'wsgi.py', 'asgi.py', 'server.py'];

    /**
     * Directories holding tests, docs, demos, tooling or installed packages rather than the application.
     *
     * @var list<string>
     */
    private const SKIPPED_DIRS = [
        '__pycache__', 'node_modules', 'site-packages', 'dist-packages', 'venv', 'env', 'build', 'dist',
        'test', 'tests', 'testing', 'doc', 'docs', 'example', 'examples', 'sample', 'samples', 'demo',
        'scripts', 'tools', 'archive', 'legacy', 'migrations', 'vendor', 'third_party', 'benchmarks',
    ];

    /** `app`/`application` bound at module level to a framework's constructor or an application factory's result. */
    private const BINDING = '/^(app|application)[ \t]*(?::[^=\n]+)?=[ \t]*'
        .'(?:(?:[A-Za-z_][A-Za-z0-9_]*\.)*(?:FastAPI|Flask|Starlette|Quart|Litestar|Sanic|Bottle|[A-Za-z0-9_]*_app'
        .'|get_wsgi_application|get_asgi_application)|falcon\.App)[ \t]*\(/m';

    private const ASGI_IMPORT = '/^[ \t]*(?:from|import)[ \t]+(?:fastapi|starlette|quart|litestar|sanic)\b/m';

    private const WSGI_IMPORT = '/^[ \t]*(?:from|import)[ \t]+(?:flask|bottle|falcon|pyramid)\b/m';

    private function __construct(
        public readonly string $module,
        public readonly string $callable,
        public readonly string $searchPath,
        public readonly ?string $interface,
    ) {}

    public static function find(string $projectDir): ?self
    {
        $projectDir = rtrim($projectDir, '/');
        $candidates = self::candidates($projectDir, '', 0);
        if ($candidates === []) {
            return null;
        }
        usort($candidates, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);
        if (isset($candidates[1]) && $candidates[1]['rank'] === $candidates[0]['rank']) {
            return null;
        }

        return self::of($projectDir, $candidates[0]['path'], $candidates[0]['callable'], $candidates[0]['contents']);
    }

    /**
     * @return list<array{path: string, callable: string, contents: string, rank: array{int, int}}>
     */
    private static function candidates(string $projectDir, string $relative, int $depth): array
    {
        $dir = $relative === '' ? $projectDir : $projectDir.'/'.$relative;
        $found = [];
        foreach (ProjectContext::entries($dir) as $entry) {
            if ($entry === '' || $entry[0] === '.') {
                continue;
            }
            $path = $relative === '' ? $entry : $relative.'/'.$entry;
            if (is_dir($dir.'/'.$entry)) {
                if ($depth < self::MAX_DEPTH && ! in_array(strtolower($entry), self::SKIPPED_DIRS, true)) {
                    array_push($found, ...self::candidates($projectDir, $path, $depth + 1));
                }

                continue;
            }
            $candidate = self::candidate($dir.'/'.$entry, $path, $depth);
            if ($candidate !== null) {
                $found[] = $candidate;
            }
        }

        return $found;
    }

    /**
     * @return array{path: string, callable: string, contents: string, rank: array{int, int}}|null
     */
    private static function candidate(string $file, string $path, int $depth): ?array
    {
        if (! str_ends_with($file, '.py') || self::isTestModule(basename($file))) {
            return null;
        }
        $contents = AccountHomeRead::contents($file, self::MAX_BYTES);
        if (! is_string($contents) || preg_match(self::BINDING, $contents, $match) !== 1) {
            return null;
        }
        $name = array_search(strtolower(basename($file)), self::NAMES, true);

        return [
            'path' => $path,
            'callable' => $match[1],
            'contents' => $contents,
            'rank' => [$depth, $name === false ? count(self::NAMES) : $name],
        ];
    }

    private static function isTestModule(string $name): bool
    {
        return preg_match('/^(?:test_.*|.*_test|conftest|setup|noxfile)\.py$/i', $name) === 1;
    }

    /**
     * The module as the server imports it: from the outermost directory that is not part of its package, where a
     * directory belongs to the package when it holds `__init__.py` or the module imports it by name or relatively.
     */
    private static function of(string $projectDir, string $path, string $callable, string $contents): self
    {
        $parts = explode('/', substr($path, 0, -3));
        $file = array_pop($parts);
        $top = count($parts);
        while ($top > 0 && self::belongsToThePackage($projectDir, $parts, $top, $contents)) {
            $top--;
        }

        $module = array_slice($parts, $top);
        if ($file !== '__init__' || $module === []) {
            $module[] = $file;
        }

        return new self(
            implode('.', $module),
            $callable,
            implode('/', array_slice($parts, 0, $top)),
            self::interfaceOf($contents),
        );
    }

    /** @param  list<string>  $parts */
    private static function belongsToThePackage(string $projectDir, array $parts, int $top, string $contents): bool
    {
        $dir = implode('/', array_slice($parts, 0, $top));
        $name = preg_quote($parts[$top - 1], '/');

        return is_file($projectDir.'/'.$dir.'/__init__.py')
            || preg_match('/^[ \t]*(?:from|import)[ \t]+'.$name.'(?:\.|[ \t]+import\b)/m', $contents) === 1
            || ($top === count($parts) && preg_match('/^[ \t]*from[ \t]+\./m', $contents) === 1);
    }

    private static function interfaceOf(string $contents): ?string
    {
        if (preg_match(self::ASGI_IMPORT, $contents) === 1) {
            return self::ASGI;
        }

        return preg_match(self::WSGI_IMPORT, $contents) === 1 ? self::WSGI : null;
    }
}
