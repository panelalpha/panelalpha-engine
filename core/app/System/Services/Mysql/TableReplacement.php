<?php

namespace App\System\Services\Mysql;

/**
 * What a table-selection copy produced, and what a failure has to undo: the
 * rollback dump of every target table that was replaced, the tables that were
 * created because the target had none, and the databases created for them.
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
        public readonly array $rollbacks = [],
        public readonly array $createdDatabases = [],
        public readonly array $createdTables = [],
    ) {}
}
