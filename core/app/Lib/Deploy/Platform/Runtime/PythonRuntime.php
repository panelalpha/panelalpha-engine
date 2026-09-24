<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\Detect\PlaceholderPage;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Python, versioned by `requires-python` in pyproject.toml.
 *
 * Nothing else states a version the engine can trust: requirements.txt pins
 * packages, not the interpreter.
 */
final class PythonRuntime implements Runtime
{
    /**
     * Bare minors, oldest first — the compiled-in fallback for the catalogue's
     * `versions`. {@see versions()} answers in the `3.12` form the rest of the
     * engine speaks, which is why nothing reads this directly.
     *
     * @var list<int>
     */
    public const MINORS = [10, 11, 12, 13, 14];

    public const DEFAULT_MINOR = 12;

    /**
     * slim, not alpine: a wheel built for manylinux does not install against
     * musl, so alpine turns every dependency carrying a C extension into a
     * source build.
     */
    public const IMAGE_VARIANT = 'slim';

    /**
     * A const expression, so it cannot read the catalogue: for callers needing
     * a compile-time constant. {@see defaultImage()} honours a configured one.
     */
    public const IMAGE = 'python:3.' . self::DEFAULT_MINOR . '-' . self::IMAGE_VARIANT;

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

        return array_map(static fn (int $minor): string => '3.' . $minor, self::MINORS);
    }

    /** What a project that states no version gets, as `3.12`. */
    public static function defaultVersion(): string
    {
        return RuntimeImageCatalog::defaultVersion('python') ?? '3.' . self::DEFAULT_MINOR;
    }

    public static function defaultImage(): string
    {
        return self::imageTag(self::defaultVersion());
    }

    public static function imageTag(string $version): string
    {
        $spec = RuntimeImageCatalog::spec('python', $version);

        return $spec?->from ?? 'python:' . $version . '-' . self::IMAGE_VARIANT;
    }

    /** @var list<string> */
    private const MANIFESTS = ['requirements.txt', 'pyproject.toml', 'pipfile', 'setup.py'];

    public function id(): string
    {
        return 'python';
    }

    public function resolve(ProjectContext $context): ?Requirement
    {
        $present = false;
        foreach (self::MANIFESTS as $manifest) {
            if (!$context->hasFile($manifest)) {
                continue;
            }
            // A pyproject.toml is evidence only when it is a Python project's
            // manifest. The name is TOML's generic config filename and the
            // ecosystem borrows it for everything -- ruff, sqlfluff -- so a
            // file of tool settings does not mean the project is Python.
            // Dolibarr, a PHP application whose root pyproject.toml runs
            // codespell, was detected as Python and served the placeholder.
            // {@see declaresProject()}
            if ($manifest === 'pyproject.toml' && !self::declaresProject($context->contents($manifest))) {
                continue;
            }
            $present = true;
            break;
        }
        if (!$present && !$context->isFile('manage.py')) {
            return null;
        }

        $toml = $context->contents('pyproject.toml');
        if ($toml === null
            || preg_match('/requires-python\s*=\s*[\'"]([^\'"]+)[\'"]/', $toml, $matches) !== 1
        ) {
            return new Requirement('python', self::defaultVersion(), '', 'engine default');
        }

        $constraint = $matches[1];
        $minor = self::parseMinor($constraint);
        if ($minor === null) {
            return new Requirement('python', self::defaultVersion(), $constraint, 'engine default');
        }

        $wanted = '3.' . $minor;
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

    /** Lowest 3.x minor a `>=3.11`-shaped constraint allows. */
    private static function parseMinor(string $constraint): ?int
    {
        if (preg_match('/3\s*\.\s*(\d+)/', $constraint, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Where the project's dependencies live: a virtualenv inside the project,
     * not the image's global site-packages.
     *
     * This is what lets a Python app run from our stock image with nothing
     * built. Keeping packages installed by `pip install` meant baking them into
     * a per-project image and exporting it, which for a project of similar size
     * was 180.7s of a 299.7s build.
     *
     * The same trick PHP uses with `vendor/`: the dependencies are part of the
     * account's files, so the image stays generic and a redeploy replaces files
     * instead of layers. Host build and runtime container see it at the same
     * path because both mount the project at /app.
     */
    public const VENV = '.venv';

    /**
     * @param array<string, true> $files
     */
    public static function installCommand(array $files, ?string $pyproject = null): string
    {
        // Re-running `venv` over an existing one is a no-op, so a rebuild
        // keeps the packages it already has and pip only reconciles the diff.
        // No --upgrade-deps: it reinstalls pip and setuptools every deploy.
        $venv = 'python -m venv ' . self::VENV . ' && ';
        // Cached, not --no-cache-dir: the host build mounts a per-project
        // pip cache, and there is no image layer here for the cache to bloat.
        $pip = self::VENV . '/bin/pip install ';

        if (isset($files['requirements.txt'])) {
            return $venv . $pip . '-r requirements.txt';
        }
        // A committed Pipfile.lock is installed as-is, like `uv sync --frozen`:
        // `install --deploy` aborts when the lock's hash lags the Pipfile
        // (JARR), though the project's own image installs it with `pipenv sync`.
        if (isset($files['pipfile'])) {
            $pipenv = isset($files['pipfile.lock']) ? 'sync' : 'install --deploy';

            return $venv . $pip . 'pipenv && ' . self::VENV . '/bin/pipenv ' . $pipenv;
        }
        // A Poetry application declaring `package-mode = false` is a
        // deployable program, not a distributable package: `pip install .`
        // asks poetry-core to build a wheel of the root and it refuses with
        // `RuntimeError: Building a package is not possible in non-package
        // mode.` `poetry install --no-root` installs the dependencies and skips
        // the project. VIRTUAL_ENV points poetry at the same .venv the other
        // installs write into; without it poetry makes its own under a cache
        // directory the runtime never looks at.
        if (self::isNonPackagePoetry($pyproject)) {
            return $venv . $pip . 'poetry'
                . ' && VIRTUAL_ENV="$PWD/' . self::VENV . '" ' . self::VENV
                . '/bin/poetry install --no-root --no-interaction';
        }

        // A uv project states its resolution in `uv.lock`, and
        // `--no-install-project` is uv's spelling of "dependencies only". pip
        // has no equivalent — `--no-deps` still builds the root, and a root
        // holding sibling packages (bitcart's api/conf/daemons) makes
        // setuptools refuse with `Multiple top-level packages discovered in a
        // flat-layout`. `--frozen` installs what the project committed, the way
        // `npm ci` does.
        //
        // Not `--no-dev`: a uv project's runtime set is not always in
        // `dependencies` — bitcart keeps fastapi and uvicorn in a `web` group
        // reachable only through the `dev` umbrella, so `--no-dev` installs two
        // packages and the app cannot start.
        if (isset($files['uv.lock'])) {
            return $venv . $pip . 'uv'
                . ' && VIRTUAL_ENV="$PWD/' . self::VENV . '" ' . self::VENV
                . '/bin/uv sync --frozen --no-install-project';
        }

        // A pyproject declaring neither `[project]` (PEP 621) nor a Poetry
        // project is configuration for a tool, not a manifest: ruff, codespell,
        // towncrier, or the `[build-system]` a non-Python application left
        // behind (Dolibarr). `pip install .` would fail on it anyway, with
        // `configuration error: 'project' must contain ['version'] properties`.
        // The venv is still created: every start command runs through it.
        if (self::declaresNothingToInstall($files, $pyproject)) {
            return 'python -m venv ' . self::VENV;
        }

        // Everything left states a package and has no lockfile: Poetry in
        // package mode, PEP 621, a bare setup.py. `pip install .` is the only
        // way to install their dependencies, and also where a console entry
        // point and an importable root package come from (mopidy's `mopidy`
        // script) — so building the project is kept here, and only here.
        return $venv . $pip . '.';
    }

    /**
     * Whether the project's only manifest is a pyproject that declares
     * neither a package nor any dependencies.
     *
     * `pip install .` on such a file is the wrong manifest, not a missing one:
     * no distribution to build and no dependency list to install.
     *
     * A `setup.py` or `setup.cfg` beside the pyproject is metadata this reader
     * cannot see, so the project is never judged from the pyproject alone.
     *
     * @param array<string, true> $files
     */
    public static function declaresNothingToInstall(array $files, ?string $pyproject): bool
    {
        if ($pyproject === null
            || isset($files['setup.py'])
            || isset($files['setup.cfg'])
        ) {
            return false;
        }

        // The same question {@see declaresProject()} answers: "declares
        // nothing" is exactly "declares no project".
        return !self::declaresProject($pyproject);
    }

    /**
     * Whether a pyproject.toml is a Python *project* manifest, or a TOML file
     * some tool keeps its own settings in.
     *
     * The answer is either `[project]` (PEP 621, what modern Poetry,
     * hatchling, flit, pdm, setuptools and maturin projects have) or
     * `[tool.poetry]`. A file with neither is configuration for a tool in the
     * Python ecosystem — codespell, ruff, sqlfluff, pytest — and is not
     * evidence the application is Python: Dolibarr has six `[tool.*]` tables
     * and no `[project]`, Composio only `[tool.uv.*]`.
     *
     * `[build-system]` is NOT consulted: Dolibarr has one with
     * `setuptools.build_meta`, so accepting a Python build backend would still
     * misdetect the repository this exists to fix.
     */
    public static function declaresProject(?string $pyproject): bool
    {
        return $pyproject !== null
            && (self::hasTable($pyproject, 'project') || self::hasTable($pyproject, 'tool.poetry'));
    }

    /**
     * Whether a TOML document has exactly this table, not one whose name
     * merely starts with it: `[project.scripts]` is not `[project]`.
     */
    private static function hasTable(string $toml, string $table): bool
    {
        // TOML allows a comment after a table header, and a project that
        // writes `[project]  # the app` must not be read as declaring nothing.
        return preg_match(
            '/^[ \t]*\[' . preg_quote($table, '/') . '\][ \t]*(?:#.*)?\r?$/m',
            $toml
        ) === 1;
    }

    /**
     * Whether this pyproject describes a Poetry application, not a distributable
     * package.
     *
     * `package-mode = false` is Poetry's switch for "this is a program": there
     * is no importable package to build, so pip cannot install the project.
     * Looked for only under `[tool.poetry]`.
     */
    public static function isNonPackagePoetry(?string $pyproject): bool
    {
        if ($pyproject === null) {
            return false;
        }
        // Poetry's boolean spellings, and only inside [tool.poetry]: the key
        // must be seen after that table opens and before the next one does.
        if (preg_match('/^\[tool\.poetry\]\s*$(.*?)(?=^\[|\z)/ms', $pyproject, $section) !== 1) {
            return false;
        }

        return preg_match('/^\s*package-mode\s*=\s*false\s*$/mi', $section[1]) === 1;
    }

    /** The venv's interpreter, which is what every Python command must run under. */
    public static function python(): string
    {
        return self::VENV . '/bin/python';
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
     * Modules a declared server imports: the root search takes every
     * conventional entry point. The nested search is narrower — there, `app.py`
     * beside a manage.py is a Django application module, not a server target.
     *
     * @var list<string>
     */
    private const WSGI_MODULES = ['wsgi.py', 'asgi.py'];

    /**
     * A declared WSGI or ASGI server wins over running the module directly: a
     * Flask quickstart's `app.py` ends in a bare `app.run()` binding
     * 127.0.0.1:5000, so the container comes up, stays up, and answers nothing
     * on the port the recipe published.
     *
     * @param list<string> $manifests contents of requirements.txt and friends
     */
    public static function startCommand(string $projectDir, array $manifests = []): string
    {
        $projectDir = rtrim($projectDir, '/');
        $server = self::declaredServer($manifests);

        // 1. An application object at the root a declared server can import.
        //    The root is where a project states itself, so it is consulted
        //    before anything further down the tree.
        $root = self::applicationModule($projectDir, self::ENTRYPOINTS);
        if ($root !== null && $server !== null) {
            return self::serverCommand($server, $root[0], $root[1], '');
        }

        // 2. A root entry point runnable as a script.
        foreach (self::ENTRYPOINTS as $file) {
            if (!is_file($projectDir . '/' . $file)) {
                continue;
            }
            // A file called wsgi.py or asgi.py is a hand-off to a server, not
            // a program. Running it imports the module and exits, and the
            // account crash-loops with nothing bound. Only run one when it
            // says it is also a script.
            if (self::isServerHandoff($file) && !self::hasMainGuard($projectDir . '/' . $file)) {
                continue;
            }

            return self::python() . ' ' . $file;
        }

        // 3. A Django project laid out in a subdirectory -- NetBox, issue
        //    #414 -- and a declared server to run it. Only reached when the
        //    root offered nothing.
        if ($server !== null) {
            $nested = self::nestedServableModule($projectDir);
            if ($nested !== null) {
                return self::serverCommand($server, $nested[0], $nested[1], $nested[2]);
            }
        }

        return self::lastResortServer();
    }

    /**
     * A declared WSGI or ASGI server importing `module:callable`.
     *
     * The search-path flag appears only when the module is not importable from
     * the working directory — a Django project whose package sits below the
     * root.
     */
    private static function serverCommand(string $server, string $module, string $callable, string $searchPath): string
    {
        if ($server === 'uvicorn') {
            return self::VENV . '/bin/uvicorn'
                . self::searchPathOption('uvicorn', $searchPath)
                . ' ' . $module . ':' . $callable
                . ' --host 0.0.0.0 --port ' . self::PORT;
        }

        // --bind, not the default 127.0.0.1:8000: the listener has to be
        // reachable from outside the container.
        return self::VENV . '/bin/gunicorn --bind 0.0.0.0:' . self::PORT
            . ' --timeout 120' . self::searchPathOption('gunicorn', $searchPath)
            . ' ' . $module . ':' . $callable;
    }

    /** `wsgi.py` and `asgi.py` name a callable for a server to import. */
    private static function isServerHandoff(string $file): bool
    {
        return $file === 'wsgi.py' || $file === 'asgi.py';
    }

    private static function hasMainGuard(string $path): bool
    {
        $contents = @file_get_contents($path);

        return is_string($contents)
            && preg_match('/^if\s+__name__\s*==/m', $contents) === 1;
    }

    /**
     * Nothing in this repository is runnable, and the container still has to
     * come up -- an account that answers is easier to diagnose than one that
     * crash-loops with nothing in the log.
     *
     * It must not publish the checkout: `python -m http.server` in the project
     * directory serves every file, with indexes. An InvenTree deploy landed
     * here and answered 200 on `/.git/config` over a public name.
     *
     * So it serves one generated page out of an otherwise empty directory, and
     * `not-placeholder` recognises the page for what it is instead of the
     * deploy reporting itself healthy.
     */
    private static function lastResortServer(): string
    {
        $dir = self::PLACEHOLDER_DIR;
        // The title is the detection contract not-placeholder matches on: a
        // page with a title of its own answered 200 and was reported healthy.
        $page = '<!doctype html><title>' . PlaceholderPage::NOT_CONFIGURED_TITLE . '</title>'
            . '<h1>This application did not start</h1>'
            . '<p>PanelAlpha found no Python entry point in this repository, so nothing is running. '
            . 'Name the entry point in a panelalpha.yaml, or add one of: '
            . implode(', ', self::ENTRYPOINTS) . '.</p>';

        return 'mkdir -p ' . $dir
            . " && printf '%s' " . escapeshellarg($page) . ' > ' . $dir . '/index.html'
            . ' && ' . self::python() . ' -m http.server ' . self::PORT . ' --directory ' . $dir;
    }

    /**
     * gunicorn first: a project listing both is normally serving a WSGI app
     * through gunicorn with a uvicorn worker class, and gunicorn takes the bind
     * address.
     *
     * @param list<string> $manifests
     */
    private static function declaredServer(array $manifests): ?string
    {
        $text = strtolower(implode("\n", array_filter($manifests, 'is_string')));
        foreach (['gunicorn', 'uvicorn'] as $server) {
            if (preg_match('/(?<![a-z0-9_.-])' . $server . '(?![a-z0-9_.-])/', $text) === 1) {
                return $server;
            }
        }

        return null;
    }

    /**
     * A Django project laid out below the project root, as
     * [module, callable, relative search path], or null when there is none.
     *
     * Only reached when nothing at the root is runnable, and only for a project
     * with a declared server: a WSGI module cannot be run as a script, so
     * naming one without gunicorn or uvicorn would crash-loop the account.
     *
     * `netbox/manage.py` beside `netbox/netbox/wsgi.py` gives module
     * `netbox.wsgi` with `netbox/` on the import path, exactly what NetBox's
     * own systemd unit passes (`--pythonpath /opt/netbox/netbox netbox.wsgi`).
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private static function nestedServableModule(string $projectDir): ?array
    {
        foreach (self::djangoProjectLayout($projectDir) as $module) {
            $contents = @file_get_contents($projectDir . '/' . $module[2]);
            if (!is_string($contents)) {
                continue;
            }
            foreach (['application', 'app'] as $callable) {
                if (preg_match('/^' . $callable . '\s*=/m', $contents) === 1) {
                    return [$module[0], $callable, $module[1]];
                }
            }
        }

        return null;
    }

    /**
     * The WSGI/ASGI modules of every Django project in the checkout, as
     * [module, search path, file] triples, the outermost project first.
     *
     * Both `django-admin startproject` shapes are found because both are
     * anchored the same way: the package directory holding `wsgi.py` sits
     * beside the `manage.py` that runs the project. At the root that is
     * `manage.py` beside `NAME/wsgi.py` (module `NAME.wsgi`); run inside a
     * subdirectory — NetBox, issue #414 — it is `netbox/manage.py` beside
     * `netbox/netbox/wsgi.py` (module `netbox.wsgi`, needing `netbox/` on the
     * import path).
     *
     * Anchoring on `manage.py` keeps this from picking up a `wsgi.py`
     * vendored in some library's tree: Django never writes one without its
     * manage.py next door.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function djangoProjectLayout(string $projectDir): array
    {
        $candidates = [];
        foreach (self::managePyDirs($projectDir) as $relative) {
            $projectDirOf = $relative === '' ? $projectDir : $projectDir . '/' . $relative;
            foreach (self::WSGI_MODULES as $module) {
                foreach (scandir($projectDirOf) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..' || $entry[0] === '.' || self::isNoiseDir($entry)) {
                        continue;
                    }
                    // `NAME/wsgi.py`, a package beside the manage.py.
                    if (!is_file($projectDirOf . '/' . $entry . '/' . $module)
                        || !is_file($projectDirOf . '/' . $entry . '/__init__.py')) {
                        continue;
                    }
                    $package = $relative === '' ? $entry : $relative . '/' . $entry;
                    $candidates[] = [
                        basename($entry) . '.' . basename($module, '.py'),
                        $relative,
                        $package . '/' . $module,
                    ];
                }
            }
        }

        return $candidates;
    }

    /**
     * The directory holding the project's `manage.py`, relative to the project
     * root — `''` when it is the root itself — or null when there is none.
     *
     * Asked by the `django` probe and by the Django recipe's commands, so
     * detection and the commands cannot disagree about a layout.
     */
    public static function djangoManageDir(string $projectDir): ?string
    {
        return self::managePyDirs(rtrim($projectDir, '/'))[0] ?? null;
    }

    /**
     * A `manage.py` invocation that works wherever the file actually sits.
     *
     * Addressed by path from the project root, not with a `cd`: the virtualenv
     * is at the root and `.venv/bin/python` would not be found from inside
     * `netbox/`. Python puts the script's own directory on `sys.path[0]`, so
     * `.venv/bin/python netbox/manage.py` resolves `netbox.settings` as running
     * it from inside `netbox/` would — the same reasoning as the `--pythonpath`
     * {@see nestedServableModule()} hands a declared server.
     */
    public static function manageCommand(string $projectDir, string $arguments): string
    {
        $dir = self::djangoManageDir($projectDir);
        $script = $dir === null || $dir === '' ? 'manage.py' : $dir . '/manage.py';

        // Quoted only when needed: the directory name comes off the filesystem
        // and can hold a space or a metacharacter, but quoting the ordinary
        // case would put `'manage.py'` in every Django deploy log.
        return self::python() . ' '
            . (preg_match('#^[A-Za-z0-9._/-]+$#', $script) === 1 ? $script : escapeshellarg($script))
            . ' ' . $arguments;
    }

    /**
     * How a Django project is served: a WSGI/ASGI server it declared, run
     * against the project's own module, and `manage.py runserver` only when it
     * declared none.
     *
     * `runserver` is Django's development server and says so on every boot.
     *
     * @param list<string> $manifests contents of requirements.txt and friends
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

        return self::manageCommand($projectDir, 'runserver 0.0.0.0:' . self::PORT);
    }

    /**
     * Whether a file called `manage.py` is Django's.
     *
     * The name alone is not evidence: bitcart/bitcart ships
     * `api/views/manage.py`, a FastAPI *router* sharing nothing with Django but
     * the filename. Detection keyed on the name claimed the repo as Django and
     * emitted `migrate` and `runserver`; the ASGI app was never started.
     *
     * Two signals, either enough: the file carries `DJANGO_SETTINGS_MODULE`,
     * `execute_from_command_line` or a django import; or a `wsgi.py`/`asgi.py`
     * sits in the package beside it, the shape `django-admin startproject`
     * writes.
     *
     * The second exists because the first can be missing — a manage.py trimmed
     * to a shebang is still Django if its project is shaped like one. It is not
     * a loophole for bitcart, which has neither file anywhere.
     */
    private static function isDjangoManagePy(string $dir): bool
    {
        $contents = @file_get_contents($dir . '/manage.py');
        if (is_string($contents) && $contents !== ''
            && preg_match(
                '/DJANGO_SETTINGS_MODULE|execute_from_command_line|from\s+django\b|import\s+django\b/',
                $contents
            ) === 1
        ) {
            return true;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '.' || self::isNoiseDir($entry)) {
                continue;
            }
            $package = $dir . '/' . $entry;
            if (!is_file($package . '/__init__.py')) {
                continue;
            }
            foreach (self::WSGI_MODULES as $module) {
                if (is_file($package . '/' . $module)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Directories holding a *Django* `manage.py`, relative to the project
     * root, the root itself first. Bounded to three levels: a Django project
     * does not keep its manage.py four levels down.
     *
     * @return list<string>
     */
    private static function managePyDirs(string $projectDir, string $relative = '', int $depth = 0): array
    {
        $dirs = [];
        $dir = $relative === '' ? $projectDir : $projectDir . '/' . $relative;
        if (is_file($dir . '/manage.py') && self::isDjangoManagePy($dir)) {
            $dirs[] = $relative;
        }
        if ($depth >= 3) {
            return $dirs;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '.' || self::isNoiseDir($entry)) {
                continue;
            }
            if (!is_dir($dir . '/' . $entry)) {
                continue;
            }
            $child = $relative === '' ? $entry : $relative . '/' . $entry;
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
     * @param list<string> $files basenames to look for, in order
     * @return array{0: string, 1: string}|null module and callable name
     */
    private static function applicationModule(string $dir, array $files): ?array
    {
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            $contents = @file_get_contents($path);
            if (!is_string($contents)) {
                continue;
            }
            foreach (['app', 'application'] as $name) {
                if (preg_match('/^' . $name . '\s*=/m', $contents) === 1) {
                    return [substr($file, 0, -3), $name];
                }
            }
        }

        return null;
    }

    /**
     * The server-specific flag that puts the project's package directory on the
     * import path, or '' when the module is importable as it is.
     *
     * gunicorn spells it `--pythonpath`, uvicorn `--app-dir`. Without it the
     * nested module cannot be imported and gunicorn exits with
     * `ModuleNotFoundError`.
     */
    private static function searchPathOption(string $server, string $searchPath): string
    {
        if ($searchPath === '') {
            return '';
        }

        $flag = $server === 'uvicorn' ? '--app-dir' : '--pythonpath';

        return " {$flag} '" . $searchPath . "'";
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
