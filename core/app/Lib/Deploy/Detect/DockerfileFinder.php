<?php

namespace App\Lib\Deploy\Detect;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Port\InternalPorts;
use Generator;

/**
 * The Dockerfile a repository ships, if it ships one the engine can build. A
 * Dockerfile that maps the host UID is a development file, not a candidate.
 */
final class DockerfileFinder
{
    /**
     * Production Dockerfiles that live next to the app, not at the repository root (Flipt's release build is
     * `build/Dockerfile`, beside a root Dockerfile.dev). Sail and devcontainer paths are absent.
     *
     * @var list<string>
     */
    public const NESTED_CANDIDATES = [
        'docker/Dockerfile',
        'scripts/docker/Dockerfile',
        'build/Dockerfile',
    ];

    private const ROOT_NAME = 'Dockerfile';

    /**
     * `Containerfile` is the same file under Podman's name, taken with `-f`.
     * A plain `Dockerfile` is yielded first, so it wins when both exist.
     */
    private const NAME_PATTERN = '/^(?:Dockerfile|Containerfile)(\..+)?$/';

    /** A whole `EXPOSE` line: every port on it, `3000/tcp` forms included. */
    private const EXPOSE_LINE_PATTERN = '/^[ \t]*EXPOSE[ \t]+(.+)$/mi';

    private const PORT_TOKEN_PATTERN = '/\b(\d{1,5})(?:\/(tcp|udp))?\b/i';

    /** A `HEALTHCHECK` line, after continuations have been joined. */
    private const HEALTHCHECK_LINE_PATTERN = '/^[ \t]*HEALTHCHECK[ \t]+(.+)$/mi';

    /** The port of an HTTP URL on the container's own loopback, as a health check probes it. */
    private const LOOPBACK_HTTP_PORT_PATTERN = '#\bhttps?://(?:localhost|127\.0\.0\.1|0\.0\.0\.0|\[::1\]):(\d{1,5})\b#i';

    /** A whole `COPY` or `ADD` line, after continuations have been joined. */
    private const COPY_LINE_PATTERN = '/^[ \t]*(?:COPY|ADD)[ \t]+(.+)$/mi';

    /** A `FROM` line: the base it starts from, and the stage name it gives, if any. */
    public const FROM_LINE_PATTERN = '/^\s*FROM\s+(?:--\S+\s+)*(\S+)(?:\s+AS\s+(\S+))?\s*$/i';

    /**
     * @param  array<string, true>  $files  lowercase basename => true
     */
    public function __construct(private readonly string $projectDir, private readonly array $files) {}

    /**
     * @param  array<string, true>  $files  lowercase basename => true
     */
    public static function find(string $projectDir, array $files): ?string
    {
        return (new self($projectDir, $files))->first();
    }

    public function first(): ?string
    {
        foreach ($this->candidates() as $relative) {
            if ($this->isUsable($relative)) {
                return $relative;
            }
        }

        return null;
    }

    /** The first port a Dockerfile declares it listens on, best first. */
    public static function exposedPort(string $dockerfilePath): ?int
    {
        $contents = is_file($dockerfilePath) ? AccountHomeRead::contents($dockerfilePath) : null;

        return is_string($contents) ? self::exposedPortIn($contents) : null;
    }

    /**
     * The `EXPOSE`d port the image's own `HEALTHCHECK` probes over HTTP, else the first one, in written order, that could
     * be a web front door (a `/udp` one cannot serve HTTP), else the first port found, so a Dockerfile exposing only 22
     * still reports something. evcc exposes EEBus on 4712 before its UI on 7070, which its health check fetches.
     */
    public static function exposedPortIn(string $contents): ?int
    {
        $contents = self::servedStage($contents);
        $declared = self::exposedDeclarations($contents);
        if ($declared === []) {
            return null;
        }

        $probed = self::healthCheckedPort($contents);
        if ($probed !== null && (in_array([$probed, 'tcp'], $declared, true) || in_array([$probed, ''], $declared, true))) {
            return $probed;
        }

        foreach ($declared as [$port, $protocol]) {
            if ($protocol !== 'udp' && InternalPorts::isWebCandidate($port)) {
                return $port;
            }
        }

        return $declared[0][0];
    }

