<?php

namespace App\System\Privileged;

/** sites-db commands as shell lines, for callers that pipe them. The root password stays in sites-db. */
final class RootEngineComposeLines
{
    private const AREA = 'compose';

    private const DATABASE = '/\A[A-Za-z_-][A-Za-z0-9_-]*\z/';

    private const TABLE = '/\A[A-Za-z0-9_$]+\z/';

    private const ACCOUNT = '/\A[A-Za-z0-9_-]+\z/';

    private const ORPHAN = '/\A[A-Za-z0-9_-]*no\.definer\z/';

    public function __construct(private readonly string $projectDirectory) {}

    /** A whole database is dumped with its routines, triggers and events. */
    public function sitesDbDump(string $database, ?string $table): string
    {
        return $this->line(__FUNCTION__, [self::database($database), $table === null ? '' : self::table($table)]);
    }

    public function sitesDbRestore(string $database): string
    {
        return $this->line(__FUNCTION__, [self::database($database)]);
    }

    /**
     * Rewrites the schema name, and each DEFINER to its mapped user or to $orphan.
     *
     * @param  array<string, string>  $definers  source user => destination user
     */
    public function sitesDbCopy(string $from, string $to, ?string $table, string $orphan, array $definers): string
    {
        foreach ($definers as $source => $destination) {
            self::account((string) $source);
            self::account($destination);
        }

        return $this->line(__FUNCTION__, [
            self::database($from),
            self::database($to),
            $table === null ? '' : self::table($table),
            self::orphan($orphan),
            ...ActionValues::pairs($definers),
        ]);
    }

    public function sitesDbDropTable(string $database, string $table): string
    {
        return $this->line(__FUNCTION__, [self::database($database), self::table($table)]);
    }

    /** @param  list<string>  $values */
    private function line(string $action, array $values): string
    {
        return Root::actionLine(self::AREA, $action, [Arguments::path($this->projectDirectory), ...$values]);
    }

    private static function database(string $name): string
    {
        return self::matching(self::DATABASE, 'database', $name);
    }

    private static function table(string $name): string
    {
        return self::matching(self::TABLE, 'table', $name);
    }

    private static function account(string $name): string
    {
        return self::matching(self::ACCOUNT, 'MySQL user', $name);
    }

    private static function orphan(string $name): string
    {
        return self::matching(self::ORPHAN, 'orphan DEFINER', $name);
    }

    private static function matching(string $pattern, string $kind, string $value): string
    {
        if (preg_match($pattern, $value) !== 1) {
            throw InvalidArgument::of($kind, $value);
        }

        return $value;
    }
}
