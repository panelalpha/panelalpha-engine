<?php

namespace App\Lib\Deploy\Platform\AppConfig;

use App\Lib\Deploy\Credentials\CredentialSpec;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformCommand;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformStage;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * What one specific project says about how to host it — facts no platform manifest, which
 * describes a kind of application, can hold. `panelalpha.yaml` uses the manifest vocabulary
 * plus single-application keys; `panelalpha.md` is markdown with named fenced blocks.
 */
final class AppConfig
{
    /** The script the engine calls for user management and SSO. */
    public const APP_SCRIPT = PaemdPage::APP_SCRIPT;

    /** Written into the account's home, run, and removed, before the clone. */
    public const PRE_CHECK_SCRIPT = PaemdPage::PRE_CHECK_SCRIPT;

    /** Written into the project, run after the clone, before the build. */
    public const SETUP_SCRIPT = PaemdPage::SETUP_SCRIPT;

    public const YAML_FILENAME = 'panelalpha.yaml';
    public const MARKDOWN_FILENAME = PaemdPage::FILENAME;

    /** A compose file the app config supplies replaces whatever else is there. */
    public const COMPOSE_REPLACE = 'replace';

    /** …or layers on top of one the engine generated. */
    public const COMPOSE_OVERRIDE = 'override';

    /** `git: {history: full}`: the clone gets the whole history and every tag. */
    public const GIT_HISTORY_FULL = 'full';

    public const GIT_HISTORY_SHALLOW = 'shallow';

    /**
     * Keys this class reads itself; everything else it may hold is a manifest key,
     * spelled as in `resources/apps/<id>/panelalpha.yaml`.
     *
     * @var list<string>
     */
    private const OWN_KEYS = [
        '$schema', 'description', 'extends', 'env',
        'precheck', 'prepare', 'entrypoint', 'commands', 'files', 'app', 'compose', 'git',
    ];

    /** Names the shipped recipe this application is an instance of. */
    public const EXTENDS_KEY = 'extends';

    /**
     * @param list<array{path: string, contents: string}> $files
     * @param list<PlatformCommand> $commands
     * @param array<string, array<string, mixed>> $requires
     * @param array<string, string> $env
     */
    private function __construct(
        private readonly ?string $preCheck,
        private readonly ?string $setup,
        private readonly ?string $entrypoint,
        private readonly ?string $compose,
        private readonly string $composeMode,
        private readonly ?string $appScript,
        private readonly array $files,
        private readonly array $commands,
        private readonly array $requires,
        private readonly array $env,
        private readonly ?array $manifest,
        private readonly ?CredentialSpec $credentials,
        private readonly bool $fullGitHistory = false,
        private readonly ?string $portScheme = null,
    ) {
    }

    /**
     * The app config for a project, or null when neither the repository nor the
     * engine has anything to say about it.
     */
    public static function load(
        AppConfigSource $source,
        string $projectDir,
        ?string $gitUrl = null
    ): ?self {
        return AppConfigLocator::find($source, $projectDir, $gitUrl)[0];
    }

    /**
     * Parse by shape when the filename is not to hand: a markdown page opens with
     * prose or a heading, a YAML document does not. Only for callers with content
     * and no path — `load()` knows the filename and does not guess.
     */
    public static function fromContent(string $content): ?self
    {
        if (trim($content) === '') {
            return null;
        }

        return self::looksLikeYaml($content)
            ? self::fromYaml($content)
            : self::fromMarkdown($content);
    }

    public static function fromMarkdown(string $content): ?self
    {
        if (trim($content) === '') {
            return null;
        }

        $setup = PaemdPage::setupCommands($content);
        $compose = PaemdPage::compose($content);

        // A page may carry a block of the YAML format, parsed by the same code, so a
        // page and a `panelalpha.yaml` mean the same thing by the same words.
        $embedded = PaemdPage::config($content);
        $config = $embedded === null ? null : self::fromYaml($embedded);

        return new self(
            PaemdPage::preCheckCommands($content),
            $setup,
            $config?->entrypoint(),
            $compose ?? $config?->compose(),
            $compose !== null ? self::COMPOSE_REPLACE : ($config?->composeMode() ?? self::COMPOSE_REPLACE),
            PaemdPage::appScript($content),
            array_merge(PaemdPage::fileSnippets($content), $config?->files() ?? []),
            $config?->commands() ?? [],
            $config?->requires() ?? [],
            $config?->env() ?? [],
            $config?->manifest(),
            $config?->credentials(),
            $config?->fullGitHistory() ?? false,
            $config?->portScheme(),
        );
    }

