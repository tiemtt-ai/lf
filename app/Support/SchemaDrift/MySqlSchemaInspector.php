<?php

namespace App\Support\SchemaDrift;

use Illuminate\Database\ConnectionInterface;

class MySqlSchemaInspector
{
    public function inspect(ConnectionInterface $connection): array
    {
        $schema = $connection->getSchemaBuilder();
        $database = (string) $connection->getDatabaseName();
        $tables = [];

        $databaseTables = array_filter(
            $schema->getTables(),
            fn ($metadata) => ($metadata['schema'] ?? $database) === $database
        );

        foreach ($databaseTables as $metadata) {
            $name = $metadata['name'];
            $columns = array_map(fn ($column) => [
                'name' => $column['name'],
                'type' => strtolower((string) ($column['type_name'] ?? $column['type'] ?? '')),
                'nullable' => (bool) ($column['nullable'] ?? false),
                'default' => $column['default'] ?? null,
                'unsigned' => str_contains(strtolower((string) ($column['type'] ?? '')), 'unsigned'),
                'auto_increment' => (bool) ($column['auto_increment'] ?? false),
                'generated' => $column['generation'] ?? null,
            ], $schema->getColumns($name));
            $indexes = $schema->getIndexes($name);
            $primary = collect($indexes)->first(fn ($index) => (bool) ($index['primary'] ?? false));
            $normalIndexes = collect($indexes)->reject(fn ($index) => (bool) ($index['primary'] ?? false))->map(fn ($index) => [
                'columns' => $index['columns'],
                'unique' => (bool) ($index['unique'] ?? false),
                'type' => strtolower((string) ($index['type'] ?? 'btree')),
            ])->values()->all();
            $foreignKeys = array_map(fn ($foreign) => [
                'columns' => $foreign['columns'],
                'foreign_table' => $foreign['foreign_table'],
                'foreign_columns' => $foreign['foreign_columns'],
                'on_update' => strtolower((string) ($foreign['on_update'] ?? 'no action')),
                'on_delete' => strtolower((string) ($foreign['on_delete'] ?? 'no action')),
            ], $schema->getForeignKeys($name));
            $checks = collect($connection->select(
                'SELECT cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME WHERE tc.CONSTRAINT_SCHEMA = ? AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = ?',
                [$database, $name, 'CHECK']
            ))->pluck('CHECK_CLAUSE')->unique()->map(fn ($expression) => ['expression' => $expression])->values()->all();
            foreach ($columns as &$column) {
                if ($column['type'] === 'longtext' && collect($checks)->contains(
                    fn ($check) => preg_match('/json_valid\s*\(\s*`?'.preg_quote($column['name'], '/').'`?\s*\)/i', $check['expression']) === 1
                )) {
                    $column['type'] = 'json';
                }
            }
            unset($column);
            $triggers = array_map(fn ($row) => [
                'name' => strtolower($row->TRIGGER_NAME),
                'timing' => strtolower($row->ACTION_TIMING),
                'event' => strtolower($row->EVENT_MANIPULATION),
                'statement' => preg_replace('/\s+/', ' ', trim($row->ACTION_STATEMENT)),
            ], $connection->select(
                'SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?',
                [$database, $name]
            ));
            $tables[] = [
                'name' => $name,
                'columns' => $columns,
                'primary_key' => $primary['columns'] ?? [],
                'indexes' => $normalIndexes,
                'foreign_keys' => $foreignKeys,
                'checks' => $checks,
                'triggers' => $triggers,
                'views' => [],
            ];
        }

        $views = array_map(
            fn ($view) => ['name' => $view['name'], 'definition' => $view['definition'] ?? null],
            array_filter($schema->getViews(), fn ($view) => ($view['schema'] ?? $database) === $database)
        );

        return ['database_family' => 'mysql', 'tables' => $tables, 'views' => $views];
    }
}
