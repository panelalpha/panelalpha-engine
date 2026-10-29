<?php

namespace App\Lib\Deploy\Platform;

/**
 * Reads one field out of a manifest and reports which manifest and which
 * field when it cannot. A new field in `PlatformManifest::fromArray()` is one
 * line here, not another four-line validation block.
 */
final class ManifestReader
{
    private const IDENTIFIER = '/^[a-z0-9][a-z0-9-]*$/';

    private const MIN_PORT = 1;

    private const MAX_PORT = 65535;

    /**
     * @param  array<string, mixed>  $raw
     * @param  string  $subject  how errors name this manifest — its file before
     *                           an id has been read, its id afterwards
     */
    public function __construct(private readonly array $raw, private string $subject) {}

    /** Name later errors after the manifest's own id. */
    public function nameErrorsAfter(string $subject): void
    {
        $this->subject = $subject;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->raw);
    }

    public function raw(string $key): mixed
    {
        return $this->raw[$key] ?? null;
    }

    /**
     * @param  list<string>  $known
     *
     * @throws ManifestException on a key nobody defined
     */
    public function assertNoUnknownKeys(array $known): void
    {
        $unknown = array_diff(array_keys($this->raw), $known);
        if ($unknown !== []) {
            throw $this->fail(
                'unknown key(s) '.implode(', ', $unknown).'; expected one of '.implode(', ', $known)
            );
        }
    }

    /**
     * @throws ManifestException
     */
    public function identifier(string $key, ?string $default = null): string
    {
        $value = $this->raw($key) ?? $default;
        if (! is_string($value) || preg_match(self::IDENTIFIER, $value) !== 1) {
            throw $this->fail("'{$key}' must be a lowercase kebab-case string");
        }

        return $value;
    }

    /**
     * @throws ManifestException
     */
    public function text(string $key): string
    {
        $value = $this->raw($key);
        if (! is_string($value) || trim($value) === '') {
            throw $this->fail("'{$key}' must be a non-empty string");
        }

        return trim($value);
    }

    /**
     * @throws ManifestException
     */
    public function optionalText(string $key, string $expectation): ?string
    {
        $value = $this->raw($key);
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || trim($value) === '') {
            throw $this->fail("'{$key}' {$expectation}");
        }

        return trim($value);
    }

    /**
     * @throws ManifestException
     */
    public function integer(string $key, string $expectation): int
    {
        $value = $this->raw($key);
        if (! is_int($value)) {
            throw $this->fail("'{$key}' {$expectation}");
        }

        return $value;
    }

    /**
     * The same check without a default: absent stays absent, so a manifest
     * that says nothing about a key is distinguishable from one that picked
     * the default.
     *
     * @param  list<string>  $allowed
     *
     * @throws ManifestException
     */
    public function optionalEnum(string $key, array $allowed): ?string
    {
        $value = $this->raw($key);
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw $this->fail("'{$key}' must be one of ".implode(', ', $allowed));
        }

        return $value;
    }

    /**
     * @param  list<string>  $allowed
     *
     * @throws ManifestException
     */
    public function enum(string $key, array $allowed, string $default): string
    {
        $value = $this->raw($key) ?? $default;
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw $this->fail("'{$key}' must be one of ".implode(', ', $allowed));
        }

        return $value;
    }

    /**
     * @throws ManifestException
     */
    public function port(string $key): ?int
    {
        $value = $this->raw($key);
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < self::MIN_PORT || $value > self::MAX_PORT) {
            throw $this->fail("'{$key}' must be a TCP port number");
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ManifestException
     */
    public function object(string $key, string $expectation, bool $required = false): array
    {
        $value = $this->raw($key) ?? ($required ? null : []);
        if (! is_array($value) || ($required && $value === [])) {
            throw $this->fail("'{$key}' {$expectation}");
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function passthrough(string $key): array
    {
        $value = $this->raw($key);

        return is_array($value) ? $value : [];
    }

    public function fail(string $message): ManifestException
    {
        return new ManifestException("{$this->subject}: {$message}");
    }
}
