<?php

namespace App\System\Services\Mysql;

use App\Models\User;
use App\System\Services\Mysql;

/**
 * Which tables of a source project differ from its paired target: structure by
 * `SHOW CREATE TABLE`, data by `CHECKSUM TABLE`.
 */
class TableDiff
{
    public function __construct(
        private readonly Mysql $mysql,
    ) {}

    /**
     * The names are the source project's, so they can be handed straight back to
     * a push that selects tables.
     *
     * @return array{
     *     tables_structure_diff: list<array{database: string, name: string}>,
     *     tables_data_diff: list<array{database: string, name: string}>
     * }
     */
    public function between(User $source, User $target): array
    {
        $tables = $this->mysql->tables();
        $sourcePrefix = $source->getMysqlPrefix();
        $targetPrefix = $target->getMysqlPrefix();
        $structure = [];
        $data = [];

        foreach ($source->mysqlDatabases as $row) {
            $from = $row->database;
            $to = DatabaseCopier::mapName($from, $sourcePrefix, $targetPrefix);
            $targetNames = $tables->names($to);

            foreach ($tables->names($from) as $name) {
                $difference = $this->tableDifference($tables, $from, $to, $name, $targetNames);
                if ($difference['structure']) {
                    $structure[] = ['database' => $from, 'name' => $name];
                }
                if ($difference['data']) {
                    $data[] = ['database' => $from, 'name' => $name];
                }
            }
        }

        return [
            'tables_structure_diff' => $structure,
            'tables_data_diff' => $data,
        ];
    }

    /**
     * A table the target does not hold at all is a structure difference; its
     * data is not compared, because there is nothing to compare against.
     *
     * @param  list<string>  $targetNames
     * @return array{structure: bool, data: bool}
     */
    private function tableDifference(
        Tables $tables,
        string $from,
        string $to,
        string $name,
        array $targetNames,
    ): array {
        if (! in_array($name, $targetNames, true)) {
            return ['structure' => true, 'data' => false];
        }

        return [
            'structure' => $tables->createStatement($from, $name) !== $tables->createStatement($to, $name),
            'data' => $tables->checksum($from, $name) !== $tables->checksum($to, $name),
        ];
    }
}
