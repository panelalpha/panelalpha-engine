<?php

namespace App\Support;

use RuntimeException;

/**
 * Reads and edits the engine's `.env` one key at a time, leaving the rest of
 * the file as it was. Two constraints shape it:
 *
 *  - It is a **single-file bind mount** of the host's `.env-core`, bound by
 *    inode, so write-to-temp-and-rename would detach the mount and the edit
 *    would reach neither side. Rewritten in place, previous contents kept beside.
 *  - Its lines are documentation: a key is set by replacing the commented-out
 *    placeholder `.env-core.example` ships, not by appending a second copy.
 */
class EnvFile
{
    /** Where the previous contents go before a write, so a bad edit is recoverable. */
    public const BACKUP_SUFFIX = '.pae-backup';

    /** The container path `docker-compose.yml` maps the host's `.env-core` onto. */
    public const CORE_MOUNT = '/var/www/html/.env';

    public function __construct(private readonly string $path)
    {
    }

    /** The file this installation actually boots from. */
    public static function current(): self
    {
        return new self(app()->environmentFilePath());
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Whether this is the file the operator knows as `.env-core`. `pae` runs
     * inside the container, so the path it would otherwise print is one the
     * operator has never seen.
     */
    public function isCoreMount(): bool
    {
        return $this->path === self::CORE_MOUNT;
    }

    /**
     * Why writing here would probably change nothing, or null when this looks
     * like the file the engine boots from.
     *
     * Delete the host's `.env-core` and the single-file mount detaches: the
     * container falls back to whatever is at that path in its other mounts and
     * nothing breaks loudly, because compose passes the database credentials
     * as environment variables. `APP_URL` becoming `http://localhost` is the
     * visible symptom. A real engine environment always has `APP_KEY`, which
     * makes its absence the cheapest sign this file is not the one being read.
     */
    public function suspicious(): ?string
    {
        if (!$this->exists()) {
            return sprintf('There is no %s to write to.', $this->path);
        }

        if ($this->get('APP_KEY') !== null) {
            return null;
        }

        return sprintf(
            "%s has no APP_KEY, so it is almost certainly not the file this engine boots from.\n"
            . 'Writing here would change nothing. Restart the engine to reattach it: '
            . 'docker compose restart core',
            $this->path
        );
    }

    /**
     * The value a key is set to, or null when absent or commented out. Read
     * from the file, not `env()`: what is written down rather than what the
     * process booted with.
     */
    public function get(string $key): ?string
    {
        foreach ($this->lines() as $line) {
            if ($this->keyOf($line) === $key) {
                return $this->unquote(trim(substr($line, strpos($line, '=') + 1)));
            }
        }

        return null;
    }

    /**
     * Set keys to values, in place.
     *
     * @param array<string, string> $values
     * @return array<int, string> the keys whose value actually changed
     */
    public function set(array $values): array
    {
        if (!$this->exists()) {
            throw new RuntimeException("No .env file at {$this->path}.");
        }

        $original = $this->read();
        $lines = explode("\n", $original);
        $changed = [];

        foreach ($values as $key => $value) {
            if ($this->get($key) === $value) {
                continue;
            }

            $lines = $this->apply($lines, $key, $value);
            $changed[] = $key;
        }

        if ($changed === []) {
            return [];
        }

        $updated = implode("\n", $lines);

        // Kept beside the file rather than swapped in, for the inode reason
        // above; one rolling copy, so the state before the last edit is always
        // recoverable and the directory does not fill up with them.
        $this->write($this->path . self::BACKUP_SUFFIX, $original);
        $this->write($this->path, $updated);

        return $changed;
    }

    /**
     * Put `KEY=value` where the reader would expect to find it: over the live
     * line if there is one, over the commented placeholder the example file
     * ships if there is one, and at the end of the file otherwise.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private function apply(array $lines, string $key, string $value): array
    {
        $entry = $key . '=' . $this->quote($value);

        foreach ($lines as $i => $line) {
            if ($this->keyOf($line) === $key) {
                $lines[$i] = $entry;

                return $lines;
            }
        }

        foreach ($lines as $i => $line) {
            if ($this->commentedKeyOf($line) === $key) {
                $lines[$i] = $entry;

                return $lines;
            }
        }

        // A trailing blank line is normal; append after it, not before.
        while ($lines !== [] && trim(end($lines)) === '') {
            array_pop($lines);
        }

        $lines[] = '';
        $lines[] = $entry;
        $lines[] = '';

        return $lines;
    }

    /** The key a live line sets, or null when the line sets nothing. */
    private function keyOf(string $line): ?string
    {
        return preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $m) === 1
            ? $m[1]
            : null;
    }

    /** The key a commented-out placeholder stands for, as `# MCP_TOOLSETS=`. */
    private function commentedKeyOf(string $line): ?string
    {
        return preg_match('/^\s*#\s*([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $m) === 1
            ? $m[1]
            : null;
    }

    /**
     * Quote what dotenv would otherwise read as something else: anything with
     * whitespace, a `#` that would start a comment, or a quote of its own.
     */
    private function quote(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.\/@:,*+-]+$/', $value) === 1) {
            return $value;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private function unquote(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return str_replace(['\\"', '\\\\'], ['"', '\\'], substr($value, 1, -1));
        }

        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    private function read(): string
    {
        $contents = @file_get_contents($this->path);

        if ($contents === false) {
            throw new RuntimeException("Could not read {$this->path}.");
        }

        return $contents;
    }

    private function write(string $path, string $contents): void
    {
        if (@file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Could not write {$path}. Run this as the user that owns the file.");
        }
    }

    /** @return array<int, string> */
    private function lines(): array
    {
        return $this->exists() ? explode("\n", $this->read()) : [];
    }
}
