<?php

namespace App\Lib\Deploy\Platform;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Credentials\CredentialSpec;
use App\Lib\Deploy\Health\CheckRegistry;
use App\Lib\Deploy\Platform\Runtime\Requirement;
use App\Lib\Deploy\Platform\Runtime\Php\PhpDocroot;

/**
 * One platform or app, as declared by a YAML file under `resources/platforms/` or
 * `resources/apps/<id>/panelalpha.yaml`: how to recognise it, its image, its port, and the
 * ordered commands that build, install, upgrade and start it. A new stack is a new YAML file.
 */
final class PlatformManifest
{
    public const RUNTIME_NODE = 'node';
    public const RUNTIME_NGINX = 'nginx';
    public const RUNTIME_COMMAND = 'command';
    public const RUNTIME_PHP = 'php';
    public const RUNTIME_COMPOSE = 'compose';
    public const RUNTIME_DOCKERFILE = 'dockerfile';

    private const RUNTIMES = [
        self::RUNTIME_NODE,
        self::RUNTIME_NGINX,
        self::RUNTIME_COMMAND,
        self::RUNTIME_PHP,
        self::RUNTIME_COMPOSE,
        self::RUNTIME_DOCKERFILE,
    ];

    /**
     * Every key a manifest may set.
     *
     * @var list<string>
     */
    public const KNOWN_KEYS = [
        '$schema', 'id', 'strategy', 'label', 'priority', 'runtime', 'port', 'port_scheme',
        'image', 'runtime_image', 'requires', 'output_directory', 'output_from',
        'commands_from', 'env', 'detect', 'extra', 'commands', 'database',
        'app_root',
        'docroot',
        'check',
        'check_skip',
        'build_args',
        'system_packages',
        'frontend_build',
        'credentials',
    ];

    /** Databases a manifest can ask the engine to provision. */
    public const DATABASES = ['mysql'];

    /** What the app's port speaks; the account's :80/:443 rules proxy to it with this. */
    public const PORT_SCHEMES = ['http', 'https'];

    /**
     * @param array<string, mixed> $detect
     * @param list<PlatformCommand> $commands
     * @param array<string, string> $env
     * @param array<string, mixed> $extra
     */
    private function __construct(
        public readonly string $id,
        public readonly string $strategy,
        public readonly string $label,
        public readonly int $priority,
        public readonly string $runtime,
        public readonly ?int $port,
        public readonly ?string $portScheme,
        public readonly ?string $image,
        public readonly array $requires,
        /**
         * A smaller base for the final stage, when building and running want different
         * images: .NET's SDK compiles, its aspnet runtime runs, and shipping the SDK cost
         * 294s of layer export against 63s of compile. `{version}` is substituted.
         */
        public readonly ?string $runtimeImage,
        public readonly ?string $outputDirectory,
        public readonly ?string $outputFrom,
        public readonly array $commandsFrom,
        public readonly ?string $database,
        /**
         * The application's own root inside the repository, '' when they are the same
         * directory. phpBB ships its application in phpBB/ with only build tooling at the
         * top level, and everything below would otherwise be invisible.
         */
        public readonly string $appRoot,
        public readonly string $docroot,
        /**
         * `--build-arg` values a repository's own Dockerfile needs, keyed by arg name.
         * Not `--target`: a target stops the build there, so Kimai's `apache` stage would
         * have no `/opt/kimai` in it, while its `ARG BASE` reaches later
         * `FROM ${BASE}-base` stages. Values are literals, never templates.
         *
         * @var array<string, string>
         */
        public readonly array $buildArgs,
        /**
         * Debian packages baked into this app's own PHP base variant — ffmpeg for a video
         * site. Allowlisted by `PhpBaseImage::SYSTEM_PACKAGES`; `runtime: php` only.
         *
         * @var list<string>
         */
        public readonly array $systemPackages,
        /**
         * The PHP host frontend pass: null runs package.json's `build` script when it
         * has one, false never runs the pass, a command replaces that script and runs
         * even when package.json declares none. `runtime: php` only.
         */
        public readonly string|false|null $frontendBuild,
        /**
         * The login the application is seeded with: the engine generates the
         * values, keeps them on the project and writes them into the account.
         */
        public readonly ?CredentialSpec $credentials,
        public readonly array $detect,
        /**
         * Health checks this manifest adds to the ones its runtime brings, as `<group>`
         * or `<group>/<id>` references resolved by `CheckRegistry`. Additions only: a
         * manifest never names its own runtime's group.
         *
         * @var list<string>
         */
        public readonly array $checks,
        /**
         * Shipped checks this application answers wrongly by design, each with the
         * reason, reported as skipped rather than run. Apaxy's product is a
         * directory listing, which `_baseline/no-directory-listing` exists to fail.
         *
         * @var list<array{check: string, reason: string, covered_by: ?string}>
         */
        public readonly array $checkSkips,
        public readonly array $commands,
        public readonly array $env,
        public readonly array $extra,
        public readonly string $source
    ) {
    }

