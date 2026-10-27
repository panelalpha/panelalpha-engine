<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Named search-replace for a PHP hosting WordPress install.
 *
 * Maps to `wp search-replace {search} {replacement} ...extra`. Extra args are
 * table names and the flags below only: WP-CLI honours its global options,
 * --exec among them, from any position, so anything else is refused.
 */
final class SearchReplace
{
    public const SWITCHES = ['--dry-run', '--precise', '--all-tables', '--all-tables-with-prefix', '--regex'];

    /** Flag => grammar of its value. */
    public const VALUED = [
        '--skip-columns' => '[A-Za-z0-9_$]+(,[A-Za-z0-9_$]+)*',
        '--regex-delimiter' => '[^A-Za-z0-9\\\\\s]',
    ];

    /** A table name, or a pattern with WP-CLI's * wildcard. */
    public const TABLE = '[A-Za-z0-9_$*][A-Za-z0-9_$*-]*';

    public function __construct(
        private WpCli $cli,
    ) {}

    /**
     * @param  list<string>  $extra
     * @return array{search: string, replacement: string, message: string}
     */
    public function run(string $search, string $replacement, array $extra = []): array
    {
        $args = ['search-replace', self::positional($search), self::positional($replacement), ...self::allowed($extra)];
        $result = $this->cli->runOrFail($args);

        return [
            'search' => $search,
            'replacement' => $replacement,
            'message' => trim($result['stdout']),
        ];
    }

    /** Whether an extra argument is a table name or one of the allowed flags. */
    public static function accepts(string $argument): bool
    {
        if (in_array($argument, self::SWITCHES, true)) {
            return true;
        }
        foreach (self::VALUED as $flag => $grammar) {
            if (preg_match('~^'.$flag.'=(?:'.$grammar.')$~D', $argument) === 1) {
                return true;
            }
        }

        return preg_match('~^'.self::TABLE.'$~D', $argument) === 1;
    }

    /** The allowed flags, as a caller writes them. */
    public static function flags(): string
    {
        $valued = array_map(static fn (string $flag): string => $flag.'=…', array_keys(self::VALUED));

        return implode(', ', [...self::SWITCHES, ...$valued]);
    }

    /** WP-CLI reads an argument starting with '-' as an option wherever it stands. */
    private static function positional(string $value): string
    {
        if (str_starts_with($value, '-')) {
            throw new Exception("'{$value}' starts with '-', which WP-CLI would read as an option.");
        }

        return $value;
    }

    /**
     * @param  list<string>  $extra
     * @return list<string>
     */
    private static function allowed(array $extra): array
    {
        foreach ($extra as $argument) {
            if (! self::accepts($argument)) {
                throw new Exception("'{$argument}' is not a table name or one of: ".self::flags().'.');
            }
        }

        return $extra;
    }
}
