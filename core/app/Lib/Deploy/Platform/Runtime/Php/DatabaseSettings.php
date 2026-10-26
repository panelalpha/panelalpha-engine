<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

/**
 * The database the account was given, with the defaults a container needs
 * when a field came back blank.
 */
final class DatabaseSettings
{
    private const MYSQL_DRIVERS = ['mysql', 'mariadb'];

    private const DEFAULTS = [
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'app',
        'username' => 'root',
    ];

    /**
     * @param  array<string, mixed>  $db
     */
    private function __construct(private readonly array $db) {}

    /**
     * @param  array{connection?: string, host?: string, port?: string, database?: string, username?: string, password?: string}  $db
     */
    public static function fromArray(array $db): self
    {
        return new self($db);
    }

    public function isMysql(): bool
    {
        return in_array($this->connection(), self::MYSQL_DRIVERS, true);
    }

    /** mariadb speaks the mysql driver; Laravel has no separate connection for it. */
    public function driver(): string
    {
        return $this->connection() === 'mariadb' ? 'mysql' : $this->connection();
    }

    public function connection(): string
    {
        return strtolower(trim((string) ($this->db['connection'] ?? '')));
    }

    public function host(): string
    {
        return $this->value('host');
    }

    public function port(): string
    {
        return $this->value('port');
    }

    public function database(): string
    {
        return $this->value('database');
    }

    public function username(): string
    {
        return $this->value('username');
    }

    public function password(): string
    {
        return (string) ($this->db['password'] ?? '');
    }

    private function value(string $key): string
    {
        return trim((string) ($this->db[$key] ?? '')) ?: self::DEFAULTS[$key];
    }
}