    /**
     * @throws ManifestException on malformed YAML or an unknown key
     */
    public static function fromYaml(string $content): ?self
    {
        if (trim($content) === '') {
            return null;
        }

        try {
            $raw = Yaml::parse($content);
        } catch (ParseException $e) {
            throw new ManifestException(
                'Invalid YAML in ' . self::YAML_FILENAME . ': ' . $e->getMessage(),
                0,
                $e
            );
        }
        if (!is_array($raw)) {
            throw new ManifestException(self::YAML_FILENAME . ' does not contain a mapping');
        }

        self::assertNoUnknownKeys($raw);

        $compose = self::readComposeContent($raw);
        $mode = self::readComposeMode($raw);
        $setup = self::readScript($raw, 'prepare');

        return new self(
            self::readScript($raw, 'precheck'),
            $setup,
            self::readScript($raw, 'entrypoint'),
            $compose,
            $mode,
            self::readAppScript($raw),
            self::readFiles($raw),
            self::readCommands($raw),
            self::readRequires($raw),
            self::readEnv($raw),
            self::readManifest($raw),
            CredentialSpec::parse(
                $raw['credentials'] ?? null,
                static fn (string $m): ManifestException => new ManifestException(self::YAML_FILENAME . ": {$m}")
            ),
            self::readGitHistory($raw) === self::GIT_HISTORY_FULL,
            self::readPortScheme($raw),
        );
    }

    /**
     * A `.panelalpha/` directory: `panelalpha.yaml` for what is structured, a file per
     * script or blob beside it, the file winning over the same thing inline.
     *
     * @param array{
     *   config?: ?string, precheck?: ?string, prepare?: ?string,
     *   entrypoint?: ?string, app?: ?string, compose?: ?string,
     *   compose_mode?: string, files?: list<array{path: string, contents: string}>
     * } $parts
     * @throws ManifestException
     */
    public static function fromDirectory(array $parts): ?self
    {
        $raw = $parts['config'] ?? null;
        $config = is_string($raw) && trim($raw) !== '' ? self::fromYaml($raw) : null;
        $compose = $parts['compose'] ?? null;

        $appConfig = new self(
            $parts['precheck'] ?? $config?->preCheckCommands(),
            $parts['prepare'] ?? $config?->setupCommands(),
            $parts['entrypoint'] ?? $config?->entrypoint(),
            $compose ?? $config?->compose(),
            $compose !== null
                ? ($parts['compose_mode'] ?? self::COMPOSE_REPLACE)
                : ($config?->composeMode() ?? self::COMPOSE_REPLACE),
            $parts['app'] ?? $config?->appScript(),
            self::mergeFiles($parts['files'] ?? [], $config?->files() ?? []),
            $config?->commands() ?? [],
            $config?->requires() ?? [],
            $config?->env() ?? [],
            $config?->manifest(),
            $config?->credentials(),
            $config?->fullGitHistory() ?? false,
            $config?->portScheme(),
        );

        return $appConfig->isEmpty() ? null : $appConfig;
    }

    /**
     * Files from the directory, then any the config declares that it did not already
     * provide — same rule as the scripts: the file wins.
     *
     * @param list<array{path: string, contents: string}> $files
     * @param list<array{path: string, contents: string}> $inline
     * @return list<array{path: string, contents: string}>
     */
    private static function mergeFiles(array $files, array $inline): array
    {
        $paths = array_column($files, 'path');
        foreach ($inline as $entry) {
            if (!in_array($entry['path'], $paths, true)) {
                $files[] = $entry;
            }
        }

        return $files;
    }

    /** Nothing to say: an empty directory is no app config at all. */
    public function isEmpty(): bool
    {
        return $this->preCheck === null
            && $this->setup === null
            && $this->entrypoint === null
            && $this->compose === null
            && $this->appScript === null
            && $this->files === []
            && $this->commands === []
            && $this->requires === []
            && $this->env === []
            && $this->manifest === null
            && $this->credentials === null
            && !$this->fullGitHistory
            && $this->portScheme === null;
    }

    /**
     * Validation to run before the clone — a disk-space check, a licence probe.
     * Runs on the account: at this point there is no application container.
     */
    public function preCheckCommands(): ?string
    {
        return $this->preCheck;
    }

    /**
     * Setup to run after the clone and before the build, on the account.
     *
     * Distinct from the manifest's `install` stage, which runs inside the container
     * on first boot: an app config generating a compose file must do it here, where
     * there is not yet an image to run.
     */
    public function setupCommands(): ?string
    {
        return $this->setup;
    }

