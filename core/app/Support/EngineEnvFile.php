<?php

namespace App\Support;

use App\System;

/**
 * One of the engine's settings files: `.env-core`, which core boots from, or compose's `.env`.
 *
 * `pae configure` runs as root and edits the file itself, as {@see EnvFile} always has. php-fpm and the queue run as
 * www-data, which may read `.env-core` (root:www-data 0640) but write neither file, and the root broker writes
 * neither on core's behalf: a value an API request reads comes from here, one it would set does not.
 */
final class EngineEnvFile
{
    public const CORE = '.env-core';

    public const COMPOSE = '.env';

    public function __construct(
        private readonly EnvFile $file,
        private readonly string $name,
    ) {}

    public static function core(): self
    {
        return new self(EnvFile::current(), self::CORE);
    }

    public static function compose(?System $system = null): self
    {
        $system ??= new System;

        return new self(new EnvFile($system->engineDirPath().'/.env'), self::COMPOSE);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function file(): EnvFile
    {
        return $this->file;
    }

    /** Whether this process may read the file; when it may not, every value reads as unset. */
    public function readable(): bool
    {
        return is_readable($this->file->path());
    }

    /** The value a live line sets $key to, or null when none does or the file cannot be read. */
    public function get(string $key): ?string
    {
        return $this->readable() ? $this->file->get($key) : null;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, ?string> key => what a live line sets it to, null when none does
     */
    public function values(array $keys): array
    {
        return array_combine($keys, array_map($this->get(...), $keys));
    }

    /**
     * Sets keys to values in place, leaving every other line as it was.
     *
     * @param  array<string, string>  $values
     * @return list<string> the keys whose value changed
     */
    public function set(array $values): array
    {
        return array_values($this->file->set($values));
    }
}
