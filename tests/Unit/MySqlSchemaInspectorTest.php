<?php

namespace Tests\Unit;

use App\Support\SchemaDrift\MySqlSchemaInspector;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Builder;
use Mockery;
use PHPUnit\Framework\TestCase;

class MySqlSchemaInspectorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_inspects_only_the_selected_database_schema(): void
    {
        $schema = Mockery::mock(Builder::class);
        $schema->shouldReceive('getTables')->once()->andReturn([
            ['name' => 'selected_table', 'schema' => 'learnforge_db'],
            ['name' => 'foreign_table', 'schema' => 'another_database'],
        ]);
        $schema->shouldReceive('getColumns')->once()->with('selected_table')->andReturn([]);
        $schema->shouldReceive('getIndexes')->once()->with('selected_table')->andReturn([]);
        $schema->shouldReceive('getForeignKeys')->once()->with('selected_table')->andReturn([]);
        $schema->shouldReceive('getViews')->once()->andReturn([
            ['name' => 'selected_view', 'schema' => 'learnforge_db', 'definition' => 'select 1'],
            ['name' => 'foreign_view', 'schema' => 'another_database', 'definition' => 'select 2'],
        ]);

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('learnforge_db');
        $connection->shouldReceive('select')->twice()->andReturn([]);

        $result = (new MySqlSchemaInspector)->inspect($connection);

        $this->assertSame(['selected_table'], array_column($result['tables'], 'name'));
        $this->assertSame(['selected_view'], array_column($result['views'], 'name'));
    }
}