    /** A compose file the app config supplies for the project. */
    public function compose(): ?string
    {
        return $this->compose;
    }

    /** Whether that compose file replaces the project's or layers over it. */
    public function composeMode(): string
    {
        return $this->composeMode;
    }

    /**
     * The compose file a deploy writes into the checkout, where detection
     * reads it ahead of the repository's own. Null in override mode, which
     * is layered over the run file outside the checkout.
     */
    public function replacingCompose(): ?string
    {
        return $this->composeMode === self::COMPOSE_OVERRIDE ? null : $this->compose;
    }

    /** The user-management script, if this project has one. */
    public function appScript(): ?string
    {
        return $this->appScript;
    }

    /**
     * The project's own entrypoint, replacing the generated one outright.
     * Written into the checkout as `.panelalpha/entrypoint.sh`.
     */
    public function entrypoint(): ?string
    {
        return $this->entrypoint;
    }

    /**
     * The manifest this file describes, when it describes one: `extends:` naming a
     * shipped recipe, manifest keys of its own, or both — the keys overriding the
     * recipe they extend.
     *
     * @return ?array<string, mixed>
     */
    public function manifest(): ?array
    {
        return $this->manifest;
    }

    /**
     * Files copied verbatim into the project tree.
     *
     * @return list<array{path: string, contents: string}>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * Staged commands, in the same vocabulary a manifest uses.
     *
     * YAML only: markdown has one script per lifecycle point and no way to name,
     * guard or stage them individually.
     *
     * @return list<PlatformCommand>
     */
    public function commands(?string $stage = null): array
    {
        if ($stage === null) {
            return $this->commands;
        }

        return array_values(array_filter(
            $this->commands,
            static fn (PlatformCommand $c): bool => $c->runsIn($stage)
        ));
    }