    /** The stage the deploy builds ({@see DockerfileStages}) when it declares a port of its own, else the whole file. */
    private static function servedStage(string $contents): string
    {
        $stage = DockerfileStages::stageText($contents, DockerfileStages::productionTarget($contents));

        return $stage !== $contents && self::exposedDeclarations($stage) !== [] ? $stage : $contents;
    }

    /** The port the last `HEALTHCHECK` fetches an HTTP URL from on the container's loopback; null when it does not. */
    private static function healthCheckedPort(string $contents): ?int
    {
        $joined = preg_replace('/\\\\\r?\n/', ' ', $contents) ?? $contents;
        if (preg_match_all(self::HEALTHCHECK_LINE_PATTERN, $joined, $lines) === 0) {
            return null;
        }
        $line = self::substitute((string) end($lines[1]), self::declaredValues($contents));
        if (preg_match(self::LOOPBACK_HTTP_PORT_PATTERN, $line, $m) !== 1) {
            return null;
        }
        $port = (int) $m[1];

        return $port > 0 && $port <= 65535 ? $port : null;
    }

    /**
     * Every TCP port the `EXPOSE` lines declare, in written order.
     *
     * @return list<int>
     */
    public static function exposedPortsIn(string $contents): array
    {
        $ports = [];
        foreach (self::exposedDeclarations($contents) as [$port, $protocol]) {
            if ($protocol !== 'udp') {
                $ports[$port] = true;
            }
        }

        return array_keys($ports);
    }

    /**
     * `EXPOSE ${PORT}` after `ENV PORT=8080` states a port as plainly as a literal; a name the file never defines is skipped.
     *
     * @return list<array{0: int, 1: string}> port and lowercased protocol ('' when unstated)
     */
    private static function exposedDeclarations(string $contents): array
    {
        if (preg_match_all(self::EXPOSE_LINE_PATTERN, $contents, $lines) === 0) {
            return [];
        }

        $declared = self::declaredValues($contents);

        $ports = [];
        foreach ($lines[1] as $line) {
            $line = self::substitute($line, $declared);
            if (preg_match_all(self::PORT_TOKEN_PATTERN, $line, $tokens, PREG_SET_ORDER) === 0) {
                continue;
            }
            foreach ($tokens as $token) {
                $port = (int) $token[1];
                if ($port > 0 && $port <= 65535) {
                    $ports[] = [$port, strtolower($token[2] ?? '')];
                }
            }
        }

        return $ports;
    }

