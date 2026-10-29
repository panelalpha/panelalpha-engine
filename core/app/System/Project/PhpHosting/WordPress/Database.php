<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Named database operations for a PHP hosting WordPress install.
 *
 * Export and import act on the install's own database (table prefix and
 * credentials from wp-config) via `wp db export|import`. They do not
 * administer MySQL users or databases through the engine account API.
 *
 * Import strips DEFINER clauses from the SQL file first (same sed pattern as
 * app-lite AbstractDatabase::removeDefinerFromSqlFile), as an internal step —
 * not a named operation.
 *
 * The file of either is held inside the project ({@see WpCli::projectFile()}),
 * so neither reads nor writes a file of the host or of WP-CLI's container.
 */
final class Database
{
    /** A statement the MySQL or MariaDB client reports as rejected, one per line of its output. */
    public const REJECTED_STATEMENT_PATTERN = '/^ERROR \d{4}\b.*$/m';

    /** Rejected statements named in an import failure; the rest are only counted. */
    private const REJECTED_STATEMENTS_SHOWN = 5;

    /** The field a file outside the project is refused on. */
    private const FILE_FIELD = 'file';

    public function __construct(
        private WpCli $cli,
    ) {}

    /**
     * @param  list<string>  $tables  only these tables; [] = every table of the install
     * @return array{file: string, message: string}
     */
    public function export(string $file, array $tables = []): array
    {
        $file = $this->projectFile($file);
        $args = ['db', 'export', $file];
        if ($tables !== []) {
            $args[] = '--tables='.implode(',', $tables);
        }
        $args[] = '--exec='.self::ownerOnlyCode($file);
        $result = $this->cli->runOrFail($args);

        return [
            'file' => $file,
            'message' => trim($result['stdout']),
        ];
    }

    /**
     * PHP that WP-CLI runs before `db export`, in the same process: the dump
     * is created readable and writable by the user that writes it only, and a
     * file already there is narrowed the same way before it is overwritten.
     */
    private static function ownerOnlyCode(string $file): string
    {
        return sprintf(
            'umask(0077); if (is_file(%1$s) && ! @chmod(%1$s, 0600)) { WP_CLI::error(%2$s); }',
            var_export($file, true),
            var_export("Could not make {$file} private to its owner, so nothing was exported to it. Choose a file that does not exist yet.", true),
        );
    }

    /**
     * @return array{file: string, message: string}
     */
    public function import(string $file): array
    {
        $file = $this->projectFile($file);
        $result = $this->cli->runOrFail(['db', 'import', $file, '--exec='.self::removeDefinerCode($file)]);
        $this->refuseRejectedStatements($file, $result['stderr']);

        return [
            'file' => $file,
            'message' => trim($result['stdout']),
        ];
    }

    private function projectFile(string $file): string
    {
        return $this->cli->projectFile(self::FILE_FIELD, WpCli::positional($file));
    }

    /**
     * WP-CLI imports with `mysql -e "SOURCE file"`, and the client then goes on
     * past a statement MySQL rejects and still exits 0, so WP-CLI reports
     * success. The client's own error lines are what tell.
     */
    private function refuseRejectedStatements(string $file, string $stderr): void
    {
        $count = preg_match_all(self::REJECTED_STATEMENT_PATTERN, $stderr, $matches);
        if ($count === 0 || $count === false) {
            return;
        }

        $shown = array_slice($matches[0], 0, self::REJECTED_STATEMENTS_SHOWN);
        $more = $count > count($shown) ? "\n...and ".($count - count($shown)).' more.' : '';

        throw new Exception(
            "MySQL rejected {$count} statement(s) in {$file}, so it was not fully imported and the "
                ."database may hold part of it:\n".implode("\n", $shown).$more,
        );
    }

    /**
     * PHP that WP-CLI runs before `db import`, in the same process, so the
     * file is rewritten in the container and as the user that then reads it.
     * Strips DEFINER=`user`@`host` so the import does not fail when that MySQL
     * user is absent (same sed as app-lite's removeDefinerFromSqlFile), and
     * stops the import when the file has one and cannot be rewritten.
     */
    private static function removeDefinerCode(string $sqlFile): string
    {
        $file = escapeshellarg($sqlFile);
        $command = "grep -q 'DEFINER=' {$file} 2>/dev/null || exit 0; sed -i "
            .escapeshellarg('s/DEFINER=[^*]*\*/\*/g')." {$file} 2>&1";

        return sprintf(
            'exec(%s, $definerOutput, $definerStatus);'
            .' if ($definerStatus !== 0) { WP_CLI::error(%s . trim(implode("\n", $definerOutput))); }',
            var_export($command, true),
            var_export("Could not remove the DEFINER clauses from {$sqlFile}, so it was not imported: ", true),
        );
    }
}
