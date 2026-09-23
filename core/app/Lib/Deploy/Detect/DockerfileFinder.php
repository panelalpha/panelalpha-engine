<?php

namespace App\Lib\Deploy\Detect;

use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Port\InternalPorts;
use Generator;

/**
 * The Dockerfile a repository ships, if it ships one the engine can build. A
 * Dockerfile that maps the host UID is a development file, not a candidate.
 */
final class DockerfileFinder
{
    /**
     * Production Dockerfiles that live next to the app, not at the repository
     * root. Sail and devcontainer paths are absent.
     *
     * @var list<string>
     */
    public const NESTED_CANDIDATES = [
        'docker/Dockerfile',
        'scripts/docker/Dockerfile',
        // Flipt keeps its release build here while a Dockerfile.dev sits at
        // the root, which is the shape DEMOTED_VARIANTS exists to lose to.
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

    private const PORT_TOKEN_PATTERN = '/\b(\d{1,5})(?:\/(?:tcp|udp))?\b/i';

    /** A whole `COPY` or `ADD` line, after continuations have been joined. */
    private const COPY_LINE_PATTERN = '/^[ \t]*(?:COPY|ADD)[ \t]+(.+)$/mi';

    /**
     * @param array<string, true> $files lowercase basename => true
     */
    public function __construct(private readonly string $projectDir, private readonly array $files)
    {
    }

    /**
     * @param array<string, true> $files lowercase basename => true
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
        $contents = is_file($dockerfilePath) ? @file_get_contents($dockerfilePath) : null;

        return is_string($contents) ? self::exposedPortIn($contents) : null;
    }

    /**
     * The first exposed port an application could be served on: every port on
     * every `EXPOSE` line in written order, minus the ones that cannot be a
     * web front door. Falls back to the first port found when all are
     * excluded, so a Dockerfile exposing only 22 still reports something.
     */
    public static function exposedPortIn(string $contents): ?int
    {
        if (preg_match_all(self::EXPOSE_LINE_PATTERN, $contents, $lines) === 0) {
            return null;
        }

        $declared = self::declaredValues($contents);

        $ports = [];
        foreach ($lines[1] as $line) {
            // `EXPOSE ${PORT}` after `ENV PORT=8080` states a port as plainly
            // as a literal. A name the file never defines is skipped.
            $line = self::substitute($line, $declared);
            if (preg_match_all(self::PORT_TOKEN_PATTERN, $line, $tokens) === 0) {
                continue;
            }
            foreach ($tokens[1] as $token) {
                $port = (int) $token;
                if ($port > 0 && $port <= 65535) {
                    $ports[] = $port;
                }
            }
        }

        if ($ports === []) {
            return null;
        }

        foreach ($ports as $port) {
            if (InternalPorts::isWebCandidate($port)) {
                return $port;
            }
        }

        return $ports[0];
    }

    /**
     * Every `ENV`/`ARG` value the file assigns, later assignments winning.
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
                // The old `ENV NAME value` form: one value, no second pair.
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
     * @param array<string, string> $values
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
     * The container paths a Dockerfile declares as volumes. When the compose
     * file names none, Docker creates an anonymous volume for each: not in
     * ~/project, not backed up, orphaned on recreate. Vaultwarden refuses to
     * start on an anonymous data folder and exits 1.
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
                // A build-arg path is not something to guess at.
                if (str_starts_with($path, '/') && !str_contains($path, '$')) {
                    $paths[$path] = true;
                }
            }
        }

        return array_keys($paths);
    }

    /**
     * Suffixes naming a Dockerfile written for something other than running
     * the application in production. Ranked below NESTED_CANDIDATES, because
     * `docker/Dockerfile` is a likelier production build than `Dockerfile.dev`.
     *
     * @var list<string>
     */
    private const DEMOTED_VARIANTS = [
        'dev', 'develop', 'development', 'local', 'test', 'tests', 'ci',
        'builder', 'build', 'client', 'dispatcher', 'heroku', 'example', 'sample',
        // Authelia's root Dockerfile is the release image and is rejected for
        // other reasons, so detection fell through to Dockerfile.coverage --
        // `go build -tags dev -cover`, an instrumented binary that also swaps
        // the portal's CSP for the development one. A coverage build is never
        // what a site should run.
        'coverage',
    ];

    /**
     * Suffixes that say "this is the one to ship". Ranked above every other
     * variant so they beat whatever the directory listing happens to return
     * first.
     *
     * @var list<string>
     */
    private const PREFERRED_VARIANTS = ['prod', 'production', 'release', 'stable'];

    /**
     * Paths that might be a Dockerfile, best first: root `Dockerfile` (or
     * `Containerfile`), then PREFERRED_VARIANTS, then variants that say
     * nothing either way, then NESTED_CANDIDATES, then DEMOTED_VARIANTS.
     *
     * The ranking is the whole point. Variants used to be yielded in directory
     * order, so whichever the filesystem listed first won: `Dockerfile.client`
     * beat `Dockerfile.server.production`, `Dockerfile.heroku` beat
     * `docker/Dockerfile`, `Dockerfile.dev` beat `build/Dockerfile` and
     * `Dockerfile.latest` beat `Dockerfile.stable`. Each built something the
     * project never meant to run as its server, and the deploy then reported a
     * successful build of the wrong image.
     *
     * A multi-part suffix is judged on every part (`server.production` is
     * preferred, `server.dev` demoted); a part appearing in both lists is
     * demoted, since `Dockerfile.prod.test` is still a test file.
     *
     * @return Generator<string>
     */
    private function candidates(): Generator
    {
        $plain = [];
        $preferred = [];
        $neutral = [];
        $demoted = [];

        // The listing is lowercased, so names are taken from disk as well:
        // `Dockerfile.prod` has to be opened under the case it was written in.
        $names = [];
        foreach (array_keys($this->files) as $name) {
            if (str_starts_with((string) $name, 'dockerfile.')) {
                $names[(string) $name] = true;
            }
        }
        foreach (scandir($this->projectDir) ?: [] as $entry) {
            if (preg_match(self::NAME_PATTERN, $entry) === 1) {
                $names[$entry] = true;
            }
        }

        // From disk as well: a caller with no listing to hand passes [] --
        // PortsReport does -- and the loop below skips the plain name on the
        // assumption that this line took it (engine#258).
        if (isset($this->files[strtolower(self::ROOT_NAME)]) || isset($names[self::ROOT_NAME])) {
            $plain[] = self::ROOT_NAME;
        }

        foreach (array_keys($names) as $name) {
            $suffix = (string) strstr((string) $name, '.');
            if ($suffix === '') {
                // `Dockerfile` is already in $plain; this is `Containerfile`.
                if (strcasecmp((string) $name, self::ROOT_NAME) !== 0) {
                    $plain[] = (string) $name;
                }
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

        yield from $plain;
        yield from $preferred;
        yield from $neutral;
        yield from self::NESTED_CANDIDATES;
        yield from $demoted;
    }

    /**
     * A `COPY`/`ADD` source the build context does not contain: GoReleaser
     * copies a CI binary that is gitignored, and building fails late as
     * `failed to compute cache key`. A build arg counts by its literal prefix
     * (`build/` in `build/app-${VERSION}`) only; `--from` is another stage.
     */
    public static function missingContextSource(string $contents, string $contextDir): ?string
    {
        foreach (self::copyInstructions($contents) as $sources) {
            foreach ($sources as $source) {
                $required = self::literalPrefix(self::withPlatformArgs($source));
                if ($required === null) {
                    continue;
                }
                if (!file_exists(rtrim($contextDir, '/') . '/' . $required)) {
                    return $source;
                }
            }
        }

        return null;
    }

    /**
     * The sources of every `COPY`/`ADD` that reads from the build context.
     *
     * @return Generator<list<string>>
     */
    private static function copyInstructions(string $contents): Generator
    {
        // Continuations first: one instruction may span lines.
        $contents = self::withoutHeredocBodies($contents);
        $joined = preg_replace('/\\\\[ \t]*\r?\n/', ' ', $contents) ?? $contents;

        if (preg_match_all(self::COPY_LINE_PATTERN, $joined, $lines) === 0) {
            return;
        }

        foreach ($lines[1] as $line) {
            $argv = self::instructionArgv(trim($line));
            // `--from` means the source is another stage.
            $flags = array_filter($argv, static fn (string $a): bool => str_starts_with($a, '--'));
            foreach ($flags as $flag) {
                if (str_starts_with(strtolower($flag), '--from=')) {
                    continue 2;
                }
            }

            $paths = array_values(array_filter($argv, static fn (string $a): bool => !str_starts_with($a, '--')));
            // The last argument is the destination; one argument names nothing.
            array_pop($paths);
            if ($paths === []) {
                continue;
            }

            // `COPY <<EOF /dst` writes inline content, not from the context.
            yield array_values(array_filter(
                $paths,
                static fn (string $p): bool => !self::isRemote($p) && !str_starts_with($p, '<<')
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
     * The part of a source path that is certain, or null when none of it is:
     * `build/app-${TARGETOS}` as far as `build/`, `${DIR}/app` not at all.
     * Drop only a leading `./` -- `ltrim($source, './')` takes a character
     * class, and rejects a valid Dockerfile over `.env.template`.
     */
    private static function literalPrefix(string $source): ?string
    {
        // `.`, `./` and absolute `/app` all mean the context root: the
        // context is the whole build tree.
        $source = (string) preg_replace('#^(?:\./|/)+#', '', $source);
        if ($source === '' || $source === '.') {
            return null;
        }

        // A `..` *segment* escapes the context and cannot be verified; as a
        // substring it is not evidence of that (`a..b/c` is a legitimate name).
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
        $path = $this->projectDir . '/' . $relative;
        if (!is_file($path) || ComposeFileInspector::isHostUidMappedDockerfile($path)) {
            return false;
        }

        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            return false;
        }

        return self::missingContextSource($contents, $this->projectDir) === null;
    }
}