    /**
     * Every `ENV`/`ARG` value the file assigns, later assignments winning, the old `ENV NAME value` form included.
     * Continuations are joined first: a multi-line `ENV` is one instruction.
     *
     * @return array<string, string>
     */
    private static function declaredValues(string $contents): array
    {
        $joined = preg_replace('/\\\\\r?\n/', ' ', $contents) ?? $contents;
        if (preg_match_all('/^[ \t]*(?:ENV|ARG)[ \t]+(.+)$/mi', $joined, $lines) === 0) {
            return [];
        }

        $values = [];
        foreach ($lines[1] as $line) {
            $pattern = '/([A-Za-z_][A-Za-z0-9_]*)=("[^"]*"|\'[^\']*\'|\S+)/';
            if (preg_match_all($pattern, $line, $pairs, PREG_SET_ORDER) === 0) {
                if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)[ \t]+(\S.*)$/', $line, $single) === 1) {
                    $values[$single[1]] = trim($single[2], "\"'");
                }

                continue;
            }
            foreach ($pairs as $pair) {
                $values[$pair[1]] = trim($pair[2], "\"'");
            }
        }

        return $values;
    }

    /**
     * `$PORT` and `${PORT}` replaced by what the file assigned them. A name
     * the file never defines is left unresolved, not emptied.
     *
     * @param  array<string, string>  $values
     */
    private static function substitute(string $line, array $values): string
    {
        return preg_replace_callback(
            '/\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?/',
            static fn (array $m): string => $values[$m[1]] ?? $m[0],
            $line
        ) ?? $line;
    }

    /** `VOLUME /data` or `VOLUME ["/data", "/config"]`. */
    private const VOLUME_LINE_PATTERN = '/^[ \t]*VOLUME[ \t]+(.+)$/mi';

    /**
     * The container paths a Dockerfile declares as volumes, a build-arg path left out rather than guessed at. Unnamed by
     * compose, each is an anonymous volume: not backed up, orphaned on recreate, and Vaultwarden will not start on one.
     *
     * @return list<string> absolute container paths, in the order declared
     */
    public static function declaredVolumesIn(string $contents): array
    {
        if (preg_match_all(self::VOLUME_LINE_PATTERN, $contents, $lines) === 0) {
            return [];
        }

        $paths = [];
        foreach ($lines[1] as $line) {
            $line = trim($line);
            $decoded = str_starts_with($line, '[') ? json_decode($line, true) : null;
            $tokens = is_array($decoded) ? $decoded : preg_split('/\s+/', $line);
            foreach ($tokens ?: [] as $token) {
                $path = trim((string) $token, " \t\"'");
                if (str_starts_with($path, '/') && ! str_contains($path, '$')) {
                    $paths[$path] = true;
                }
            }
        }

        return array_keys($paths);
    }

    /**
     * Suffixes naming a Dockerfile not meant to run the application in production (a coverage build is an instrumented
     * binary), ranked below NESTED_CANDIDATES: `docker/Dockerfile` is a likelier production build than `Dockerfile.dev`.
     *
     * @var list<string>
     */
    private const DEMOTED_VARIANTS = [
        'dev', 'develop', 'development', 'local', 'test', 'tests', 'ci',
        'builder', 'build', 'client', 'dispatcher', 'heroku', 'example', 'sample',
        'coverage',
    ];

    /**
     * Suffixes that say "this is the one to ship", ranked above every other variant so they beat whatever the directory
     * listing happens to return first.
     *
     * @var list<string>
     */
    private const PREFERRED_VARIANTS = ['prod', 'production', 'release', 'stable'];

    /**
     * Paths that might be a Dockerfile, best first: root `Dockerfile` (or `Containerfile`), PREFERRED_VARIANTS, unmarked
     * variants, NESTED_CANDIDATES, DEMOTED_VARIANTS.
     *
     * @return Generator<string>
     */
    private function candidates(): Generator
    {
        $names = $this->namesOnDisk();
        [$preferred, $neutral, $demoted] = self::rankedVariants(array_keys($names));

        yield from $this->plainNames($names);
        yield from $preferred;
        yield from $neutral;
        yield from self::NESTED_CANDIDATES;
        yield from $demoted;
    }

    /**
     * The listing's variant names and every Dockerfile-shaped name on disk: the listing is lowercased, and `Dockerfile.prod`
     * has to be opened under the case it was written in.
     *
     * @return array<string, true>
     */
    private function namesOnDisk(): array
    {
        $names = [];
        foreach (array_keys($this->files) as $name) {
            if (str_starts_with((string) $name, 'dockerfile.')) {
                $names[(string) $name] = true;
            }
        }
        foreach (ProjectContext::entries($this->projectDir) as $entry) {
            if (preg_match(self::NAME_PATTERN, $entry) === 1) {
                $names[$entry] = true;
            }
        }

        return $names;
    }

    /**
     * `Dockerfile` from the listing or from disk (a caller with no listing, PortsReport, passes []), then `Containerfile`.
     *
     * @param  array<string, true>  $names
     * @return list<string>
     */
    private function plainNames(array $names): array
    {
        $plain = [];
        if (isset($this->files[strtolower(self::ROOT_NAME)]) || isset($names[self::ROOT_NAME])) {
            $plain[] = self::ROOT_NAME;
        }
        foreach (array_keys($names) as $name) {
            $suffix = (string) strstr((string) $name, '.');
            if ($suffix === '' && strcasecmp((string) $name, self::ROOT_NAME) !== 0) {
                $plain[] = (string) $name;
            }
        }

        return $plain;
    }

    /**
     * Suffixed names as preferred, unmarked and demoted. Every part of a multi-part suffix counts (`server.production` is
     * preferred, `server.dev` demoted), and a part in both lists demotes: `Dockerfile.prod.test` is still a test file.
     *
     * @param  list<string>  $names
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    private static function rankedVariants(array $names): array
    {
        $preferred = [];
        $neutral = [];
        $demoted = [];
        foreach ($names as $name) {
            $suffix = (string) strstr((string) $name, '.');
            if ($suffix === '') {
                continue;
            }

            $parts = array_filter(explode('.', strtolower(ltrim($suffix, '.'))));
            if (array_intersect($parts, self::DEMOTED_VARIANTS) !== []) {
                $demoted[] = (string) $name;
            } elseif (array_intersect($parts, self::PREFERRED_VARIANTS) !== []) {
                $preferred[] = (string) $name;
            } else {
                $neutral[] = (string) $name;
            }
        }

        return [$preferred, $neutral, $demoted];
    }

    /**
     * The one `<name>.dockerfile` at the root (otobo's `otobo.web.dockerfile`), less DEMOTED_VARIANTS; of several, the one
     * a PREFERRED_VARIANTS word marks, else none, since picking would be a guess.
     */
    public static function findNamed(string $projectDir): ?string
    {
        $finder = new self($projectDir, []);
        $usable = [];
        $preferred = [];
        foreach (ProjectContext::entries($projectDir) as $entry) {
            if (preg_match('/^(.+)\.(?:dockerfile|containerfile)$/i', $entry, $m) !== 1
                || preg_match(self::NAME_PATTERN, $entry) === 1
                || ! $finder->isUsable($entry)
            ) {
                continue;
            }
            $parts = array_filter(preg_split('/[._-]+/', strtolower($m[1])) ?: []);
            if (array_intersect($parts, self::DEMOTED_VARIANTS) !== []) {
                continue;
            }
            $usable[] = $entry;
            if (array_intersect($parts, self::PREFERRED_VARIANTS) !== []) {
                $preferred[] = $entry;
            }
        }

        if (count($usable) === 1) {
            return $usable[0];
        }

        return count($preferred) === 1 ? $preferred[0] : null;
    }

    /**
     * A `COPY`/`ADD` source the build context lacks (a gitignored CI binary fails late as `failed to compute cache key`). An
     * assigned default is read as that default, since nothing passes another; any other variable counts by its literal prefix.
     */
    public static function missingContextSource(string $contents, string $contextDir): ?string
    {
        $declared = self::declaredValues($contents);
        foreach (self::copyInstructions($contents) as $sources) {
            foreach ($sources as $source) {
                $required = self::literalPrefix(self::withPlatformArgs(self::substitute($source, $declared)));
                if ($required === null) {
                    continue;
                }
                if (! file_exists(rtrim($contextDir, '/').'/'.$required)) {
                    return $source;
                }
            }
        }

        return null;
    }

    /**
     * The sources of every `COPY`/`ADD` that reads from the build context, continuations joined: not a `--from` stage,
     * not the destination (the last argument), not a `<<EOF` heredoc's inline content.
     *
     * @return Generator<list<string>>
     */
    private static function copyInstructions(string $contents): Generator
    {
        $contents = self::withoutHeredocBodies($contents);
        $joined = preg_replace('/\\\\[ \t]*\r?\n/', ' ', $contents) ?? $contents;

        if (preg_match_all(self::COPY_LINE_PATTERN, $joined, $lines) === 0) {
            return;
        }

        foreach ($lines[1] as $line) {
            $argv = self::instructionArgv(trim($line));
            $flags = array_filter($argv, static fn (string $a): bool => str_starts_with($a, '--'));
            foreach ($flags as $flag) {
                if (str_starts_with(strtolower($flag), '--from=')) {
                    continue 2;
                }
            }

            $paths = array_values(array_filter($argv, static fn (string $a): bool => ! str_starts_with($a, '--')));
            array_pop($paths);
            if ($paths === []) {
                continue;
            }

            yield array_values(array_filter(
                $paths,
                static fn (string $p): bool => ! self::isRemote($p) && ! str_starts_with($p, '<<')
            ));
        }
    }

    /**
     * Drops BuildKit heredoc bodies (`<<EOF` ... `EOF`), so a script's own
     * `COPY`/`ADD` lines are not read as instructions.
     */
    public static function withoutHeredocBodies(string $contents): string
    {
        $out = [];
        $pending = [];
        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            if ($pending !== []) {
                if (trim($line) === $pending[0]) {
                    array_shift($pending);
                }

                continue;
            }
            $out[] = $line;
            if (preg_match_all('/<<-?(["\']?)([A-Za-z_][A-Za-z0-9_]*)\1/', $line, $m) > 0) {
                $pending = $m[2];
            }
        }

        return implode("\n", $out);
    }

    /**
     * An instruction's arguments, JSON-array form included.
     *
     * @return list<string>
     */
    private static function instructionArgv(string $line): array
    {
        $bracket = strpos($line, '[');
        if ($bracket !== false && trim(substr($line, 0, $bracket)) === '') {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return array_values(array_map(static fn (mixed $v): string => (string) $v, $decoded));
            }
        }

        $argv = preg_split('/\s+/', $line) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $a): string => trim($a, "\"'"),
            $argv
        ), static fn (string $a): bool => $a !== ''));
    }

    private static function isRemote(string $path): bool
    {
        return (bool) preg_match('#^(?:https?://|git@|[a-z]+://)#i', $path);
    }

    /**
     * The certain part of a source path, cleaned as Docker cleans it (only whole `.` segments go: `ltrim` would eat `.env`),
     * or null: `build/app-${TARGETOS}` as far as `build/`; `${DIR}/app`, or a `..` segment escaping the context, not at all.
     */
    private static function literalPrefix(string $source): ?string
    {
        $segments = array_filter(explode('/', $source), static fn (string $s): bool => $s !== '' && $s !== '.');
        $source = implode('/', $segments);
        if ($source === '') {
            return null;
        }

        if (preg_match('#(?:^|/)\.\.(?:/|$)#', $source) === 1) {
            return null;
        }

        $cut = strcspn($source, '$*?[');
        if ($cut === strlen($source)) {
            return $source;
        }

        $slash = strrpos(substr($source, 0, $cut), '/');

        return $slash === false ? null : substr($source, 0, $slash);
    }

    /** BuildKit sets these itself for this host unless `--platform` is given. */
    private static function withPlatformArgs(string $source): string
    {
        $arch = in_array(php_uname('m'), ['aarch64', 'arm64'], true) ? 'arm64' : 'amd64';
        $values = [
            'TARGETPLATFORM' => "linux/{$arch}", 'BUILDPLATFORM' => "linux/{$arch}",
            'TARGETOS' => 'linux', 'BUILDOS' => 'linux',
            'TARGETARCH' => $arch, 'BUILDARCH' => $arch,
            'TARGETVARIANT' => '', 'BUILDVARIANT' => '',
        ];

        return preg_replace_callback(
            '/\$\{(\w+)\}|\$(\w+)/',
            static function (array $m) use ($values): string {
                $name = $m[1] !== '' ? $m[1] : $m[2];

                return $values[$name] ?? $m[0];
            },
            $source
        ) ?? $source;
    }

    private function isUsable(string $relative): bool
    {
        $path = $this->projectDir.'/'.$relative;
        if (! is_file($path) || ComposeFileInspector::isHostUidMappedDockerfile($path)) {
            return false;
        }

        $contents = AccountHomeRead::contents($path);
        if (! is_string($contents)) {
            return false;
        }

        return self::missingContextSource($contents, $this->projectDir) === null;
    }
}
