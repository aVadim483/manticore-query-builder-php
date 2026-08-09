<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\Schema\SchemaTable;
use PHPUnit\Framework\TestCase;

/**
 * CREATE TABLE DSL: the schema renders itself into the column list and table options.
 */
final class SchemaTableTest extends TestCase
{
    public function testEveryColumnTypeIsRendered(): void
    {
        $schema = new SchemaTable();
        $schema->timestamp('created_at');
        $schema->string('manufacturer');
        $schema->text('title');
        $schema->json('info');
        $schema->float('price');
        $schema->multi('categories');
        $schema->multi64('big');
        $schema->bool('on_sale');
        $schema->integer('qty');
        $schema->bigint('huge');

        $this->assertSame(
            '(created_at timestamp, manufacturer string, title text, info json, price float,'
            . ' categories multi, big multi64, on_sale bool, qty integer, huge bigint)',
            (string)$schema
        );
    }

    public function testSchemaFromArray(): void
    {
        $schema = new SchemaTable([
            'created_at' => ['type' => 'timestamp'],
            'price' => ['type' => 'float'],
        ]);

        $this->assertSame('(created_at timestamp, price float)', (string)$schema);
    }

    public function testColumnOptionsFromArray(): void
    {
        $schema = new SchemaTable();
        $schema->string('code', ['indexed']);

        $this->assertSame('(code string indexed)', (string)$schema);
    }

    public function testColumnOptionsFromString(): void
    {
        $schema = new SchemaTable();
        $schema->text('title', 'indexed');

        $this->assertSame('(title text indexed)', (string)$schema);
    }

    public function testSeveralColumnOptionsFromString(): void
    {
        $schema = new SchemaTable();
        $schema->text('title', 'indexed stored');

        $this->assertSame('(title text indexed stored)', (string)$schema);
    }

    public function testTableEngine(): void
    {
        $schema = new SchemaTable();
        $schema->text('title');
        $schema->tableEngine('columnar');

        $this->assertSame("(title text) engine='columnar'", (string)$schema);
    }

    public function testTableMorphologyFromString(): void
    {
        $schema = new SchemaTable();
        $schema->text('title');
        $schema->tableMorphology('lemmatize_en_all');

        $this->assertSame("(title text) morphology='lemmatize_en_all'", (string)$schema);
    }

    public function testTableMorphologyFromArrayIsJoined(): void
    {
        $schema = new SchemaTable();
        $schema->text('title');
        $schema->tableMorphology(['stem_en', 'stem_ru']);

        $this->assertSame("(title text) morphology='stem_en,stem_ru'", (string)$schema);
    }

    public function testMorphologyIsAliasOfTableMorphology(): void
    {
        $schema = new SchemaTable();
        $schema->text('title');
        $schema->morphology('stem_en');

        $this->assertSame("(title text) morphology='stem_en'", (string)$schema);
    }

    public function testTableOptions(): void
    {
        $schema = new SchemaTable();
        $schema->text('title');
        $schema->tableOptions(['min_stemming_len' => 5, 'html_strip' => 1]);

        $this->assertSame("(title text) min_stemming_len='5' html_strip='1'", (string)$schema);
    }

    public function testTableOptionsExtractEngineAndMorphology(): void
    {
        $schema = new SchemaTable();
        $schema->text('title');
        $schema->tableOptions(['engine' => 'columnar', 'morphology' => 'stem_en', 'html_strip' => 1]);

        $this->assertSame(
            "(title text) engine='columnar' morphology='stem_en' html_strip='1'",
            (string)$schema
        );
    }

    public function testTableOptionsAcceptKeyValueStrings(): void
    {
        $schema = new SchemaTable();
        $schema->text('title');
        $schema->tableOptions(['min_stemming_len=5']);

        $this->assertSame("(title text) min_stemming_len='5'", (string)$schema);
    }

    public function testTableOptionsStripSurroundingQuotes(): void
    {
        $schema = new SchemaTable();
        $schema->text('title');
        $schema->tableOptions(['html_index_attrs' => "'img=alt,title'"]);

        $this->assertSame("(title text) html_index_attrs='img=alt,title'", (string)$schema);
    }

    public function testTableOptionsEscapeQuotes(): void
    {
        $schema = new SchemaTable();
        $schema->text('title');
        $schema->tableOptions(['blend_chars' => "it's"]);

        $this->assertSame("(title text) blend_chars='it\\'s'", (string)$schema);
    }

    public function testTableOptionsReplacePreviousCall(): void
    {
        $schema = new SchemaTable();
        $schema->text('title');
        $schema->tableOptions(['html_strip' => 1]);
        $schema->tableOptions(['min_stemming_len' => 3]);

        $this->assertSame("(title text) min_stemming_len='3'", (string)$schema);
    }

    public function testTypeWithInlineOptions(): void
    {
        $schema = new SchemaTable();
        $schema->addColumn('title', 'text indexed');

        $this->assertSame('(title text indexed)', (string)$schema);
    }

    public function testAddColumnReturnsColumnForChaining(): void
    {
        $schema = new SchemaTable();
        $column = $schema->text('title');

        $this->assertInstanceOf(\avadim\Manticore\QueryBuilder\Schema\SchemaColumn::class, $column);
    }

    public function testEmptySchemaRendersEmptyColumnList(): void
    {
        $this->assertSame('()', (string)(new SchemaTable()));
    }
}
