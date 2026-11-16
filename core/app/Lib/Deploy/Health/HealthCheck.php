<?php

namespace App\Lib\Deploy\Health;

/**
 * One question asked of a deployed application's own response, from `resources/checks/<group>/<id>.yaml`.
 * `path` and `json` exist because an API answers elsewhere than `/`, as JSON.
 */
final class HealthCheck
{
    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_INFO = 'info';

    /** @var list<string> */
    private const SEVERITIES = [self::SEVERITY_ERROR, self::SEVERITY_WARNING, self::SEVERITY_INFO];

    /** @var list<string> */
    private const EXPECT_KEYS = ['status', 'status_not', 'body', 'body_not', 'path', 'json'];

    /** The one key the check carries but `expect` does not: which URL to ask. */
    public const DEFAULT_PATH = '/';

    /** @var list<string> */
    private const KNOWN_KEYS = ['$schema', 'id', 'severity', 'expect', 'when', 'landing', 'message', 'fix', 'serving', 'explain'];

    /**
     * @param  array<string, mixed>  $expect
     * @param  array<string, mixed>|null  $when
     * @param  list<string>  $landing  asked only when the probe's redirects from `/` ended under one of these; empty: always
     */
    private function __construct(
        public readonly string $group,
        public readonly string $id,
        public readonly string $severity,
        public readonly array $expect,
        public readonly ?array $when,
        public readonly string $message,
        public readonly ?string $fix,
        public readonly ?string $serving,
        public readonly ?string $explain,
        public readonly string $source,
        public readonly array $landing = []
    ) {}

    /** How a manifest and a report name this check: `<group>/<id>`. */
    public function reference(): string
    {
        return $this->group.'/'.$this->id;
    }

    /** The URL this check is asked about, as a path and query: `/` unless the check names another. */
    public function path(): string
    {
        $path = $this->expect['path'] ?? null;

        return is_string($path) && $path !== '' ? $path : self::DEFAULT_PATH;
    }

    /**
     * The JSON members this check requires of the body, or an empty array.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $json = $this->expect['json'] ?? null;

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $raw  decoded check file
     *
     * @throws CheckException
     */
    public static function fromArray(array $raw, string $group, string $source): self
    {
        self::assertKnownKeys($raw, $source);
        $id = self::idOf($raw, $source);
        $where = self::whereOf($source, $group, $id);
        $message = self::messageOf($raw, $where);
        $severity = self::severityOf($raw, $group, $id);
        $expect = self::expectOf($raw, $group, $id);
        $when = self::whenOf($raw, $group, $id);
        self::assertExpectsSomething($expect, $group, $id);
        self::assertPath($expect, $group, $id);
        self::assertJson($expect, $group, $id);
        $landing = self::landing($raw, $group, $id);

        return new self(
            $group,
            $id,
            $severity,
            $expect,
            is_array($when) ? $when : null,
            trim($message),
            self::optionalString($raw, 'fix', $group, $id),
            self::optionalString($raw, 'serving', $group, $id),
            self::optionalString($raw, 'explain', $group, $id),
            $source,
            $landing
        );
    }

    /** @param  array<string, mixed>  $raw */
    private static function assertKnownKeys(array $raw, string $source): void
    {
        $unknown = array_diff(array_keys($raw), self::KNOWN_KEYS);
        if ($unknown !== []) {
            throw new CheckException("{$source}: unknown key(s) ".implode(', ', $unknown));
        }
    }

    /** @param  array<string, mixed>  $raw */
    private static function idOf(array $raw, string $source): string
    {
        $id = $raw['id'] ?? null;
        if (! is_string($id) || preg_match('/^[a-z0-9][a-z0-9-]*$/', $id) !== 1) {
            throw new CheckException("{$source}: 'id' must be a lowercase slug");
        }

        return $id;
    }

    /** The file's own name where there is one, since a recipe can ship checks too; `group/id` otherwise. */
    private static function whereOf(string $source, string $group, string $id): string
    {
        return $source !== '' ? $source : "{$group}/{$id}";
    }

    /** @param  array<string, mixed>  $raw */
    private static function messageOf(array $raw, string $where): string
    {
        $message = $raw['message'] ?? null;
        if (! is_string($message) || trim($message) === '') {
            throw new CheckException("{$where}: 'message' must be a non-empty string");
        }

        return $message;
    }