    /** Is this a runtime the engine knows how to build for? */
    public static function isRuntime(string $runtime): bool
    {
        return in_array($runtime, self::RUNTIMES, true);
    }

    /** @return list<string> */
    public static function runtimes(): array
    {
        return self::RUNTIMES;
    }

    /**
     * @param array<string, mixed> $raw decoded manifest JSON
     * @param bool $requireDetect false for a recipe that is looked up rather
     *        than detected — {@see SourceRecipes}, where the path the file
     *        sits at is the rule and a `detect` block would be a second,
     *        contradictable answer to a question already settled
     * @throws ManifestException
     */
    public static function fromArray(
        array $raw,
        string $source = '<inline>',
        bool $requireDetect = true,
        ?string $recipeChecks = null
    ): self {
        $reader = new ManifestReader($raw, $source);
        $reader->assertNoUnknownKeys(self::KNOWN_KEYS);

        $id = $reader->identifier('id');
        // Errors from here on name the manifest rather than its file: what it calls itself
        // is what the author looks for.
        $reader->nameErrorsAfter($id);

        return new self(
            $id,
            // Two manifests can describe one strategy: an Astro project builds to static
            // HTML or to a Node server depending on its config — different detect rules,
            // runtimes and commands, but the same 'astro' identity downstream.
            $reader->identifier('strategy', $id),
            $reader->text('label'),
            $reader->integer('priority', 'must be an integer — higher is checked first'),
            $runtime = $reader->enum('runtime', self::RUNTIMES, self::RUNTIME_COMMAND),
            $reader->port('port'),
            $reader->optionalEnum('port_scheme', self::PORT_SCHEMES),
            $reader->optionalText('image', 'must be a non-empty string when present'),
            self::readRequires($reader),
            $reader->optionalText('runtime_image', 'must be a non-empty string when present'),
            $reader->optionalText('output_directory', 'must be a non-empty string when present'),
            $reader->optionalText('output_from', 'must be a resolver name when present'),
            self::readCommandResolvers($reader),
            $reader->optionalEnum('database', self::DATABASES),
            self::readAppRoot($reader),
            self::readDocroot($reader),
            self::readBuildArgs($reader),
            self::readSystemPackages($reader),
            self::readFrontendBuild($reader),
            CredentialSpec::parse($reader->raw('credentials'), $reader->fail(...)),
            $reader->object('detect', 'must be a non-empty condition object', $requireDetect),
            $checks = self::readChecks($reader, $recipeChecks),
            self::readCheckSkips($reader, $runtime, $checks, $recipeChecks),
            self::readCommands($reader),
            self::readEnv($reader),
            $reader->passthrough('extra'),
            $source
        );
    }

    /**
     * A manifest's `check:` list, as `<group>` or `<group>/<id>` references resolved by
     * `CheckRegistry`; a single string is accepted for the common case of adding one.
     *
     * Validated at load, not at use: `AppHealth::runChecks()` swallows the throw from an
     * unknown reference and reports `serving: unknown, checks: []`.
     *
     * @return list<string>
     * @throws ManifestException
     */
    private static function readChecks(ManifestReader $reader, ?string $recipeChecks = null): array
    {
        if (!$reader->has('check')) {
            return [];
        }

        $raw = $reader->raw('check');
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            throw $reader->fail("check must be a check reference or a list of them");
        }

        $checks = [];
        foreach ($raw as $reference) {
            // The group may begin with `_`: the baseline group every application is asked
            // is `_baseline`. The id stays a plain lowercase slug, as HealthCheck enforces.
            if (!is_string($reference) || preg_match('#^[a-z0-9_][a-z0-9_-]*(/[a-z0-9][a-z0-9-]*)?$#', $reference) !== 1) {
                throw $reader->fail("check entries must be 'group' or 'group/id'");
            }
            if (in_array($reference, $checks, true)) {
                continue;
            }
            self::assertCheckReference($reference, $reader, $recipeChecks);
            $checks[] = $reference;
        }

