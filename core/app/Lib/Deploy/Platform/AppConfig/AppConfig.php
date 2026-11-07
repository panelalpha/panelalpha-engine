<?php

namespace App\Lib\Deploy\Platform\AppConfig;

use App\Lib\Deploy\Credentials\CredentialSpec;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformCommand;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Project\PublicRouting\PublicRouting;
use App\Lib\Project\PublicRouting\PublicRoutingReader;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * What one project says about how to host it, which no manifest of a kind of application can hold: `panelalpha.yaml` in the
 * manifest vocabulary plus single-application keys, or `panelalpha.md`, markdown with named fenced blocks.
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
        'precheck', 'prepare', 'entrypoint', 'commands', 'files', 'app', 'compose', 'git', 'health',
        ...PublicRoutingReader::KEYS,
    ];

    /** Ceiling for `health: {start_period}`, in seconds. */
    public const MAX_START_PERIOD = 1800;

    /** Names the shipped recipe this application is an instance of. */
    public const EXTENDS_KEY = 'extends';

    /** Keys that ride along with a manifest without describing one. */
    private const NON_MANIFEST_KEYS = ['requires', 'credentials', 'port_scheme', self::EXTENDS_KEY];

    /**
     * @param  list<array{path: string, contents: string}>  $files
     * @param  list<PlatformCommand>  $commands
     * @param  array<string, array<string, mixed>>  $requires
     * @param  array<string, string>  $env
     * @param  ?list<string>  $gitSubmodules
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
        private readonly ?int $startPeriod = null,
        private readonly ?array $gitSubmodules = null,
        private readonly ?PublicRouting $publicRouting = null,
    ) {}

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
     * Parse by shape when the filename is not to hand: a markdown page opens with prose or a heading, a YAML document does not.
     * Only for callers with content and no path; `load()` knows the filename and does not guess.
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

    /**
     * @param  string  $subject  how errors name the page: the file it was read from
     */
    public static function fromMarkdown(string $content, string $subject = self::MARKDOWN_FILENAME): ?self
    {
        if (trim($content) === '') {
            return null;
        }

        $setup = PaemdPage::setupCommands($content);
        $compose = PaemdPage::compose($content);
        $config = self::embeddedConfig($content, $subject);

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
            $config?->startPeriod(),
            $config?->gitSubmodules(),
            $config?->publicRouting,
        );
    }

    /** A page's block of the YAML format, read by the same code, so a page and a `panelalpha.yaml` mean the same by the same words. */
    private static function embeddedConfig(string $content, string $subject): ?self
    {
        $embedded = PaemdPage::config($content);

        return $embedded === null ? null : self::fromYaml($embedded, $subject);
    }

    /**
     * @param  string  $subject  how errors name this document: the file it was read
     *                           from, or the recipe that ships it
     *
     * @throws ManifestException on malformed YAML or an unknown key
     */
    public static function fromYaml(string $content, string $subject = self::YAML_FILENAME): ?self
    {
        if (trim($content) === '') {
            return null;
        }

        try {
            $raw = Yaml::parse($content);
        } catch (ParseException $e) {
            throw new ManifestException(
                'Invalid YAML in '.$subject.': '.$e->getMessage(),
                0,
                $e
            );
        }
        if (! is_array($raw)) {
            throw new ManifestException($subject.' does not contain a mapping');
        }

        return self::fromMapping($raw, $subject);
    }

    /**
     * @param  array<string, mixed>  $raw
     *
     * @throws ManifestException on an unknown key or a malformed value
     */
    private static function fromMapping(array $raw, string $subject): self
    {
        self::assertNoUnknownKeys($raw, $subject);

        $compose = self::readComposeContent($raw, $subject);
        $mode = self::readComposeMode($raw, $subject);
        $setup = self::readScript($raw, 'prepare', $subject);

        return new self(
            self::readScript($raw, 'precheck', $subject),
            $setup,
            self::readScript($raw, 'entrypoint', $subject),
            $compose,
            $mode,
            self::readAppScript($raw, $subject),
            self::readFiles($raw, $subject),
            self::readCommands($raw, $subject),
            self::readRequires($raw, $subject),
            self::readEnv($raw, $subject),
            self::readManifest($raw, $subject),
            CredentialSpec::parse(
                $raw['credentials'] ?? null,
                static fn (string $m): ManifestException => new ManifestException($subject.": {$m}")
            ),
            self::readGitHistory($raw, $subject) === self::GIT_HISTORY_FULL,
            self::readPortScheme($raw, $subject),
            self::readStartPeriod($raw, $subject),
            self::readGitSubmodules($raw, $subject),
            PublicRoutingReader::read($raw, $subject),
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
     * @param  string  $subject  how errors name the document inside the directory
     *
     * @throws ManifestException
     */
    public static function fromDirectory(array $parts, string $subject = self::YAML_FILENAME): ?self
    {
        $raw = $parts['config'] ?? null;
        $config = is_string($raw) && trim($raw) !== '' ? self::fromYaml($raw, $subject) : null;
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
            $config?->startPeriod(),
            $config?->gitSubmodules(),
            $config?->publicRouting,
        );

        return $appConfig->isEmpty() ? null : $appConfig;
    }

    /**
     * Files from the directory, then any the config declares that it did not already
     * provide — same rule as the scripts: the file wins.
     *
     * @param  list<array{path: string, contents: string}>  $files
     * @param  list<array{path: string, contents: string}>  $inline
     * @return list<array{path: string, contents: string}>
     */
    private static function mergeFiles(array $files, array $inline): array
    {
        $paths = array_column($files, 'path');
        foreach ($inline as $entry) {
            if (! in_array($entry['path'], $paths, true)) {
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
            && ! $this->fullGitHistory
            && $this->portScheme === null
            && $this->startPeriod === null
            && $this->gitSubmodules === null
            && $this->publicRouting === null;
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
     * Setup to run on the account after the clone, before the build; not the manifest's `install` stage, which runs in the
     * container on first boot: an app config generating a compose file must do it here, before there is an image to run.
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
     * The compose file a deploy writes into the checkout, where detection reads it ahead of the repository's own. Null in
     * override mode, which is layered over the run file outside the checkout.
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
     * Whether the user-management script can log an admin in: `users:sso` in the capability list its `info` command prints,
     * read from the script's text, so nothing runs.
     */
    public function supportsAdminSso(): bool
    {
        if ($this->appScript === null) {
            return false;
        }
        preg_match_all('/\[\s*"[a-z:-]+"(?:\s*,\s*"[a-z:-]+")*\s*\]/', $this->appScript, $lists);
        foreach ($lists[0] as $list) {
            if (str_contains($list, '"users:sso"')) {
                return true;
            }
        }

        return false;
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
     * The manifest this file describes, when it describes one: `extends:` naming a shipped recipe, manifest keys of its own, or
     * both, the keys overriding the recipe they extend.
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
     * Staged commands, in a manifest's vocabulary. YAML only: markdown has one script per lifecycle point and no way to name,
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
     * The app config's own entrypoint, a `serve: true` command in the start stage. It replaces the platform's, as two processes
     * fighting for one port is not a thing anyone asked for.
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
     * Whether the deploy clone needs the whole history and every tag: a build that stamps its version with `git describe` fails
     * on the default depth-1 clone.
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

    /**
     * `health: {start_period}`: how long the app may take to bind its port
     * after the deploy's own probe gave up. Null means the engine's default.
     */
    public function startPeriod(): ?int
    {
        return $this->startPeriod;
    }

    /**
     * `git: {submodules: [...]}`: the only submodules the deploy fetches, by path, each with its own submodules. Null fetches
     * every one the repository declares; an empty list fetches none.
     *
     * @return ?list<string>
     */
    public function gitSubmodules(): ?array
    {
        return $this->gitSubmodules;
    }

    /** `domain:` and `public_ports:`: what the application asks to be reached on besides its domain's :80/:443. */
    public function publicRouting(): PublicRouting
    {
        return $this->publicRouting ?? PublicRouting::none();
    }

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
     * @param  array<string, mixed>  $raw
     */
    private static function assertNoUnknownKeys(array $raw, string $subject): void
    {
        $known = self::knownKeys();
        $unknown = array_diff(array_keys($raw), $known);
        if ($unknown !== []) {
            sort($known);
            throw new ManifestException(
                $subject.': unknown key(s) '.implode(', ', $unknown)
                .'; expected one of '.implode(', ', $known)
            );
        }
    }

    /**
     * `precheck:` / `prepare:` as a bare script, for an app config that has one thing
     * to say and no reason to name it.
     *
     * @param  array<string, mixed>  $raw
     */
    private static function readScript(array $raw, string $key, string $subject): ?string
    {
        $value = $raw[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || trim($value) === '') {
            throw new ManifestException($subject.": '{$key}' must be a non-empty script");
        }

        return $value;
    }

    /** @param array<string, mixed> $raw */
    private static function readPortScheme(array $raw, string $subject): ?string
    {
        $scheme = $raw['port_scheme'] ?? null;
        if ($scheme !== null && ! in_array($scheme, PlatformManifest::PORT_SCHEMES, true)) {
            throw new ManifestException(
                $subject.": 'port_scheme' must be one of ".implode(', ', PlatformManifest::PORT_SCHEMES)
            );
        }

        return $scheme;
    }

    /** @param array<string, mixed> $raw */
    private static function readStartPeriod(array $raw, string $subject): ?int
    {
        $health = $raw['health'] ?? null;
        if ($health === null) {
            return null;
        }
        $period = is_array($health) ? ($health['start_period'] ?? null) : null;
        if (! is_array($health) || array_diff(array_keys($health), ['start_period']) !== []
            || ! is_int($period) || $period < 0 || $period > self::MAX_START_PERIOD
        ) {
            throw new ManifestException(
                $subject.": 'health' must be {start_period: <seconds, 0-".self::MAX_START_PERIOD.'>}'
            );
        }

        return $period;
    }

    /** @param array<string, mixed> $raw */
    private static function readComposeContent(array $raw, string $subject): ?string
    {
        $compose = $raw['compose'] ?? null;
        if ($compose === null) {
            return null;
        }
        if (is_string($compose)) {
            return trim($compose) === '' ? null : $compose;
        }
        if (! is_array($compose)) {
            throw new ManifestException($subject.": 'compose' must be a string or an object");
        }
        $content = $compose['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            throw new ManifestException($subject.": 'compose.content' must be a non-empty string");
        }

        return $content;
    }

    /** @param array<string, mixed> $raw */
    private static function readGitHistory(array $raw, string $subject): string
    {
        $git = $raw['git'] ?? null;
        if ($git === null) {
            return self::GIT_HISTORY_SHALLOW;
        }
        $history = is_array($git) ? ($git['history'] ?? self::GIT_HISTORY_SHALLOW) : null;
        if (! is_array($git) || $git === [] || array_diff(array_keys($git), ['history', 'submodules']) !== []
            || ! in_array($history, [self::GIT_HISTORY_FULL, self::GIT_HISTORY_SHALLOW], true)
        ) {
            throw new ManifestException(
                $subject.": 'git' takes history (".self::GIT_HISTORY_FULL.' or '
                .self::GIT_HISTORY_SHALLOW.') and submodules (a list of paths)'
            );
        }

        return $history;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return ?list<string>
     */
    private static function readGitSubmodules(array $raw, string $subject): ?array
    {
        $git = $raw['git'] ?? null;
        if (! is_array($git) || ! array_key_exists('submodules', $git)) {
            return null;
        }
        $paths = $git['submodules'];
        if (! is_array($paths) || ! array_is_list($paths)) {
            throw new ManifestException($subject.": 'git.submodules' must be a list of submodule paths");
        }

        return array_values(array_unique(array_map(
            static fn (mixed $path): string => self::submodulePath($path, $subject),
            $paths
        )));
    }

    /** A path inside the repository, as `.gitmodules` names it: relative, and not climbing out. */
    private static function submodulePath(mixed $path, string $subject): string
    {
        $clean = is_string($path) ? rtrim(trim($path), '/') : '';
        if ($clean === '' || str_starts_with($clean, '/') || str_starts_with($clean, '-')
            || in_array('..', explode('/', $clean), true)
        ) {
            throw new ManifestException(
                $subject.": 'git.submodules' entries must be paths inside the repository, got "
                .json_encode($path)
            );
        }

        return $clean;
    }

    /** @param array<string, mixed> $raw */
    private static function readComposeMode(array $raw, string $subject): string
    {
        $compose = $raw['compose'] ?? null;
        if (! is_array($compose)) {
            return self::COMPOSE_REPLACE;
        }
        $mode = $compose['mode'] ?? self::COMPOSE_REPLACE;
        if (! in_array($mode, [self::COMPOSE_REPLACE, self::COMPOSE_OVERRIDE], true)) {
            throw new ManifestException(
                $subject.": 'compose.mode' must be '"
                .self::COMPOSE_REPLACE."' or '".self::COMPOSE_OVERRIDE."'"
            );
        }

        return $mode;
    }

    /**
     * The manifest-shaped keys this file carries, or null when it only says what to run. An explicit `id` names this manifest and
     * wins over the inherited one: two configs that only inherit would answer to one id, which the shipped tree refuses as shadowing.
     *
     * @param  array<string, mixed>  $raw
     * @return ?array<string, mixed>
     */
    private static function readManifest(array $raw, string $subject): ?array
    {
        $manifest = self::manifestKeys($raw);
        $describing = self::describingKeys($manifest);

        $inherit = $raw[self::EXTENDS_KEY] ?? null;
        if ($inherit !== null) {
            if (! is_string($inherit) || trim($inherit) === '') {
                throw new ManifestException(
                    $subject.": '".self::EXTENDS_KEY."' must name a shipped recipe"
                );
            }
            $inherit = trim($inherit);
            if (! isset($manifest['id']) || ! is_string($manifest['id']) || trim($manifest['id']) === '') {
                $manifest['id'] = $inherit;
            }
        }

        if ($describing === [] && $inherit === null) {
            return null;
        }
        if ($inherit === null && ! isset($manifest['id'])) {
            throw new ManifestException(
                $subject.': declares '.implode(', ', $describing)." but has no 'id', or '"
                .self::EXTENDS_KEY."' naming the recipe to start from"
            );
        }

        return $manifest;
    }

    /**
     * Manifest keys, without `commands` and `env`, which this class applies itself (copying them would run every command twice).
     * `extends` is kept as the base recipe's name even beside an `id`: the source recipe layer resolves it.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function manifestKeys(array $raw): array
    {
        $applied = ['commands', 'env', '$schema'];
        $manifest = array_intersect_key(
            $raw,
            array_flip(array_diff(PlatformManifest::KNOWN_KEYS, $applied))
        );
        if (isset($raw[self::EXTENDS_KEY])) {
            $manifest[self::EXTENDS_KEY] = $raw[self::EXTENDS_KEY];
        }

        return $manifest;
    }

    /**
     * The keys that describe a platform, sorted: `requires`, `credentials` and `port_scheme` ride along with a manifest without
     * making one, as a compose recipe declares the last two without being a manifest.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private static function describingKeys(array $manifest): array
    {
        $describing = array_diff(array_keys($manifest), self::NON_MANIFEST_KEYS);
        sort($describing);

        return $describing;
    }

    /** @param array<string, mixed> $raw */
    private static function readAppScript(array $raw, string $subject): ?string
    {
        $app = $raw['app'] ?? null;
        if ($app === null) {
            return null;
        }
        if (is_string($app)) {
            return trim($app) === '' ? null : $app;
        }
        if (! is_array($app)) {
            throw new ManifestException($subject.": 'app' must be a script or an object");
        }
        $script = $app['script'] ?? null;
        if (! is_string($script) || trim($script) === '') {
            throw new ManifestException($subject.": 'app.script' must be a non-empty string");
        }

        return $script;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<array{path: string, contents: string}>
     */
    private static function readFiles(array $raw, string $subject): array
    {
        $files = $raw['files'] ?? [];
        if (! is_array($files)) {
            throw new ManifestException($subject.": 'files' must be an object of path => contents");
        }

        $result = [];
        foreach ($files as $path => $contents) {
            if (! is_string($path) || trim($path) === '') {
                throw new ManifestException($subject.": 'files' keys must be paths");
            }
            $clean = self::snippetPath($path, $subject);
            if (! is_string($contents)) {
                throw new ManifestException($subject.": contents of '{$path}' must be a string");
            }
            $result[] = ['path' => $clean, 'contents' => $contents];
        }

        return $result;
    }

    /**
     * $path without a leading `./` and nothing else (never `ltrim($path, './')`, which turns `../x` into `x`), refused
     * when it climbs out of the project or holds a NUL: a snippet written there could land anywhere the account can reach.
     */
    private static function snippetPath(string $path, string $subject): string
    {
        $clean = trim($path);
        if (str_starts_with($clean, './')) {
            $clean = substr($clean, 2);
        }
        if ($clean === ''
            || str_contains($clean, "\0")
            || str_starts_with($clean, '/')
            || $clean === '..'
            || str_starts_with($clean, '../')
            || str_contains($clean, '/../')
            || str_ends_with($clean, '/..')
        ) {
            throw new ManifestException($subject.": '{$path}' is not a path inside the project");
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<PlatformCommand>
     */
    private static function readCommands(array $raw, string $subject): array
    {
        $declared = $raw['commands'] ?? [];
        if (! is_array($declared)) {
            throw new ManifestException($subject.": 'commands' must be a list");
        }

        $commands = [];
        $seen = [];
        foreach (array_values($declared) as $index => $entry) {
            if (! is_array($entry)) {
                throw new ManifestException($subject.".commands[{$index}]: must be an object");
            }
            $command = PlatformCommand::fromArray($entry, $subject, $index);
            if (isset($seen[$command->id])) {
                throw new ManifestException(
                    $subject.": duplicate command id '{$command->id}'"
                );
            }
            $seen[$command->id] = true;
            $commands[] = $command;
        }

        return $commands;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, array<string, mixed>>
     */
    private static function readRequires(array $raw, string $subject): array
    {
        $declared = $raw['requires'] ?? [];
        if (! is_array($declared)) {
            throw new ManifestException($subject.": 'requires' must be an object");
        }

        $requires = [];
        foreach ($declared as $id => $spec) {
            if (! is_string($id) || ! preg_match('/^[a-z0-9][a-z0-9-]*$/', $id)) {
                throw new ManifestException($subject.": '{$id}' is not a valid runtime name");
            }
            if (is_string($spec)) {
                $spec = ['role' => $spec];
            }
            if (! is_array($spec)) {
                throw new ManifestException(
                    $subject.".requires.{$id}: must be a role name or an object"
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
     * @param  array<string, mixed>  $raw
     * @return array<string, string>
     */
    private static function readEnv(array $raw, string $subject): array
    {
        $declared = $raw['env'] ?? [];
        if (! is_array($declared)) {
            throw new ManifestException($subject.": 'env' must be an object");
        }

        $env = [];
        foreach ($declared as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                throw new ManifestException($subject.": '{$key}' is not a valid environment variable name");
            }
            if (! is_string($value) && ! is_int($value) && ! is_bool($value)) {
                throw new ManifestException($subject.".env.{$key}: must be a scalar");
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
                if (str_starts_with($line, $key.':')) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
}
