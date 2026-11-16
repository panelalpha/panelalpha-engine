<?php

namespace App\System\Project\PhpHosting\WordPress;

use App\System\Project\Dind\AppDatabase;

/**
 * `wp config list|get|set|delete`, which read wp-config.php without loading WordPress, and `config create`.
 * The type picks constants (the default), variables such as $table_prefix, or all.
 */
final class Config
{
    public const TYPES = ['constant', 'variable', 'all'];

    /**
     * The longest value WP-CLI writes and reads back: its wp-config reader stops at the PCRE limits, and its writer at
     * the pattern size, before this length is reached.
     */
    public const MAX_VALUE_LENGTH = 16384;

    public function __construct(
        private WpCli $cli,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $type = 'constant'): array
    {
        $this->assertType($type);
        $result = $this->cli->runOrFail(['config', 'list', '--format=json']);

        $entries = [];
        foreach (JsonOutput::decode($result['stdout']) as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ($type !== 'all' && ($row['type'] ?? null) !== $type) {
                continue;
            }
            $entries[] = $row;
        }

        return $entries;
    }

    /**
     * The value as wp-config.php defines it, read as JSON: a string, a boolean, a number, an array or null.
     *
     * @return array{constant: string, value: mixed}
     */
    public function get(string $name, string $type = 'constant'): array
    {
        $this->assertType($type);

        return [
            'constant' => $name,
            'value' => $this->readValue(['config', 'get', WpCli::positional($name), '--type='.$type]),
        ];
    }

    /**
     * With type all, WP-CLI updates whichever of the constant and the variable exists, and adds a constant otherwise.
     *
     * @return array{constant: string, value: string, message: string}
     */
    public function set(string $name, string $value, bool $raw = false, string $type = 'constant'): array
    {
        $this->assertType($type);
        self::assertValueLength($value);
        [$written, $raw] = self::writableValue($value, $raw);
        $args = ['config', 'set', WpCli::positional($name), $written, '--type='.$type];
        if ($raw) {
            $args[] = '--raw';
        }
        $result = $this->cli->runOrFail($args);

        return [
            'constant' => $name,
            'value' => $value,
            'message' => trim($result['stdout']),
        ];
    }

    /**
     * An existing file fails without force, WP-CLI checks the database without skip_check, and
     * {@see defaultDatabaseHost()} picks a missing dbhost; {@see WpCli::createConfigFile()} keeps the file private.
     *
     * @param array{
     *     dbname: string,
     *     dbuser: string,
     *     dbpass?: string,
     *     dbhost?: string,
     *     dbprefix?: string,
     *     dbcharset?: string,
     *     dbcollate?: string,
     *     locale?: string,
     *     force?: bool,
     *     skip_check?: bool,
     * } $fields
     * @return array{message: string}
     */
    public function create(array $fields): array
    {
        $fields['dbhost'] ??= $this->defaultDatabaseHost();
        $options = [];
        foreach (['dbname', 'dbuser', 'dbpass', 'dbhost', 'dbprefix', 'dbcharset', 'dbcollate', 'locale'] as $key) {
            if (isset($fields[$key])) {
                self::assertValueLength((string) $fields[$key]);
                $options[] = "--{$key}=".$fields[$key];
            }
        }
        if (! empty($fields['force'])) {
            $options[] = '--force';
        }
        if (! empty($fields['skip_check'])) {
            $options[] = '--skip-check';
        }
        $result = $this->cli->createConfigFile($options);

        return [
            'message' => trim($result['stdout']),
        ];
    }

    /**
     * PHP hosting: the projects' MySQL server, as the engine's installer writes (localhost is the PHP container there).
     * Own containers: the host wp-config.php already names, its compose database service; without one null, WP-CLI's localhost.
     */
    private function defaultDatabaseHost(): ?string
    {
        if (! $this->cli->runsInContainer()) {
            return AppDatabase::hostname();
        }

        $current = $this->cli->run(['config', 'get', 'DB_HOST']);
        $host = trim($current['stdout']);

        return $current['exit_code'] === 0 && $host !== '' ? $host : null;
    }

    /**
     * @return array{constant: string, message: string}
     */
    public function delete(string $name, string $type = 'constant'): array
    {
        $this->assertType($type);
        $result = $this->cli->runOrFail(
            ['config', 'delete', WpCli::positional($name), '--type='.$type],
        );

        return [
            'constant' => $name,
            'message' => trim($result['stdout']),
        ];
    }

    /**
     * WP-CLI would read a value starting with '-' as an option, so it goes as
     * the raw PHP that writes the same wp-config.php line.
     *
     * @return array{string, bool}
     */
    private static function writableValue(string $value, bool $raw): array
    {
        if (! str_starts_with($value, '-')) {
            return [$value, $raw];
        }

        return [$raw ? '('.$value.')' : var_export($value, true), true];
    }

    /**
     * A value JSON cannot carry, such as a string that is not UTF-8, reads as the plain output prints it.
     *
     * @param  list<string>  $args
     */
    private function readValue(array $args): mixed
    {
        $value = json_decode(trim($this->cli->runOrFail([...$args, '--format=json'])['stdout']), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $value;
        }

        return trim($this->cli->runOrFail($args)['stdout']);
    }

    private function assertType(string $type): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new Exception("Invalid wp-config type: {$type}.");
        }
    }

    /** A longer value is one the list cannot read back, so it is refused before anything is written. */
    private static function assertValueLength(string $value): void
    {
        if (strlen($value) > self::MAX_VALUE_LENGTH) {
            throw new Exception(
                'A wp-config.php value can be at most '.self::MAX_VALUE_LENGTH.' bytes; this one is '.strlen($value).'.'
            );
        }
    }
}
