<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Detect\PlaceholderPage;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\Python\ApplicationObject;

/**
 * Python, versioned by `.python-version` when it pins one, else by `requires-python` in pyproject.toml; requirements.txt pins
 * packages, not the interpreter.
 */
final class PythonRuntime implements Runtime
{
    /**
     * Bare minors, oldest first, the compiled-in fallback for the catalogue's `versions`; nothing reads this directly, as
     * {@see versions()} answers in the `3.12` form the rest of the engine speaks.
     *
     * @var list<int>
     */
    public const MINORS = [10, 11, 12, 13, 14];

    public const DEFAULT_MINOR = 12;

    /**
     * slim, not alpine: a manylinux wheel does not install against musl, so alpine turns every dependency carrying a C extension
     * into a source build.
     */
    public const IMAGE_VARIANT = 'slim';

    /**
     * A const expression, so it cannot read the catalogue: for callers needing
     * a compile-time constant. {@see defaultImage()} honours a configured one.
     */
    public const IMAGE = 'python:3.'.self::DEFAULT_MINOR.'-'.self::IMAGE_VARIANT;

    /**
     * The versions a project may resolve to, oldest first, as `3.12`.
     *
     * @return list<string>
     */
    public static function versions(): array
    {
        $configured = RuntimeImageCatalog::versions('python');
        if ($configured !== []) {
            return $configured;
        }

        return array_map(static fn (int $minor): string => '3.'.$minor, self::MINORS);
    }

    /** What a project that states no version gets, as `3.12`. */
    public static function defaultVersion(): string
    {
        return RuntimeImageCatalog::defaultVersion('python') ?? '3.'.self::DEFAULT_MINOR;
    }

    public static function defaultImage(): string
    {
        return self::imageTag(self::defaultVersion());
    }

    public static function imageTag(string $version): string
    {
        $spec = RuntimeImageCatalog::spec('python', $version);

        return $spec?->from ?? 'python:'.$version.'-'.self::IMAGE_VARIANT;
    }

    /** @var list<string> */
    private const MANIFESTS = ['requirements.txt', 'pyproject.toml', 'pipfile', 'setup.py'];

    public function id(): string
    {
        return 'python';
    }

    /**
     * An exact `.python-version` pin wins over a `requires-python` range: uv and pyenv run what it names, and a venv built on
     * another minor is not the one the image runs.
     */
    public function resolve(ProjectContext $context): ?Requirement
    {
        $present = self::hasPythonManifest($context);
        if (! $present && ! $context->isFile('manage.py')) {
            return null;
        }

        $pinned = self::pinnedMinor($context->contents('.python-version'));
        if ($pinned !== null) {
            return new Requirement('python', '3.'.$pinned, '', '.python-version');
        }

        $toml = $context->contents('pyproject.toml');
        if ($toml === null
            || preg_match('/requires-python\s*=\s*[\'"]([^\'"]+)[\'"]/', $toml, $matches) !== 1
        ) {
            return new Requirement('python', self::defaultVersion(), '', 'engine default');
        }

        return self::requiredByPyproject($matches[1]);
    }

    /**
     * Whether a Python manifest is present; a pyproject.toml only when it declares a project, as the name is TOML's generic
     * config file and tools such as ruff or codespell borrow it (Dolibarr, a PHP application, was detected as Python).
     *
     * @see declaresProject()
     */
    public static function hasPythonManifest(ProjectContext $context): bool
    {
        $present = false;
        foreach (self::MANIFESTS as $manifest) {
            if (! $context->hasFile($manifest)) {
                continue;
            }
            if ($manifest === 'pyproject.toml' && ! self::declaresProject($context->contents($manifest))) {
                continue;
            }
            $present = true;
            break;
        }

        return $present;
    }

    /** The oldest known version a `requires-python` constraint allows, or its own minor when it is newer than all of them. */
    private static function requiredByPyproject(string $constraint): Requirement
    {
        $minor = self::parseMinor($constraint);
        if ($minor === null) {
            return new Requirement('python', self::defaultVersion(), $constraint, 'engine default');
        }

        $wanted = '3.'.$minor;
        foreach (self::versions() as $known) {
            if (version_compare($known, $wanted, '>=')) {
                return new Requirement('python', $known, $constraint, 'pyproject.toml requires-python');
            }
        }

        return new Requirement('python', $wanted, $constraint, 'pyproject.toml requires-python');
    }

