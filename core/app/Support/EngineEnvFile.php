<?php

namespace App\Support;

use App\System;
use App\System\Privileged;
use App\System\Privileged\RootEngineCompose;

/**
 * One of the engine's settings files: `.env-core`, which core boots from, or compose's `.env`.
 *
 * `pae configure` runs as root and edits the file itself, as {@see EnvFile} always has. php-fpm and the queue run as
 * www-data, which may read `.env-core` (root:www-data 0640) but write neither file. Only compose's `.env` is
 * changed from the API, through the root broker, which takes the site database's three cache sizes and nothing else,
 * each value one word `.env` keeps unquoted; it reads them back through the broker too. `.env-core` is root's alone.
 */
final class EngineEnvFile
{
    public const CORE = '.env-core';

    public const COMPOSE = '.env';

    public function __construct(
        private readonly EnvFile $file,
        private readonly string $name,
        private readonly ?System $system = null,
        private readonly ?bool $direct = null,
    ) {}

    public static function core(?System $system = null): self
    {
        return new self(EnvFile::current(), self::CORE, $system);
    }

    public static function compose(?System $system = null): self
    {
        $system ??= new System;

        return new self(new EnvFile($system->engineDirPath().'/.env'), self::COMPOSE, $system);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function file(): EnvFile
    {
        return $this->file;
    }

    /** Whether this process may read the file itself. */
    public function readable(): bool
    {
        return $this->direct ?? is_readable($this->file->path());
    }

    /** The value a live line sets $key to, or null when none does. */
    public function get(string $key): ?string
    {
        return $this->values([$key])[$key];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, ?string> key => what a live line sets it to, null when none does
     */
    public function values(array $keys): array
    {
        if ($this->name === self::COMPOSE && ! $this->readable()) {
            return array_merge(array_fill_keys($keys, null), $this->brokerValues($keys));
        }

        return array_combine($keys, array_map($this->fromFile(...), $keys));
    }

    /** What a live line of the file sets $key to; null too when this process cannot read `.env-core`. */
    private function fromFile(string $key): ?string
    {
        return $this->readable() ? $this->file->get($key) : null;
    }

    /**
     * Sets keys to values in place, leaving every other line as it was.
     *
     * @param  array<string, string>  $values
     * @return list<string> the keys whose value changed
     */
    public function set(array $values): array
    {
        if (! $this->viaBroker()) {
            return array_values($this->file->set($values));
        }

        $current = $this->values(array_keys($values));
        $changed = array_filter($values, static fn (string $value, string $key): bool => $current[$key] !== $value, ARRAY_FILTER_USE_BOTH);
        if ($changed !== []) {
            $this->system()->exec($this->broker()->setEnv($changed));
        }

        return array_keys($changed);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function brokerValues(array $keys): array
    {
        $found = [];
        foreach (explode("\n", trim($this->system()->exec($this->broker()->envValues($keys)))) as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $found[$key] = $value;
            }
        }

        return $found;
    }

    /** Whether a change goes through the root broker: only compose's `.env`, and only when this process may not write it. */
    private function viaBroker(): bool
    {
        return $this->name === self::COMPOSE && ! ($this->direct ?? is_writable($this->file->path()));
    }

    private function broker(): RootEngineCompose
    {
        return Privileged::engineCompose($this->system()->engineDirPath());
    }

    private function system(): System
    {
        return $this->system ?? new System;
    }
}