        return $checks;
    }

    /**
     * Refuse a reference no check answers to, at load rather than at health-check time.
     *
     * Loaded rather than resolved through `CheckRegistry::for()`, which also needs a
     * runtime group. The registry caches, so this is what the resolution costs anyway.
     *
     * @throws ManifestException
     */
    private static function assertCheckReference(
        string $reference,
        ManifestReader $reader,
        ?string $recipeChecks = null
    ): void {
        // A recipe's own check is not in `resources/checks/`, so it is asked about first.
        // Whether the file is well-formed is the registry's finding, reported once.
        if (CheckRegistry::recipeReferences($recipeChecks, [$reference]) !== []) {
            return;
        }

        try {
            $groups = CheckRegistry::all();
        } catch (\Throwable $e) {
            // A malformed shipped check is a packaging bug in its own file,
            // reported where it lives. Refusing every manifest that mentions a
            // check would bury that under a tree-wide failure.
            return;
        }

        [$group, $id] = str_contains($reference, '/')
            ? explode('/', $reference, 2)
            : [$reference, null];

        if (!isset($groups[$group])) {
            throw $reader->fail(
                "check '{$reference}': no group '{$group}'; the groups are " . implode(', ', array_keys($groups))
            );
        }
        if ($id === null) {
            return;
        }
        foreach ($groups[$group] as $check) {
            if ($check->id === $id) {
                return;
            }
        }

        throw $reader->fail("check '{$reference}': group '{$group}' has no check '{$id}'");
    }

    /**
     * `check_skip`: shipped checks this application is asked and answers wrongly
     * by design, e.g. `{check: _baseline/no-directory-listing, reason: "..."}`.
     *
     * Refused here rather than ignored at health time: an unknown or unasked
     * check, a missing reason, or an `error` check waived with no `covered_by`
     * naming the `error` check that asks the question instead. Without that
     * last rule a skip could hide an outage.
     *
     * @param list<string> $checks this manifest's own `check:` list
     * @return list<array{check: string, reason: string, covered_by: ?string}>
     * @throws ManifestException
     */
    private static function readCheckSkips(
        ManifestReader $reader,
        string $runtime,
        array $checks,
        ?string $recipeChecks
    ): array {
        $raw = $reader->raw('check_skip');
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            throw $reader->fail('check_skip must be a list of {check, reason} entries');
        }

        // The checks this manifest is asked, by reference, and which of them ship.
        // A registry that cannot load is reported by its own file, as in
        // assertCheckReference(); the runner still refuses an uncovered error skip.
        try {
            $asked = [];
            foreach (CheckRegistry::for($runtime, $checks, $recipeChecks) as $check) {
                $asked[$check->reference()] = $check;
            }
            $shipped = [];
            foreach (CheckRegistry::all() as $group) {
                foreach ($group as $check) {
                    $shipped[$check->reference()] = true;
                }
            }
        } catch (\Throwable $e) {
            $asked = null;
            $shipped = null;
        }

        $skips = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || array_is_list($entry) || array_diff(array_keys($entry), ['check', 'reason', 'covered_by']) !== []) {
                throw $reader->fail('check_skip entries take check, reason and covered_by only');
            }
            $reference = $entry['check'] ?? null;
            if (!is_string($reference) || preg_match('#^[a-z0-9_][a-z0-9_-]*/[a-z0-9][a-z0-9-]*$#', $reference) !== 1) {
                throw $reader->fail("check_skip: 'check' must name one check as 'group/id'");
            }
            $reason = $entry['reason'] ?? null;
            if (!is_string($reason) || trim($reason) === '') {
                throw $reader->fail("check_skip '{$reference}': a reason is required, and it is shown in the health report");
            }
            $cover = $entry['covered_by'] ?? null;
            if ($cover !== null && (!is_string($cover) || preg_match('#^[a-z0-9_][a-z0-9_-]*/[a-z0-9][a-z0-9-]*$#', $cover) !== 1)) {
                throw $reader->fail("check_skip '{$reference}': covered_by must name one check as 'group/id'");
            }
            if (isset($skips[$reference])) {
                throw $reader->fail("check_skip '{$reference}' is listed twice");
            }
            $skips[$reference] = ['check' => $reference, 'reason' => trim($reason), 'covered_by' => $cover];
        }

        if ($asked !== null && $shipped !== null) {
            foreach ($skips as $reference => $skip) {
                if (!isset($shipped[$reference])) {
                    throw $reader->fail("check_skip '{$reference}': the engine ships no such check");
                }
                if (!isset($asked[$reference])) {
                    throw $reader->fail("check_skip '{$reference}': this manifest is never asked that check");
                }
                $cover = $skip['covered_by'];
                if ($cover !== null) {
                    if ($cover === $reference || isset($skips[$cover]) || !isset($asked[$cover])) {
                        throw $reader->fail(
                            "check_skip '{$reference}': covered_by '{$cover}' must be another check this manifest is asked and does not skip"
                        );
                    }
                }
                if ($asked[$reference]->severity === \App\Lib\Deploy\Health\HealthCheck::SEVERITY_ERROR
                    && ($cover === null || $asked[$cover]->severity !== \App\Lib\Deploy\Health\HealthCheck::SEVERITY_ERROR)) {
                    throw $reader->fail(
                        "check_skip '{$reference}' has severity error: name the error check that asks the question "
                        . 'instead in covered_by, or a skip could hide an outage'
                    );
                }
            }
        }

        return array_values($skips);
    }

    /**
     * `app_root`, normalised to '' or a single trailing-slash-free relative path.
     * Rejected if it escapes the repository: it is pasted into COPY sources.
     *
     * @throws ManifestException
     */
    private static function readAppRoot(ManifestReader $reader): string
    {
        $raw = $reader->optionalText('app_root', 'must be a non-empty string when present');
        if ($raw === null) {
            return '';
        }

        $value = trim($raw, '/');
        if ($value === '' || $value === '.') {
            return '';
        }
        foreach (explode('/', $value) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new ManifestException(
                    "app_root must be a relative path inside the repository, got '{$raw}'"
                );
            }
        }

        return $value;
    }

    /**
     * `docroot`, relative to the application root, normalised as `app_root` is and rejected
     * if it escapes: it becomes Apache's DocumentRoot, and '../' would serve the account's
     * home directory.
     *
     * An absent key is '' and the runtime probes for the root. `.` (or `/`) is a stated
     * answer, PhpDocroot::ROOT, so the probe cannot move it to a `public/` the
     * application does not serve from (OpenEMR).
     *
     * @throws ManifestException
     */
    private static function readDocroot(ManifestReader $reader): string
    {
        $raw = $reader->optionalText('docroot', 'must be a non-empty string when present');
        if ($raw === null) {
            return '';
        }

        $value = trim($raw, '/');
        if ($value === '' || $value === '.') {
            return PhpDocroot::ROOT;
        }
        foreach (explode('/', $value) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new ManifestException(
                    "docroot must be a relative path inside the application, got '{$raw}'"
                );
            }
        }

        return $value;
    }

    /**
     * `build_args`, a map of arg name => the literal value to pass.
     *
     * Values are strings on purpose: a template would have to be substituted from the
     * project on disk, which is not available where these are validated, and a
     * half-substituted value falls back to the Dockerfile's own default.
     *
     * @return array<string, string>
     * @throws ManifestException
     */
    private static function readBuildArgs(ManifestReader $reader): array
    {
        $declared = $reader->object('build_args', 'must be an object of arg name => value');
        $args = [];
        foreach ($declared as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw $reader->fail("'{$name}' is not a valid Dockerfile build-arg name");
            }
            if (!is_string($value) && !is_int($value)) {
                throw $reader->fail("build_args.{$name}: must be a string");
            }
            $args[$name] = (string) $value;
        }

        return $args;
    }

    /**
     * `system_packages`, a list of allowlisted Debian package names.
     *
     * Refused rather than dropped when unknown or on another runtime: a video site
     * that deploys green without ffmpeg accepts uploads it can never convert.
     *
     * @return list<string>
     * @throws ManifestException
     */
    private static function readSystemPackages(ManifestReader $reader): array
    {
        if (!$reader->has('system_packages') || $reader->raw('system_packages') === null) {
            return [];
        }
        $raw = $reader->raw('system_packages');
        if (!is_array($raw) || !array_is_list($raw)) {
            throw $reader->fail('system_packages must be a list of package names');
        }
        $allowed = PhpBaseImage::SYSTEM_PACKAGES;
        foreach ($raw as $name) {
            if (!is_string($name) || !in_array($name, $allowed, true)) {
                $shown = is_scalar($name) ? (string) $name : gettype($name);
                throw $reader->fail(
                    "system_packages: '{$shown}' is not one the engine can add; allowed: " . implode(', ', $allowed)
                );
            }
        }
        if ($raw !== [] && ($reader->raw('runtime') ?? self::RUNTIME_COMMAND) !== self::RUNTIME_PHP) {
            throw $reader->fail("system_packages is only supported for runtime 'php'");
        }

        return PhpBaseImage::normalizeSystemPackages($raw);
    }

    /**
     * `frontend_build`: false, or the command that replaces the `build` script.
     * `true` is refused: the pass already runs whenever there is a build script.
     *
     * @throws ManifestException
     */
    private static function readFrontendBuild(ManifestReader $reader): string|false|null
    {
        $raw = $reader->raw('frontend_build');
        if ($raw === null) {
            return null;
        }
        if ($raw !== false && (!is_string($raw) || trim($raw) === '')) {
            throw $reader->fail('frontend_build must be false or a non-empty command');
        }
        if (($reader->raw('runtime') ?? self::RUNTIME_COMMAND) !== self::RUNTIME_PHP) {
            throw $reader->fail("frontend_build is only supported for runtime 'php'");
        }

        return $raw === false ? false : trim($raw);
    }

    /**
     * Some commands are derived rather than declared: the Rust start command is the
     * binary name out of Cargo.toml, the Python one is whichever of app.py, main.py or
     * wsgi.py the project has. `commands_from` maps a command id to the resolver.
     *
     * @return array<string, string>
     * @throws ManifestException
     */
    private static function readCommandResolvers(ManifestReader $reader): array
    {
        $declared = $reader->object('commands_from', 'must be an object of command id => resolver');
        foreach ($declared as $commandId => $resolver) {
            if (!is_string($commandId) || !is_string($resolver) || trim($resolver) === '') {
                throw $reader->fail("'commands_from' entries must be command id => resolver name");
            }
        }

        return $declared;
    }

    /**
     * The toolchains this platform needs, and what each is for. A manifest names them and
     * never states a version: the runtime reads the project to answer.
     *
     * `role: build` is load-bearing — that toolchain gets its own build stage and stays out
     * of the runtime image.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function readRequires(ManifestReader $reader): array
    {
        $declared = $reader->object('requires', 'must be an object of runtime => role or spec');

        $requires = [];
        foreach ($declared as $runtimeId => $spec) {
            if (!is_string($runtimeId) || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $runtimeId)) {
                throw $reader->fail("'{$runtimeId}' is not a valid runtime name");
            }
            if (is_string($spec)) {
                $spec = ['role' => $spec];
            }
            if (!is_array($spec)) {
                throw $reader->fail("requires.{$runtimeId}: must be a role name or an object");
            }
            $role = $spec['role'] ?? Requirement::ROLE_RUNTIME;
            if (!in_array($role, [Requirement::ROLE_RUNTIME, Requirement::ROLE_BUILD], true)) {
                throw $reader->fail(
                    "requires.{$runtimeId}: 'role' must be '"
                    . Requirement::ROLE_RUNTIME . "' or '" . Requirement::ROLE_BUILD . "'"
                );
            }
            $unknown = array_diff(array_keys($spec), ['role', 'optional']);
            if ($unknown !== []) {
                throw $reader->fail("requires.{$runtimeId}: unknown key(s) " . implode(', ', $unknown));
            }
            $requires[$runtimeId] = ['role' => $role, 'optional' => (bool) ($spec['optional'] ?? false)];
        }

        return $requires;
    }

    /**
     * @return list<PlatformCommand>
     */
    private static function readCommands(ManifestReader $reader): array
    {
        $id = $reader->identifier('id');
        $commands = [];
        $seen = [];
        foreach (array_values($reader->object('commands', 'must be a list')) as $index => $entry) {
            if (!is_array($entry)) {
                throw $reader->fail("commands[{$index}]: must be an object");
            }
            $command = PlatformCommand::fromArray($entry, $id, $index);
            if (isset($seen[$command->id])) {
                throw $reader->fail("duplicate command id '{$command->id}'");
            }
            $seen[$command->id] = true;
            $commands[] = $command;
        }

        if (count(array_filter($commands, static fn (PlatformCommand $c): bool => $c->serve)) > 1) {
            throw $reader->fail("only one command may be marked 'serve'");
        }

        return $commands;
    }

    /**
     * @return array<string, string>
     */
    private static function readEnv(ManifestReader $reader): array
    {
        $declared = $reader->object('env', 'must be an object');

        $env = [];
        foreach ($declared as $key => $value) {
            if (!is_string($key) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                throw $reader->fail("'{$key}' is not a valid environment variable name");
            }
            if (!is_string($value) && !is_int($value) && !is_bool($value)) {
                throw $reader->fail("env.{$key}: must be a string, integer or boolean");
            }
            $env[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $env;
    }

    /**
     * Commands belonging to one stage, in manifest order, with the serve command last
     * regardless of where it was written.
     *
     * @return list<PlatformCommand>
     */
    public function stage(string $stage): array
    {
        $commands = array_values(array_filter(
            $this->commands,
            static fn (PlatformCommand $c): bool => $c->runsIn($stage)
        ));

        usort(
            $commands,
            static fn (PlatformCommand $a, PlatformCommand $b): int => ($a->serve <=> $b->serve)
        );

        return $commands;
    }

    /**
     * This manifest in the shape the deploy pipeline reads. `install_command`,
     * `build_command` and `start_command` are projections of the staged commands.
     *
     * @param array<string, mixed> $probeData what the probes found while this manifest
     *        was matched — the workspace a Next app lives in, the Dockerfile a repo
     *        shipped.
     * @return array<string, mixed>
     */
    public function describe(ProjectContext $context, array $probeData = []): array
    {
        $decision = array_merge(
            $this->baseDecision($context),
            // Probe output first, so a probe can supply what the manifest could not know
            // without overwriting the identity the manifest declared.
            $probeData,
            $this->extra,
            $this->identity()
        );

        // Last, because the resolvers need the probe output merged: the Next output
        // directory is relative to the workspace it found.
        return PlatformValues::apply($this, $context, $decision);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseDecision(ProjectContext $context): array
    {
        [$dependencies, $assets] = $this->buildCommands($context);

        return [
            'compose_path' => null,
            'dockerfile' => null,
            'port_hint' => $this->port,
            'port_scheme' => $this->portScheme,
            'runtime' => $this->runtime,
            'runtime_image' => $this->runtimeImage,
            'output_directory' => $this->outputDirectory,
            'package_manager' => null,
            'install_command' => implode(' && ', $dependencies),
            'build_command' => implode(' && ', $assets),
            'start_command' => $this->serveCommand()?->run ?? '',
            'env' => $this->env === [] ? null : $this->env,
            'static_index' => null,
            'image' => $this->image,
            'database' => $this->database,
            'app_root' => $this->appRoot,
            'docroot' => $this->docroot,
            'build_args' => $this->buildArgs,
            'system_packages' => $this->systemPackages,
            'frontend_build' => $this->frontendBuild,
            'credentials' => $this->credentials?->toArray(),
        ];
    }

    /**
     * What no probe and no `extra` block may overwrite.
     *
     * @return array<string, string>
     */
    private function identity(): array
    {
        return [
            'strategy' => $this->strategy,
            'label' => $this->label,
            'platform' => $this->id,
        ];
    }

    /**
     * Build-stage commands split by role: what installs dependencies, and what
     * compiles assets.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function buildCommands(ProjectContext $context): array
    {
        $dependencies = [];
        $assets = [];
        foreach (PlatformMatcher::applicable($this->stage(PlatformStage::BUILD), $context) as $command) {
            // `optional: true` means a failure is logged and the deploy goes on. Joining
            // the commands loses the flag, so it has to survive as part of the text —
            // otherwise php.yaml's optional post-autoload-dump fails the whole build.
            $run = $command->tolerantRun($command->run);
            if ($command->role === PlatformCommand::ROLE_DEPENDENCIES) {
                $dependencies[] = $run;
            } else {
                $assets[] = $run;
            }
        }

        return [$dependencies, $assets];
    }

    public function serveCommand(): ?PlatformCommand
    {
        foreach ($this->commands as $command) {
            if ($command->serve) {
                return $command;
            }
        }

        return $this->defaultServeCommand();
    }

    /**
     * The PHP runtime serves itself, from the manifest's `docroot:` and
     * PhpBaseImage::SERVE_PATH. Only for `php`.
     *
     * A command rather than null, because EntrypointWriter writes nothing without one and a
     * Laravel account would then get no key:generate and no migrate.
     */
    private function defaultServeCommand(): ?PlatformCommand
    {
        if ($this->runtime !== self::RUNTIME_PHP) {
            return null;
        }

        return PlatformCommand::fromArray([
            'id' => 'serve',
            'stage' => PlatformStage::START,
            'serve' => true,
            'run' => PhpBaseImage::SERVE_PATH,
            'description' => 'Apache, on the document root the manifest declared.',
        ], $this->id, count($this->commands));
    }
}