    public function image(Requirement $requirement): string
    {
        return self::imageTag($requirement->version);
    }

    /**
     * The 3.x minor on the first version line of a .python-version
     * (`3.11.13`, `3.11`, `cpython@3.11`, `cpython-3.11.13-linux-x86_64-gnu`).
     */
    private static function pinnedMinor(?string $file): ?int
    {
        foreach (preg_split('/\R/', (string) $file) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            return preg_match('/(?:^|[^0-9.])3\.(\d+)(?:\.\d+)?(?:[^0-9.]|$)/', $line, $m) === 1 ? (int) $m[1] : null;
        }

        return null;
    }

    /** Lowest 3.x minor a `>=3.11`-shaped constraint allows. */
    private static function parseMinor(string $constraint): ?int
    {
        if (preg_match('/3\s*\.\s*(\d+)/', $constraint, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * The project's virtualenv, so the app runs from the stock image with nothing baked or exported, as PHP's `vendor/` does: a
     * redeploy replaces files, not layers, and host build and container both see it at /app.
     */
    public const VENV = '.venv';

    /**
     * Re-running `venv` keeps a rebuild's packages (no --upgrade-deps: it reinstalls pip every deploy), and pip keeps the cache the
     * host build mounts. Tool config still gets the venv every start runs through; a lockless package needs `pip install .`.
     *
     * @param  array<string, true>  $files
     */
    public static function installCommand(array $files, ?string $pyproject = null): string
    {
        $venv = 'python -m venv '.self::VENV.' && ';
        $pip = self::VENV.'/bin/pip install ';

        if (isset($files['requirements.txt'])) {
            return $venv.$pip.'-r requirements.txt';
        }
        if (isset($files['pipfile'])) {
            return self::pipenvInstall($files, $venv, $pip);
        }
        if (self::isNonPackagePoetry($pyproject)) {
            return self::poetryInstall($venv, $pip);
        }

        if (isset($files['uv.lock'])) {
            return self::uvInstall($pyproject, $venv, $pip);
        }

        if (self::declaresNothingToInstall($files, $pyproject)) {
            return 'python -m venv '.self::VENV;
        }

        return $venv.$pip.'.';
    }

    /**
     * A committed Pipfile.lock is installed as-is, like `uv sync --frozen`: `install --deploy` aborts when the lock's hash lags
     * the Pipfile, though the project's own image installs it with `pipenv sync`.
     *
     * @param  array<string, true>  $files
     */
    private static function pipenvInstall(array $files, string $venv, string $pip): string
    {
        $pipenv = isset($files['pipfile.lock']) ? 'sync' : 'install --deploy';

        return $venv.$pip.'pipenv && '.self::VENV.'/bin/pipenv '.$pipenv;
    }

    /**
     * `package-mode = false` is a program, whose root `pip install .` cannot build, so `poetry install --no-root` installs only
     * the dependencies, into the same .venv through VIRTUAL_ENV rather than one under a cache the runtime never reads.
     */
    private static function poetryInstall(string $venv, string $pip): string
    {
        return $venv.$pip.'poetry'
            .' && VIRTUAL_ENV="$PWD/'.self::VENV.'" '.self::VENV
            .'/bin/poetry install --no-root --no-interaction';
    }

    /**
     * `sync --frozen` installs the committed lock with every group (`--no-dev` drops runtime sets kept under `dev`), the project
     * only with a [build-system] (pip cannot skip the root); UV_PYTHON keeps the venv off a CPython uv would download outside /app.
     */
    private static function uvInstall(?string $pyproject, string $venv, string $pip): string
    {
        return $venv.$pip.'uv'
            .' && UV_PYTHON_DOWNLOADS=never UV_PYTHON="$(command -v python)"'
            .' VIRTUAL_ENV="$PWD/'.self::VENV.'" '.self::VENV
            .'/bin/uv sync --frozen'.(self::isUvPackage($pyproject) ? '' : ' --no-install-project');
    }

    /** uv builds and installs a project only when it names a build backend. */
    private static function isUvPackage(?string $pyproject): bool
    {
        return $pyproject !== null
            && self::hasTable($pyproject, 'project')
            && self::hasTable($pyproject, 'build-system');
    }

    /**
     * Whether {@see installCommand()} installs the project itself, which is
     * where its console scripts come from.
     *
     * @param  array<string, true>  $files
     */
    private static function installsProject(array $files, ?string $pyproject): bool
    {
        if (isset($files['requirements.txt']) || isset($files['pipfile'])
            || self::isNonPackagePoetry($pyproject)) {
            return false;
        }
        if (isset($files['uv.lock'])) {
            return self::isUvPackage($pyproject);
        }

        return ! self::declaresNothingToInstall($files, $pyproject);
    }

    /**
     * Whether the only manifest is a pyproject declaring no project, which {@see declaresProject()} answers: nothing to build or
     * install. A `setup.py` or `setup.cfg` beside it is metadata this reader cannot see, so it is never judged alone.
     *
     * @param  array<string, true>  $files
     */
    public static function declaresNothingToInstall(array $files, ?string $pyproject): bool
    {
        if ($pyproject === null
            || isset($files['setup.py'])
            || isset($files['setup.cfg'])
        ) {
            return false;
        }

        return ! self::declaresProject($pyproject);
    }

    /**
     * Whether a pyproject.toml is a Python project manifest (`[project]` or `[tool.poetry]`), not a tool's settings. Not
     * `[build-system]`: Dolibarr, a PHP application, has one with `setuptools.build_meta`.
     */
    public static function declaresProject(?string $pyproject): bool
    {
        return $pyproject !== null
            && (self::hasTable($pyproject, 'project') || self::hasTable($pyproject, 'tool.poetry'));
    }

    /**
     * Whether a TOML document has exactly this table, not one whose name merely starts with it (`[project.scripts]` is not
     * `[project]`); a comment may follow the header, as in `[project]  # the app`.
     */
    private static function hasTable(string $toml, string $table): bool
    {
        return preg_match('/'.self::tableHeader($table).'/m', $toml) === 1;
    }

    /** The header line of a TOML table, indented or not, a `# comment` allowed after it. */
    private static function tableHeader(string $table): string
    {
        return '^[ \t]*\['.preg_quote($table, '/').'\][ \t]*(?:#[^\n]*)?\r?$';
    }

    /**
     * Whether this pyproject is a Poetry application, `package-mode = false` in any of Poetry's boolean spellings, inside
     * `[tool.poetry]` only: there is no importable package to build, so pip cannot install the project.
     */
    public static function isNonPackagePoetry(?string $pyproject): bool
    {
        if ($pyproject === null) {
            return false;
        }
        $section = self::tableBody($pyproject, 'tool.poetry');

        return $section !== null && preg_match('/^\s*package-mode\s*=\s*false\s*(?:#.*)?$/mi', $section) === 1;
    }

    /** The venv's interpreter, which is what every Python command must run under. */
    public static function python(): string
    {
        return self::VENV.'/bin/python';
    }

    /** The port the recipe publishes and a server has to bind; python.yaml declares the same. */
    public const PORT = 8000;

    /**
     * Where the last-resort server serves from: a directory of our own, so it
     * cannot publish the project.
     */
    public const PLACEHOLDER_DIR = '.panelalpha-not-started';

    /** Modules that conventionally hold the application object, in order. */
    private const ENTRYPOINTS = ['app.py', 'main.py', 'wsgi.py', 'asgi.py', 'server.py'];

    /**
     * Modules a declared server imports in the nested search; the root search takes every entry point, but below it `app.py`
     * beside a manage.py is a Django application module, not a server target.
     *
     * @var list<string>
     */
    private const WSGI_MODULES = ['wsgi.py', 'asgi.py'];

    /**
     * A declared WSGI or ASGI server wins over running the module (a Flask `app.run()` binds 127.0.0.1:5000, answering nothing on
     * the published port); the root, where a project states itself, before a nested Django project, then the project's scripts.
     *
     * @param  list<string>  $manifests  contents of requirements.txt and friends
     */
    public static function startCommand(string $projectDir, array $manifests = []): string
    {
        $projectDir = rtrim($projectDir, '/');
        $server = self::declaredServer($manifests);

        $root = self::applicationModule($projectDir, self::ENTRYPOINTS);
        if ($root !== null && $server !== null) {
            return self::serverCommand($server, $root[0], $root[1], '');
        }

        $script = self::rootScriptCommand($projectDir);
        if ($script !== null) {
            return $script;
        }

        if ($server !== null) {
            $nested = self::nestedServableModule($projectDir);
            if ($nested !== null) {
                return self::serverCommand($server, $nested[0], $nested[1], $nested[2]);
            }
        }

        return self::declaredScriptCommand($projectDir)
            ?? self::applicationObjectCommand($projectDir, $manifests)
            ?? self::lastResortServer();
    }

    /**
     * A declared server pointed at the one application object found below the root, before giving up: an ASGI app
     * only under uvicorn and a WSGI app only under gunicorn, as the other server cannot serve it.
     *
     * @param  list<string>  $manifests
     */
    private static function applicationObjectCommand(string $projectDir, array $manifests): ?string
    {
        $found = ApplicationObject::find($projectDir);
        if ($found === null) {
            return null;
        }
        $server = self::serverFor($found->interface, $manifests);

        return $server === null
            ? null
            : self::serverCommand($server, $found->module, $found->callable, $found->searchPath);
    }

    /** @param  list<string>  $manifests */
    private static function serverFor(?string $interface, array $manifests): ?string
    {
        $wanted = match ($interface) {
            ApplicationObject::ASGI => 'uvicorn',
            ApplicationObject::WSGI => 'gunicorn',
            default => null,
        };
        if ($wanted === null) {
            return self::declaredServer($manifests);
        }

        return self::declares($manifests, $wanted) ? $wanted : null;
    }

    /**
     * A root entry point run as a script. A wsgi.py or asgi.py only with a `__main__` guard: it is a hand-off to a server, and
     * running it imports the module and exits, so the account crash-loops with nothing bound.
     */
    private static function rootScriptCommand(string $projectDir): ?string
    {
        foreach (self::ENTRYPOINTS as $file) {
            if (! is_file($projectDir.'/'.$file)) {
                continue;
            }
            if (self::isServerHandoff($file) && ! self::hasMainGuard($projectDir.'/'.$file)) {
                continue;
            }

            return self::python().' '.$file;
        }

        return null;
    }

    /** The console script the project declares, else its one root script with a `__main__` guard. */
    private static function declaredScriptCommand(string $projectDir): ?string
    {
        $script = self::consoleScript($projectDir);
        if ($script !== null) {
            return self::VENV.'/bin/'.$script;
        }

        $main = self::soleMainScript($projectDir);
        if ($main !== null) {
            return self::python().' '
                .(preg_match('#^[A-Za-z0-9._-]+$#', $main) === 1 ? $main : escapeshellarg($main));
        }

        return null;
    }

    /**
     * The `[project.scripts]` entry to start, when the install puts it in the venv: the only one, or the one named after the
     * project (PEP 503 normalised); several with none of them the project's own is a guess, and null.
     */
    private static function consoleScript(string $projectDir): ?string
    {
        $pyproject = AccountHomeRead::contents($projectDir.'/pyproject.toml');
        if (! is_string($pyproject)
            || ! self::installsProject(ProjectContext::listRootFiles($projectDir), $pyproject)
        ) {
            return null;
        }
        $table = self::tableBody($pyproject, 'project.scripts') ?? '';
        preg_match_all('/^[ \t]*["\']?([A-Za-z0-9._-]+)["\']?[ \t]*=/m', $table, $matches);
        $scripts = array_values(array_unique($matches[1]));
        if (count($scripts) <= 1) {
            return $scripts[0] ?? null;
        }

        $normalise = static fn (string $name): string => strtolower((string) preg_replace('/[-_.]+/', '-', $name));
        $project = self::tableBody($pyproject, 'project') ?? '';
        if (preg_match('/^[ \t]*name[ \t]*=[ \t]*["\']([^"\']+)["\']/m', $project, $name) !== 1) {
            return null;
        }
        $own = array_filter(
            $scripts,
            static fn (string $script): bool => $normalise($script) === $normalise($name[1])
        );

        return count($own) === 1 ? reset($own) : null;
    }

    /** The lines of one TOML table, up to the next table header. */
    private static function tableBody(string $toml, string $table): ?string
    {
        $header = '/'.self::tableHeader($table).'(.*?)(?=^[ \t]*\[|\z)/ms';

        return preg_match($header, $toml, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * The single root-level `*.py` that runs as a program. Tooling and tests
     * are not candidates, and two programs are a guess, so null.
     */
    private static function soleMainScript(string $projectDir): ?string
    {
        $found = null;
        foreach (ProjectContext::entries($projectDir) as $entry) {
            if (! str_ends_with($entry, '.py') || ! is_file($projectDir.'/'.$entry)
                || in_array(strtolower($entry), ['setup.py', 'conftest.py', 'manage.py', 'noxfile.py'], true)
                || preg_match('/^test_|_test\.py$/i', $entry) === 1
                || ! self::hasMainGuard($projectDir.'/'.$entry)
            ) {
                continue;
            }
            if ($found !== null) {
                return null;
            }
            $found = $entry;
        }

        return $found;
    }

    /**
     * A declared server importing `module:callable`, gunicorn on 0.0.0.0, not its 127.0.0.1 default, to be reachable from outside
     * the container. The search-path flag appears only for a module below the working directory, a nested Django package.
     */
    private static function serverCommand(string $server, string $module, string $callable, string $searchPath): string
    {
        if ($server === 'uvicorn') {
            return self::VENV.'/bin/uvicorn'
                .self::searchPathOption('uvicorn', $searchPath)
                .' '.$module.':'.$callable
                .' --host 0.0.0.0 --port '.self::PORT;
        }

        return self::VENV.'/bin/gunicorn --bind 0.0.0.0:'.self::PORT
            .' --timeout 120'.self::searchPathOption('gunicorn', $searchPath)
            .' '.$module.':'.$callable;
    }

    /** `wsgi.py` and `asgi.py` name a callable for a server to import. */
    private static function isServerHandoff(string $file): bool
    {
        return $file === 'wsgi.py' || $file === 'asgi.py';
    }

    private static function hasMainGuard(string $path): bool
    {
        $contents = AccountHomeRead::contents($path);

        return is_string($contents)
            && preg_match('/^if\s+__name__\s*==/m', $contents) === 1;
    }

    /**
     * Nothing is runnable, yet an account that answers is easier to diagnose than one crash-looping: one generated page from an
     * empty directory, never the checkout (`/.git/config` once answered 200), titled so `not-placeholder` does not report it healthy.
     */
    private static function lastResortServer(): string
    {
        $dir = self::PLACEHOLDER_DIR;
        $page = '<!doctype html><title>'.PlaceholderPage::NOT_CONFIGURED_TITLE.'</title>'
            .'<h1>This application did not start</h1>'
            .'<p>PanelAlpha found no Python entry point in this repository, so nothing is running. '
            .'Name the entry point in a panelalpha.yaml, declare one [project.scripts] entry, or add one of: '
            .implode(', ', self::ENTRYPOINTS).'.</p>';

        return 'mkdir -p '.$dir
            ." && printf '%s' ".escapeshellarg($page).' > '.$dir.'/index.html'
            .' && '.self::python().' -m http.server '.self::PORT.' --directory '.$dir;
    }

    /**
     * gunicorn first: a project listing both normally serves a WSGI app through gunicorn with a uvicorn worker class, and gunicorn
     * takes the bind address.
     *
     * @param  list<string>  $manifests
     */
    private static function declaredServer(array $manifests): ?string
    {
        foreach (['gunicorn', 'uvicorn'] as $server) {
            if (self::declares($manifests, $server)) {
                return $server;
            }
        }

        return null;
    }

    /** @param  list<string>  $manifests */
    private static function declares(array $manifests, string $server): bool
    {
        $text = strtolower(implode("\n", array_filter($manifests, 'is_string')));

        return preg_match('/(?<![a-z0-9_.-])'.$server.'(?![a-z0-9_.-])/', $text) === 1;
    }

    /**
     * A Django project below the root as [module, callable, search path], such as NetBox's `netbox.wsgi` with `netbox/` on the path.
     * Only with a declared server: a WSGI module run as a script would crash-loop the account.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private static function nestedServableModule(string $projectDir): ?array
    {
        foreach (self::djangoProjectLayout($projectDir) as $module) {
            $contents = AccountHomeRead::contents($projectDir.'/'.$module[2]);
            if (! is_string($contents)) {
                continue;
            }
            foreach (['application', 'app'] as $callable) {
                if (preg_match('/^'.$callable.'\s*=/m', $contents) === 1) {
                    return [$module[0], $callable, $module[1]];
                }
            }
        }

        return null;
    }

    /**
     * The WSGI/ASGI modules of every Django project, as [module, search path, file], outermost first: a package holding `wsgi.py`
     * beside a `manage.py`, which keeps out one vendored in a library, as Django never writes one without its manage.py.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function djangoProjectLayout(string $projectDir): array
    {
        $candidates = [];
        foreach (self::managePyDirs($projectDir) as $relative) {
            $projectDirOf = $relative === '' ? $projectDir : $projectDir.'/'.$relative;
            foreach (self::WSGI_MODULES as $module) {
                foreach (ProjectContext::entries($projectDirOf) as $entry) {
                    if ($entry === '.' || $entry === '..' || $entry[0] === '.' || self::isNoiseDir($entry)) {
                        continue;
                    }
                    if (! is_file($projectDirOf.'/'.$entry.'/'.$module)
                        || ! is_file($projectDirOf.'/'.$entry.'/__init__.py')) {
                        continue;
                    }
                    $package = $relative === '' ? $entry : $relative.'/'.$entry;
                    $candidates[] = [
                        basename($entry).'.'.basename($module, '.py'),
                        $relative,
                        $package.'/'.$module,
                    ];
                }
            }
        }

        return $candidates;
    }

    /**
     * The directory holding the project's `manage.py`, relative to the root (`''` for the root), or null. Asked by the `django`
     * probe and the Django recipe's commands, so they cannot disagree about a layout.
     */
    public static function djangoManageDir(string $projectDir): ?string
    {
        return self::managePyDirs(rtrim($projectDir, '/'))[0] ?? null;
    }

    /**
     * `manage.py` by its path from the root, not after a `cd`, which would lose the root's venv; Python puts the script's directory
     * on `sys.path`. Quoted only when needed: a directory name may hold a metacharacter, but `'manage.py'` would fill every log.
     *
     * @see nestedServableModule() for the same reasoning behind a declared server's `--pythonpath`
     */
    public static function manageCommand(string $projectDir, string $arguments): string
    {
        $dir = self::djangoManageDir($projectDir);
        $script = $dir === null || $dir === '' ? 'manage.py' : $dir.'/manage.py';

        return self::python().' '.self::shellPath(self::notAnOption($script)).' '.$arguments;
    }

    /** A path as one shell word: bare when it holds only `[A-Za-z0-9._/-]`, escaped otherwise. */
    private static function shellPath(string $path): string
    {
        return preg_match('#^[A-Za-z0-9._/-]+\z#', $path) === 1 ? $path : escapeshellarg($path);
    }

    /** A relative path led by `./` when it starts with `-`, so the program it is handed to does not read an option. */
    private static function notAnOption(string $path): string
    {
        return str_starts_with($path, '-') ? './'.$path : $path;
    }

    /**
     * How a Django project is served: a WSGI/ASGI server it declared, against its own module, and `manage.py runserver`, the
     * development server that says so on every boot, only when it declared none.
     *
     * @param  list<string>  $manifests  contents of requirements.txt and friends
     */
    public static function djangoStartCommand(string $projectDir, array $manifests = []): string
    {
        $projectDir = rtrim($projectDir, '/');
        $server = self::declaredServer($manifests);
        if ($server !== null) {
            $nested = self::nestedServableModule($projectDir);
            if ($nested !== null) {
                return self::serverCommand($server, $nested[0], $nested[1], $nested[2]);
            }
        }

        return self::manageCommand($projectDir, 'runserver 0.0.0.0:'.self::PORT);
    }

    /**
     * Whether a `manage.py` is Django's, as the name alone is not (bitcart's is a FastAPI router): it says so itself, or, trimmed
     * to a shebang, sits beside a package holding `wsgi.py`/`asgi.py`, the shape `django-admin startproject` writes.
     */
    private static function isDjangoManagePy(string $dir): bool
    {
        $contents = AccountHomeRead::contents($dir.'/manage.py');
        if (is_string($contents) && $contents !== ''
            && preg_match(
                '/DJANGO_SETTINGS_MODULE|execute_from_command_line|from\s+django\b|import\s+django\b/',
                $contents
            ) === 1
        ) {
            return true;
        }

        foreach (ProjectContext::entries($dir) as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '.' || self::isNoiseDir($entry)) {
                continue;
            }
            $package = $dir.'/'.$entry;
            if (! is_file($package.'/__init__.py')) {
                continue;
            }
            foreach (self::WSGI_MODULES as $module) {
                if (is_file($package.'/'.$module)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Directories holding a *Django* `manage.py`, relative to the root, the root first; three levels at most, as a Django project
     * does not keep its manage.py four levels down.
     *
     * @return list<string>
     */
    private static function managePyDirs(string $projectDir, string $relative = '', int $depth = 0): array
    {
        $dirs = [];
        $dir = $relative === '' ? $projectDir : $projectDir.'/'.$relative;
        if (is_file($dir.'/manage.py') && self::isDjangoManagePy($dir)) {
            $dirs[] = $relative;
        }
        if ($depth >= 3) {
            return $dirs;
        }
        foreach (ProjectContext::entries($dir) as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '.' || self::isNoiseDir($entry)) {
                continue;
            }
            if (! is_dir($dir.'/'.$entry)) {
                continue;
            }
            $child = $relative === '' ? $entry : $relative.'/'.$entry;
            $dirs = array_merge($dirs, self::managePyDirs($projectDir, $child, $depth + 1));
        }

        return $dirs;
    }

    /** Directories never worth descending into for a project entry point. */
    private static function isNoiseDir(string $name): bool
    {
        return in_array($name, [
            '__pycache__', 'node_modules', 'site-packages', 'dist-packages',
            'venv', '.venv', '.tox', '.nox', '.eggs', 'build', 'dist',
        ], true);
    }

    /**
     * The module holding the application object, and the name it is bound to.
     *
     * @param  list<string>  $files  basenames to look for, in order
     * @return array{0: string, 1: string}|null module and callable name
     */
    private static function applicationModule(string $dir, array $files): ?array
    {
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            if (! is_file($path)) {
                continue;
            }
            $contents = AccountHomeRead::contents($path);
            if (! is_string($contents)) {
                continue;
            }
            foreach (['app', 'application'] as $name) {
                if (preg_match('/^'.$name.'\s*=/m', $contents) === 1) {
                    return [substr($file, 0, -3), $name];
                }
            }
        }

        return null;
    }

    /**
     * The flag putting the package directory on the import path (gunicorn `--pythonpath`, uvicorn `--app-dir`), or '' when the
     * module imports as it is; without it the nested module cannot be imported and gunicorn exits.
     */
    private static function searchPathOption(string $server, string $searchPath): string
    {
        if ($searchPath === '') {
            return '';
        }

        $flag = $server === 'uvicorn' ? '--app-dir' : '--pythonpath';

        return " {$flag}=".self::shellPath($searchPath);
    }

    public function defaultRequirement(): Requirement
    {
        return new Requirement('python', self::defaultVersion(), '', 'engine default');
    }

    /**
     * @return list<string>
     */
    public function supportedVersions(): array
    {
        return self::versions();
    }
}