    /** @param  array<string, mixed>  $raw */
    private static function severityOf(array $raw, string $group, string $id): string
    {
        $severity = $raw['severity'] ?? self::SEVERITY_ERROR;
        if (! is_string($severity) || ! in_array($severity, self::SEVERITIES, true)) {
            throw new CheckException(
                "{$group}/{$id}: 'severity' must be one of ".implode(', ', self::SEVERITIES)
            );
        }

        return $severity;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function expectOf(array $raw, string $group, string $id): array
    {
        $expect = $raw['expect'] ?? [];
        if (! is_array($expect)) {
            throw new CheckException("{$group}/{$id}: 'expect' must be an object");
        }
        $unknownExpect = array_diff(array_keys($expect), self::EXPECT_KEYS);
        if ($unknownExpect !== []) {
            throw new CheckException(
                "{$group}/{$id}: unknown expect key(s) ".implode(', ', $unknownExpect)
                .'; a question needing more than '.implode(', ', self::EXPECT_KEYS).' belongs in an explain class'
            );
        }

        return $expect;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private static function whenOf(array $raw, string $group, string $id): ?array
    {
        $when = $raw['when'] ?? null;
        if ($when !== null && ! is_array($when)) {
            throw new CheckException("{$group}/{$id}: 'when' must be an object");
        }

        return $when;
    }

    /**
     * A check that asserts nothing passes for every application: `path` alone asserts nothing, `json` does.
     *
     * @param  array<string, mixed>  $expect
     */
    private static function assertExpectsSomething(array $expect, string $group, string $id): void
    {
        if ($expect === [] || array_keys($expect) === ['path']) {
            throw new CheckException("{$group}/{$id}: 'expect' declares no condition, so the check can never fail");
        }
    }

    /**
     * `path` must be a local path, since the probe dials loopback; a query string is allowed.
     * `//host` is protocol-relative to curl, and whitespace or a newline would forge a probe line.
     *
     * @param  array<string, mixed>  $expect
     */
    private static function assertPath(array $expect, string $group, string $id): void
    {
        if (! array_key_exists('path', $expect)) {
            return;
        }

        $path = $expect['path'];
        if (! is_string($path) || $path === '' || $path[0] !== '/') {
            throw new CheckException(
                "{$group}/{$id}: 'path' must be a local path beginning with '/', not a URL"
            );
        }
        if (str_starts_with($path, '//')) {
            throw new CheckException("{$group}/{$id}: 'path' must not begin with '//'");
        }
        if (preg_match('/[\s\x00-\x1f]/', $path) === 1) {
            throw new CheckException("{$group}/{$id}: 'path' must not contain whitespace or control characters");
        }
    }

    /**
     * `landing` is a non-empty list of local paths, the same shape `path` must have.
     *
     * @param  array<string, mixed>  $raw
     * @return list<string>
     */
    private static function landing(array $raw, string $group, string $id): array
    {
        if (! array_key_exists('landing', $raw)) {
            return [];
        }

        $landing = $raw['landing'];
        if (! is_array($landing) || $landing === [] || ! array_is_list($landing)) {
            throw new CheckException("{$group}/{$id}: 'landing' must be a non-empty list of paths");
        }
        foreach ($landing as $path) {
            if (! is_string($path) || ! str_starts_with($path, '/') || preg_match('/[\s\x00-\x1f]/', $path) === 1) {
                throw new CheckException("{$group}/{$id}: 'landing' paths must begin with '/' and hold no whitespace");
            }
        }

        return $landing;
    }

    /**
     * `json` must be a non-empty flat object of scalars; a list is read as "any of these".
     *
     * @param  array<string, mixed>  $expect
     */
    private static function assertJson(array $expect, string $group, string $id): void
    {
        if (! array_key_exists('json', $expect)) {
            return;
        }

        $json = $expect['json'];
        if (! is_array($json) || $json === []) {
            throw new CheckException("{$group}/{$id}: 'json' must be a non-empty object");
        }
        if (array_is_list($json)) {
            throw new CheckException("{$group}/{$id}: 'json' must be an object of members, not a list");
        }

        foreach ($json as $key => $wanted) {
            if (! is_string($key) || $key === '') {
                throw new CheckException("{$group}/{$id}: 'json' member names must be non-empty strings");
            }
            if (is_array($wanted)) {
                self::assertJsonAlternatives($wanted, $key, $group, $id);

                continue;
            }
            self::assertJsonScalar($wanted, $key, $group, $id);
        }
    }

    /**
     * A list is "any of these"; a mapping is a shape claim and is refused.
     *
     * @param  array<mixed>  $wanted
     */
    private static function assertJsonAlternatives(array $wanted, string $key, string $group, string $id): void
    {
        if (! array_is_list($wanted)) {
            throw new CheckException(
                "{$group}/{$id}: 'json.{$key}' must not be nested — a member compared as an object "
                .'belongs in an explain class'
            );
        }
        if ($wanted === []) {
            throw new CheckException("{$group}/{$id}: 'json.{$key}' must name at least one value");
        }
        foreach ($wanted as $one) {
            self::assertJsonScalar($one, "{$key}", $group, $id);
        }
    }

    private static function assertJsonScalar(mixed $value, string $key, string $group, string $id): void
    {
        if (is_scalar($value)) {
            return;
        }

        throw new CheckException(
            "{$group}/{$id}: 'json.{$key}' must be a string, number or boolean — "
            .'a member compared as an object or array belongs in an explain class'
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function optionalString(array $raw, string $key, string $group, string $id): ?string
    {
        $value = $raw[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || trim($value) === '') {
            throw new CheckException("{$group}/{$id}: '{$key}' must be a non-empty string when present");
        }

        return trim($value);
    }
}
