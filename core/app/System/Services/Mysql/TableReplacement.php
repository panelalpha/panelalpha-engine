<?php

namespace App\System\Services\Mysql;

/**
 * What a table-selection copy produced, and what a failure undoes: rollback dumps of replaced target tables, the
 * tables and databases created where the target had none, and the users granted on those databases.
 */
final class TableReplacement
{
    /**
     * @param  list<array{database: string, table: string, dump: string}>  $rollbacks
     * @param  list<string>  $createdDatabases
     * @param  list<array{database: string, table: string}>  $createdTables
     */
    public function __construct(
        public readonly DatabaseCopyResult $result,
        public readonly AccountMap $userMap,
        public readonly array $rollbacks = [],
        public readonly array $createdDatabases = [],
        public readonly array $createdTables = [],
    ) {}
}
