<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Moves a WordPress database from one host to another. Only addresses on the
 * host itself change: `//host` and its JSON-escaped form `\/\/host`, followed
 * by anything but a letter, digit, dot or hyphen. A longer host that starts or
 * ends with it, an e-mail address and the guid column stay as they are. Runs
 * over every table with the WordPress prefix, plugin tables included, or only
 * the tables given.
 */
final class HostRewrite
{
    public const DELIMITER = '~';

    private const SLASHES = '(//|\\\\/\\\\/)';

    private const BOUNDARY = '(?![A-Za-z0-9.-])';

    /** @param list<string>|null $tables null: every table with the WordPress prefix */
    public function __construct(
        private WordPress $wordPress,
        private ?array $tables = null,
    ) {}

    /**
     * Limited to the pushed tables that are this WordPress's own: in its
     * database and with its table prefix. Null pushed tables: every table.
     *
     * @param  list<array{database: string, name: string}>|null  $pushed
     */
    public static function ofPushedTables(WordPress $wordPress, ?array $pushed): self
    {
        if ($pushed === null) {
            return new self($wordPress);
        }
        $config = $wordPress->config();
        $database = (string) $config->get('DB_NAME')['value'];
        $prefix = (string) $config->get('table_prefix', 'variable')['value'];

        return new self($wordPress, self::ownTables($pushed, $database, $prefix));
    }

    /** @param list<string> $hosts */
    public function run(array $hosts, string $newHost): void
    {
        if ($this->tables === []) {
            return;
        }
        foreach ($hosts as $host) {
            if ($host !== $newHost) {
                $this->wordPress->searchReplace()->run(
                    self::pattern($host),
                    self::replacement($newHost),
                    $this->arguments(),
                );
            }
        }
    }

    /** The WP-CLI regex, without delimiters, for an address on exactly this host. */
    public static function pattern(string $host): string
    {
        return self::SLASHES.preg_quote($host, self::DELIMITER).self::BOUNDARY;
    }

    public static function replacement(string $newHost): string
    {
        return '${1}'.$newHost;
    }

    /** @return list<string> */
    public function arguments(): array
    {
        return [
            ...($this->tables ?? []),
            '--regex',
            '--regex-delimiter='.self::DELIMITER,
            '--all-tables-with-prefix',
            '--precise',
            '--skip-columns=guid',
        ];
    }

    /**
     * @param  list<array{database: string, name: string}>  $pushed
     * @return list<string>
     */
    private static function ownTables(array $pushed, string $database, string $prefix): array
    {
        $tables = [];
        foreach ($pushed as $table) {
            if ($table['database'] === $database && str_starts_with($table['name'], $prefix)) {
                $tables[] = $table['name'];
            }
        }

        return array_values(array_unique($tables));
    }
}
