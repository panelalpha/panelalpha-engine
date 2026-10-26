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
 * not a named operation. HTTP, artisan, and MCP tools are adapters. Path
 * resolution and the runner come from WpCli; a non-zero exit becomes Exception.
 */
final class Database
{
    public function __construct(
        private WpCli $cli,
    ) {}

    /**
     * @param  list<string>  $tables  only these tables; [] = every table of the install
     * @return array{file: string, message: string}
     */
    public function export(string $file, array $tables = []): array
    {
        $args = ['db', 'export', $file];
        if ($tables !== []) {
            $args[] = '--tables='.implode(',', $tables);
        }
        $result = $this->cli->runOrFail($args);

        return [
            'file' => $file,
            'message' => trim($result['stdout']),
        ];
    }

    /**
     * @return array{file: string, message: string}
     */
    public function import(string $file): array
    {
        $this->removeDefinerFromSqlFile($file);
        $result = $this->cli->runOrFail(['db', 'import', $file]);

        return [
            'file' => $file,
            'message' => trim($result['stdout']),
        ];
    }

    /**
     * Strip DEFINER=`user`@`host` from CREATE statements so import does not
     * fail when that MySQL user is absent. Mirrors app-lite's
     * removeDefinerFromSqlFile; kept private so it is not a named operation.
     *
     * --skip-wordpress: the database is often empty or the site broken at
     * this point (staging, clone, restore), and sed needs neither.
     */
    private function removeDefinerFromSqlFile(string $sqlFile): void
    {
        // Same sed as app-lite AbstractDatabase::removeDefinerFromSqlFile.
        $command = 'sed -i '.escapeshellarg('s/DEFINER=[^*]*\*/\*/g').' '.escapeshellarg($sqlFile);
        $this->cli->runOrFail(['eval', 'exec('.var_export($command, true).');', '--skip-wordpress']);
    }
}