    /**
     * The app config's own entrypoint, when it declares one: a `serve: true` command
     * in the start stage. It replaces the platform's entrypoint, because two
     * processes fighting for one port is not a thing anyone asked for.
     */
    public function serveCommand(): ?PlatformCommand
    {
        foreach ($this->commands(PlatformStage::START) as $command) {
            if ($command->serve) {
                return $command;
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> */
    public function requires(): array
    {
        return $this->requires;
    }

    /** @return array<string, string> */
    public function env(): array
    {
        return $this->env;
    }

    /**
     * The login this application is seeded with. Read here as well as in the
     * manifest: it is delivered before the prepare hook, ahead of detection.
     */
    public function credentials(): ?CredentialSpec
    {
        return $this->credentials;
    }

    /**
     * Whether the deploy clone needs the whole history and every tag: a build
     * that stamps its version with `git describe` fails on the default
     * depth-1 clone.
     */
    public function fullGitHistory(): bool
    {
        return $this->fullGitHistory;
    }

    /**
     * `port_scheme`: what the app's port speaks. Read here as well as in the
     * manifest, since a compose recipe declares it without being a manifest.
     */
    public function portScheme(): ?string
    {
        return $this->portScheme;
    }

    // -- YAML reading ------------------------------------------------------

    /**
     * Everything this file may say: its own keys, plus the manifest's.
     *
     * @return list<string>
     */
    public static function knownKeys(): array
    {
        return array_values(array_unique(array_merge(self::OWN_KEYS, PlatformManifest::KNOWN_KEYS)));
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function assertNoUnknownKeys(array $raw): void
    {
        $known = self::knownKeys();
        $unknown = array_diff(array_keys($raw), $known);
        if ($unknown !== []) {
            sort($known);
            throw new ManifestException(
                self::YAML_FILENAME . ': unknown key(s) ' . implode(', ', $unknown)
                . '; expected one of ' . implode(', ', $known)
            );
        }
    }

    /**
     * `precheck:` / `prepare:` as a bare script, for an app config that has one thing
     * to say and no reason to name it.
     *
     * @param array<string, mixed> $raw
     */
    private static function readScript(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new ManifestException(self::YAML_FILENAME . ": '{$key}' must be a non-empty script");
        }

        return $value;
    }

    /** @param array<string, mixed> $raw */
    private static function readPortScheme(array $raw): ?string
    {
        $scheme = $raw['port_scheme'] ?? null;
        if ($scheme !== null && !in_array($scheme, PlatformManifest::PORT_SCHEMES, true)) {
            throw new ManifestException(
                self::YAML_FILENAME . ": 'port_scheme' must be one of " . implode(', ', PlatformManifest::PORT_SCHEMES)
            );
        }

        return $scheme;
    }

    /** @param array<string, mixed> $raw */
    private static function readComposeContent(array $raw): ?string
    {
        $compose = $raw['compose'] ?? null;
        if ($compose === null) {
            return null;
        }
        if (is_string($compose)) {
            return trim($compose) === '' ? null : $compose;
        }
        if (!is_array($compose)) {
            throw new ManifestException(self::YAML_FILENAME . ": 'compose' must be a string or an object");
        }
        $content = $compose['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new ManifestException(self::YAML_FILENAME . ": 'compose.content' must be a non-empty string");
        }

        return $content;
    }

    /** @param array<string, mixed> $raw */
    private static function readGitHistory(array $raw): string
    {
        $git = $raw['git'] ?? null;
        if ($git === null) {
            return self::GIT_HISTORY_SHALLOW;
        }
        $history = is_array($git) ? ($git['history'] ?? null) : null;
        if (!is_array($git) || array_diff(array_keys($git), ['history']) !== []
            || !in_array($history, [self::GIT_HISTORY_FULL, self::GIT_HISTORY_SHALLOW], true)
        ) {
            throw new ManifestException(
                self::YAML_FILENAME . ": 'git' must be {history: " . self::GIT_HISTORY_FULL
                . '} or {history: ' . self::GIT_HISTORY_SHALLOW . '}'
            );
        }

        return $history;
    }

    /** @param array<string, mixed> $raw */
    private static function readComposeMode(array $raw): string
    {
        $compose = $raw['compose'] ?? null;
        if (!is_array($compose)) {
            return self::COMPOSE_REPLACE;
        }
        $mode = $compose['mode'] ?? self::COMPOSE_REPLACE;
        if (!in_array($mode, [self::COMPOSE_REPLACE, self::COMPOSE_OVERRIDE], true)) {
            throw new ManifestException(
                self::YAML_FILENAME . ": 'compose.mode' must be '"
                . self::COMPOSE_REPLACE . "' or '" . self::COMPOSE_OVERRIDE . "'"
            );
        }

        return $mode;
    }

    /**
     * The manifest-shaped keys this file carries, or null when it carries none and is
     * only saying what to run.
     *
     * `commands` and `env` are left out because this class applies both already: copying
     * them into the manifest would run every command twice.
     *
     * @param array<string, mixed> $raw
     * @return ?array<string, mixed>
     */
    private static function readManifest(array $raw): ?array
    {
        // `commands` and `env` this class applies itself; `requires` rides along with a
        // manifest but does not make one on its own.
        $applied = ['commands', 'env', '$schema'];
        $manifest = array_intersect_key(
            $raw,
            array_flip(array_diff(PlatformManifest::KNOWN_KEYS, $applied))
        );
        // `extends` survives into the manifest array as the name of the base recipe even
        // when the config carries an id of its own: the source recipe layer resolves it,
        // and the key would otherwise be dropped here as a non-manifest one.
        if (isset($raw[self::EXTENDS_KEY])) {
            $manifest[self::EXTENDS_KEY] = $raw[self::EXTENDS_KEY];
        }
        // `credentials` and `port_scheme` ride along too: a compose recipe declares them without being a manifest.
        $describes = array_diff(array_keys($manifest), ['requires', 'credentials', 'port_scheme', self::EXTENDS_KEY]) !== [];

        $inherit = $raw[self::EXTENDS_KEY] ?? null;
        if ($inherit !== null) {
            if (!is_string($inherit) || trim($inherit) === '') {
                throw new ManifestException(
                    self::YAML_FILENAME . ": '" . self::EXTENDS_KEY . "' must name a shipped recipe"
                );
            }
            $inherit = trim($inherit);
            // An explicit `id` names THIS manifest and wins; `extends` stays the name of
            // the recipe to inherit from. Forcing the id to the inherited recipe's own made
            // two configs that only inherited answer to one id, which the shipped-tree
            // invariant refuses as shadowing. Without an `id`, the inherited one is taken.
            if (!isset($manifest['id']) || !is_string($manifest['id']) || trim($manifest['id']) === '') {
                $manifest['id'] = $inherit;
            }
        }

        if (!$describes && $inherit === null) {
            return null;
        }
        if ($inherit === null && !isset($manifest['id'])) {
            throw new ManifestException(
                self::YAML_FILENAME . ": describing a platform needs an 'id', or '"
                . self::EXTENDS_KEY . "' naming the recipe to start from"
            );
        }

        return $manifest;
    }

    /** @param array<string, mixed> $raw */
    private static function readAppScript(array $raw): ?string
    {
        $app = $raw['app'] ?? null;
        if ($app === null) {
            return null;
        }
        if (is_string($app)) {
            return trim($app) === '' ? null : $app;
        }
        if (!is_array($app)) {
            throw new ManifestException(self::YAML_FILENAME . ": 'app' must be a script or an object");
        }
        $script = $app['script'] ?? null;
        if (!is_string($script) || trim($script) === '') {
            throw new ManifestException(self::YAML_FILENAME . ": 'app.script' must be a non-empty string");
        }

        return $script;
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<array{path: string, contents: string}>
     */
    private static function readFiles(array $raw): array
    {
        $files = $raw['files'] ?? [];
        if (!is_array($files)) {
            throw new ManifestException(self::YAML_FILENAME . ": 'files' must be an object of path => contents");
        }

        $result = [];
        foreach ($files as $path => $contents) {
            if (!is_string($path) || trim($path) === '') {
                throw new ManifestException(self::YAML_FILENAME . ": 'files' keys must be paths");
            }
            // Strip a leading `./` and nothing else: `ltrim($path, './')` strips any run of
            // those two characters, turning `../../etc/cron.d/x` into `etc/cron.d/x` —
            // normalising away the traversal instead of refusing it.
            $clean = trim($path);
            if (str_starts_with($clean, './')) {
                $clean = substr($clean, 2);
            }
            // A snippet is written under the project directory; a path that
            // climbs out of it would write anywhere the account can reach.
            if ($clean === ''
                || str_starts_with($clean, '/')
                || $clean === '..'
                || str_starts_with($clean, '../')
                || str_contains($clean, '/../')
                || str_ends_with($clean, '/..')
            ) {
                throw new ManifestException(self::YAML_FILENAME . ": '{$path}' is not a path inside the project");
            }
            if (!is_string($contents)) {
                throw new ManifestException(self::YAML_FILENAME . ": contents of '{$path}' must be a string");
            }
            $result[] = ['path' => $clean, 'contents' => $contents];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<PlatformCommand>
     */
    private static function readCommands(array $raw): array
    {
        $declared = $raw['commands'] ?? [];
        if (!is_array($declared)) {
            throw new ManifestException(self::YAML_FILENAME . ": 'commands' must be a list");
        }

        $commands = [];
        $seen = [];
        foreach (array_values($declared) as $index => $entry) {
            if (!is_array($entry)) {
                throw new ManifestException(self::YAML_FILENAME . ".commands[{$index}]: must be an object");
            }
            $command = PlatformCommand::fromArray($entry, self::YAML_FILENAME, $index);
            if (isset($seen[$command->id])) {
                throw new ManifestException(
                    self::YAML_FILENAME . ": duplicate command id '{$command->id}'"
                );
            }
            $seen[$command->id] = true;
            $commands[] = $command;
        }

        return $commands;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, array<string, mixed>>
     */
    private static function readRequires(array $raw): array
    {
        $declared = $raw['requires'] ?? [];
        if (!is_array($declared)) {
            throw new ManifestException(self::YAML_FILENAME . ": 'requires' must be an object");
        }

        $requires = [];
        foreach ($declared as $id => $spec) {
            if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $id)) {
                throw new ManifestException(self::YAML_FILENAME . ": '{$id}' is not a valid runtime name");
            }
            if (is_string($spec)) {
                $spec = ['role' => $spec];
            }
            if (!is_array($spec)) {
                throw new ManifestException(
                    self::YAML_FILENAME . ".requires.{$id}: must be a role name or an object"
                );
            }
            $requires[$id] = [
                'role' => is_string($spec['role'] ?? null) ? $spec['role'] : 'runtime',
                'optional' => (bool) ($spec['optional'] ?? false),
            ];
        }

        return $requires;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, string>
     */
    private static function readEnv(array $raw): array
    {
        $declared = $raw['env'] ?? [];
        if (!is_array($declared)) {
            throw new ManifestException(self::YAML_FILENAME . ": 'env' must be an object");
        }

        $env = [];
        foreach ($declared as $key => $value) {
            if (!is_string($key) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                throw new ManifestException(self::YAML_FILENAME . ": '{$key}' is not a valid environment variable name");
            }
            if (!is_string($value) && !is_int($value) && !is_bool($value)) {
                throw new ManifestException(self::YAML_FILENAME . ".env.{$key}: must be a scalar");
            }
            $env[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $env;
    }

    /**
     * A markdown page leads with prose, a heading, or a backticked block name — none
     * of those parse as a YAML mapping with a key we know.
     */
    private static function looksLikeYaml(string $content): bool
    {
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            foreach (self::knownKeys() as $key) {
                if (str_starts_with($line, $key . ':')) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
}
