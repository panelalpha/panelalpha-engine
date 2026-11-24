<?php

namespace App\System\Privileged;

/** sites-db commands as shell lines, for callers that pipe them. The root password stays in sites-db. */
final class RootEngineComposeLines
{
    private const AREA = 'compose';

    private const DATABASE = '/\A[A-Za-z_-][A-Za-z0-9_-]*\z/';

    private const TABLE = '/\A[A-Za-z0-9_$]+\z/';

    private const ACCOUNT = '/\A[A-Za-z0-9_-]+\z/';

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

    /** Imported as $account, which every DEFINER is rewritten to, under a sign-in sites-db makes and takes back. */
    public function sitesDbCopy(string $from, string $to, ?string $table, string $account): string
    {
        return $this->line(__FUNCTION__, [
            self::database($from),
            self::database($to),
            $table === null ? '' : self::table($table),
            self::account($account),
        ]);
    }

    /** sitesDbCopy() of a dump taken from $from that the caller pipes in. */
    public function sitesDbRestoreCopy(string $from, string $to, string $account): string
    {
        return $this->line(__FUNCTION__, [self::database($from), self::database($to), self::account($account)]);
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
        if (strtolower($name) === 'root') {
            throw InvalidArgument::of('import account', $name);
        }

        return self::matching(self::ACCOUNT, 'MySQL user', $name);
    }

    private static function matching(string $pattern, string $kind, string $value): string
    {
        if (preg_match($pattern, $value) !== 1) {
            throw InvalidArgument::of($kind, $value);
        }

        return $value;
    }
}
