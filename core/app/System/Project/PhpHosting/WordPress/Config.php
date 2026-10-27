<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Named wp-config operations for a PHP hosting WordPress install.
 *
 * List/read/set/delete map to `wp config list|get|set|delete`. The type picks
 * constants (the default), variables such as $table_prefix, or all; for set,
 * all updates whichever exists and adds a constant otherwise. These commands
 * read wp-config.php without loading WordPress. Create writes a new
 * wp-config.php from database credentials.
 */
final class Config
{
    public const TYPES = ['constant', 'variable', 'all'];

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
     * @param  bool  $json  decode the value from `--format=json` (arrays, e.g. WP_REDIS_CONFIG)
     * @return array{constant: string, value: mixed}
     */
    public function get(string $name, string $type = 'constant', bool $json = false): array
    {
        $this->assertType($type);
        $args = ['config', 'get', WpCli::positional($name), '--type='.$type];
        if ($json) {
            $args[] = '--format=json';
        }
        $result = $this->cli->runOrFail($args);

        return [
            'constant' => $name,
            'value' => $json ? json_decode(trim($result['stdout']), true) : trim($result['stdout']),
        ];
    }

    /**
     * @return array{constant: string, value: string, message: string}
     */
    public function set(string $name, string $value, bool $raw = false, string $type = 'constant'): array
    {
        $this->assertType($type);
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
     * Writes a new wp-config.php (WP-CLI `config create`), e.g. after a core
     * download. Without force an existing file is a failure; without
     * skip_check WP-CLI first connects to the database.
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
        $args = ['config', 'create'];
        foreach (['dbname', 'dbuser', 'dbpass', 'dbhost', 'dbprefix', 'dbcharset', 'dbcollate', 'locale'] as $key) {
            if (isset($fields[$key])) {
                $args[] = "--{$key}=".$fields[$key];
            }
        }
        if (! empty($fields['force'])) {
            $args[] = '--force';
        }
        if (! empty($fields['skip_check'])) {
            $args[] = '--skip-check';
        }
        $result = $this->cli->runOrFail($args);

        return [
            'message' => trim($result['stdout']),
        ];
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

    private function assertType(string $type): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new Exception("Invalid wp-config type: {$type}.");
        }
    }
}
