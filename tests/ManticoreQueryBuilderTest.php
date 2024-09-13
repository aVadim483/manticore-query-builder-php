<?php

declare(strict_types=1);

require_once __DIR__ . '/Logger.php';

use PHPUnit\Framework\TestCase;
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;

final class ManticoreQueryBuilderTest extends TestCase
{
    protected function getClientConfig(): array
    {
        return [
            'defaultConnection' => 'test1',
            'connections' => [
                // Default connection which will be used with environment variables
                'test1' => [
                    'host' => 'localhost',
                    'port' => 9306,
                    'username' => null,
                    'password' => null,
                    'timeout' => 5,
                    'prefix' => 'test_', // prefix that will replace the placeholder "?<table_name>"
                    'force_prefix' => false,
                ],

                // Second connection which will use list of hosts and minimal settings
                'test2'  => [
                    'host' => 'localhost',
                    'port' => 9306,
                    'prefix' => 'second_', // prefix that will replace the placeholder "?<table_name>"
                ],

            ],
        ];
    }


    public function testCreateAndDrop()
    {
        $table = 'test1_' . uniqid() . '_products';
        $config = $this->getClientConfig();

        ManticoreDb::init($config);

        $fields = [
            'created_at' => 'timestamp',
            'manufacturer' => 'string',
            'title' => 'text',
            'info' => 'json',
            'price' => ['type' => 'float'],
            'categories' => 'multi',
            'on_sale' => 'bool',
        ];

        $options = [
            'charset_table' => 'cjk',
            'morphology' => 'icu_chinese',
        ];

        $res = ManticoreDb::drop($table);
        $this->assertFalse($res->success());

        $res = ManticoreDb::dropIfExists($table);
        $this->assertTrue($res->success());

        $res = ManticoreDb::table($table)->options($options)->create($fields);
        $this->assertTrue($res->result());

        $res = ManticoreDb::tableSettings($table);
        $this->assertEquals('cjk', $res['charset_table']);
        $this->assertEquals('icu_chinese', $res['morphology']);

        $res = ManticoreDb::tableStatus($table);
        $this->assertEquals('rt', $res['index_type']);
        $this->assertEquals(0, $res['indexed_documents']);

        $res = ManticoreDb::tableDescribe($table);
        foreach ($res as $col => $data) {
            if ($col === 'id') {
                $this->assertEquals('bigint', $data['Type']);
            }
            else {
                $type = (is_array($fields[$col]) ? $fields[$col]['type'] : $fields[$col]);
                $this->assertEquals($type === 'multi' ? 'mva' : $type, $data['Type']);
            }
        }

        $res = ManticoreDb::showTables($table);
        $this->assertEquals($table, $res[0]['Index']);
        $this->assertEquals($table, $res[0]['Table']);
        $this->assertEquals($table, $res[0]['Name']);
        $this->assertEquals('rt', $res[0]['Type']);

        $insertData = [
            [
                'created_at' => time(),
                'manufacturer' => 'Samsung',
                'title' => 'Galaxy S23 Ultra',
                'info' => ['color' => 'Red', 'storage' => 512],
                'price' => 1199.00,
                'categories' => [5, 7, 11],
                'on_sale' => true,
            ],
            [
                'created_at' => time(),
                'manufacturer' => 'Xiaomi',
                'title' => 'Redmi 12C',
                'info' => ['color' => 'Green', 'storage' => 256],
                'price' => 988.99,
                'categories' => [1, 7, 9],
                'on_sale' => false,
            ],
        ];
        foreach ($insertData as $record) {
            $res = ManticoreDb::table($table)->insert($record);
            $this->assertTrue(is_int($res->result()));
        }

        $res = ManticoreDb::table($table)->drop();
        $this->assertTrue($res->result());
    }


    public function testCreateAndDropWithPrefix()
    {
        $prefix1 = 'conn1_' . uniqid() . '_';
        $prefix2 = 'conn2_' . uniqid() . '_';
        $config = $this->getClientConfig();
        $config['connections']['test1']['prefix'] = $prefix1;
        $config['connections']['test2']['prefix'] = $prefix2;

        ManticoreDb::init($config);

        $fields = [
            'created_at' => 'timestamp',
            'manufacturer' => 'string',
            'title' => 'text',
            'info' => 'json',
            'price' => ['type' => 'float'],
            'categories' => 'multi',
            'on_sale' => 'bool',
        ];

        $options = [
            'charset_table' => 'cjk',
            'morphology' => 'icu_chinese',
        ];

        // default connection "test1"
        ManticoreDb::table('?products')->drop(true);
        $res = ManticoreDb::table('?products')->options($options)->create($fields);
        $this->assertTrue($res->result());

        $res = ManticoreDb::tableSettings('?products');
        $this->assertEquals('cjk', $res['charset_table']);
        $this->assertEquals('icu_chinese', $res['morphology']);

        $res = ManticoreDb::tableStatus('?products');
        $this->assertEquals('rt', $res['index_type']);
        $this->assertEquals(0, $res['indexed_documents']);

        $res = ManticoreDb::tableDescribe('?products');
        foreach ($res as $col => $data) {
            if ($col === 'id') {
                $this->assertEquals('bigint', $data['Type']);
            }
            else {
                $type = (is_array($fields[$col]) ? $fields[$col]['type'] : $fields[$col]);
                $this->assertEquals($type === 'multi' ? 'mva' : $type, $data['Type']);
            }
        }

        // connection "test2"
        ManticoreDb::connection('test2')->table('?products')->drop(true);
        $res = ManticoreDb::connection('test2')->create('?products', function (SchemaTable $table) {
            $table->timestamp('created_at');
            $table->string('manufacturer');
            $table->text('title');
            $table->json('info');
            $table->float('price');
            $table->multi('categories');
            $table->bool('on_sale');

            $table->tableMorphology('lemmatize_en_all');
            $table->tableOptions(['min_stemming_len' => 5, 'html_strip' => 1, 'html_index_attrs' => 'img=alt,title; a=title;']);
        });
        $this->assertTrue($res->result());

        $res = ManticoreDb::connection('test2')->tableSettings('?products');
        $this->assertEquals('lemmatize_en_all', $res['morphology']);
        $this->assertEquals('5', $res['min_stemming_len']);
        $this->assertEquals('1', $res['html_strip']);
        $this->assertEquals('img=alt,title; a=title;', $res['html_index_attrs']);

        // default connection "test1"
        $res = ManticoreDb::showTables('?%');
        $found = 0;
        foreach ($res as $row) {
            if ($row['Index'] === $prefix1 . 'products' && $row['Name'] === '?products') {
                $found = 1;
                break;
            }
        }
        $this->assertTrue($found === 1);

        // connection "test2"
        $res = ManticoreDb::connection('test2')->showTables('?%');
        $found = 0;
        foreach ($res as $row) {
            if ($row['Index'] === $prefix2 . 'products' && $row['Name'] === '?products') {
                $found = 1;
                break;
            }
        }
        $this->assertTrue($found === 1);

        $insertData = [
            [
                'created_at' => time(),
                'manufacturer' => 'Samsung',
                'title' => 'Galaxy S23 Ultra',
                'info' => ['color' => 'Red', 'storage' => 512],
                'price' => 1199.00,
                'categories' => [5, 7, 11],
                'on_sale' => true,
            ],
            [
                'created_at' => time(),
                'manufacturer' => 'Xiaomi',
                'title' => 'Redmi 12C',
                'info' => ['color' => 'Green', 'storage' => 256],
                'price' => 988.99,
                'categories' => [1, 7, 9],
                'on_sale' => false,
            ],
        ];
        foreach ($insertData as $record) {
            $res = ManticoreDb::table('?products')->insert($record);
            $this->assertTrue(is_int($res->result()));
        }

        // default connection "test1"
        $res = ManticoreDb::table('?products')->drop();
        $this->assertTrue($res->result());

        // connection "test2"
        $res = ManticoreDb::connection('test2')->index('?products')->drop();
        $this->assertTrue($res->result());
    }


    public function testJson()
    {
        $table = 'test2_' . uniqid() . '_json';
        ManticoreDb::table($table)->drop(true);
        ManticoreDb::table($table)->create([
            'name' => 'string',
            'metadata' => 'json',
        ]);
        $insert = [
            [
                'name' => 'Product One',
                'metadata' => [
                    "locations" => [
                        ["lat" => 23.000000,"long"=>46.500000,"stock"=>30],
                        ["lat"=>24.000000,"long"=>47.500000,"stock"=>20],
                        ["lat"=>24.500000,"long"=>47.500000,"stock"=>10]
                    ],
                    "color" => ["blue","black","yellow"],
                    "price" => 210.00,
                    "cpu"=> ["model"=>"Kyro 345","cores"=>8,"chipset"=>"snapdragon 845"],
                    "video_rec"=>[1080,720],
                    "memory"=>32,
                ],
            ],
            [
                'name' => 'Product Two',
                'metadata' => [
                    "locations" => [
                        ["lat" => 23.100000, "long"=>46.600000, "stock"=>10],
                        ["lat"=>24.000000, "long"=>47.500000, "stock"=>0],
                        ["lat"=>24.300000, "long"=>47.550000, "stock"=>10]
                    ],
                    "color" => ["white", "black", "blue"],
                    "price"=>410.00,
                    "cpu"=> ["model"=>"Cortex A75","cores"=>8,"chipset"=>"Exynos"],
                    "video_rec"=>[2016, 1080],
                    "memory"=>64,
                ],
            ],
            [
                'name' => 'Product Thre',
                'metadata' => [
                    "locations" => [
                        ["lat" => 23.100000, "long"=>46.600000, "stock"=> 0],
                        ["lat"=>24.000000, "long"=>47.500000, "stock"=> 0],
                        ["lat"=>24.300000, "long"=>47.550000, "stock"=> 0]
                    ],
                    "color" => ["black"],
                    "price" => 360.00,
                    "cpu"=> ["model"=>"Cortex A53","cores" => 8,"chipset" => "Exynos"],
                    "video_rec"=>[2016, 1080],
                    "memory"=>64,
                ],
            ],
        ];
        ManticoreDb::table($table)->insert($insert);

        $result = ManticoreDb::table($table)->where('DOUBLE(metadata.price)>250')->count();
        $this->assertEquals(2, $result);

        $result = ManticoreDb::table($table)->where('DOUBLE(metadata.price)>:price')
            ->bind([':price' => 250])
            ->count();
        $this->assertEquals(2, $result);

        $result = ManticoreDb::table($table)->where('metadata.cpu.model', 'Kyro 345')->count();
        $this->assertEquals(1, $result);

        $result = ManticoreDb::table($table)->where('regex(metadata.cpu.model, \'Kyro*\')')->count();
        $this->assertEquals(1, $result);

        $result = ManticoreDb::table($table)->select(['name', 'ANY(x.stock > 0 AND GEODIST(23.0,46.5, DOUBLE(x.lat), DOUBLE(x.long), {out=mi}) < 10 FOR x IN metadata.locations) AS close_to_you'])->get();
        $rec = reset($result);
        $this->assertEquals(1, $rec['close_to_you']);
        $rec = next($result);
        $this->assertEquals(0, $rec['close_to_you']);
        $rec = next($result);
        $this->assertEquals(0, $rec['close_to_you']);

        $result = ManticoreDb::table($table)->select(['metadata.memory', 'count(*) as cnt'])->groupBy('metadata.memory')->get();
        $this->assertEquals(32, $result[0]['metadata.memory']);
        $this->assertEquals(1, $result[0]['cnt']);
        $this->assertEquals(64, $result[1]['metadata.memory']);
        $this->assertEquals(2, $result[1]['cnt']);

        $result = ManticoreDb::table($table)->select(['metadata.video_rec[0] as g', 'count(*)as c'])->groupBy('g')->get();
        $this->assertEquals('1080', $result[0]['g']);
        $this->assertEquals(1, $result[0]['c']);
        $this->assertEquals('2016', $result[1]['g']);
        $this->assertEquals(2, $result[1]['c']);

        $result = ManticoreDb::table($table)->select(['*', 'IN(metadata.color, :black, :white) as color_filter'])
            ->where('color_filter=1')
            ->bind([':black' => 'black', ':white' => 'white'])
            ->get();
        $this->assertCount(3, $result);

        ManticoreDb::table($table)->drop();
    }


    protected function dataFullText(): array
    {
        $i = 1;
        return [
            [
                'id' => $i++,
                'title' => 'find me',
                'content' => 'fast and quick',
            ],
            [
                'id' => $i++,
                'title' => 'find me fast',
                'content' => 'quick',
            ],
            [
                'id' => $i++,
                'title' => 'find me slow',
                'content' => 'quick',
            ],
            [
                'id' => $i++,
                'title' => 'The quick brown fox jumps over the lazy dog',
                'content' => 'The five boxing wizards jump quickly',
            ],
            [
                'id' => $i++,
                'title' => 'find me quick and fast',
                'content' => 'quick',
            ],
            [
                'id' => $i++,
                'title' => 'find me fast now',
                'content' => 'quick',
            ],
            [
                'id' => $i++,
                'title' => 'The quick brown fox takes a step back and jumps over the lazy dog',
                'content' => 'The five boxing wizards jump quickly',
            ],
            [
                'id' => $i++,
                'title' => 'The brown and beautiful fox takes a step back and jumps over the lazy dog',
                'content' => 'The five boxing wizards jump quickly',
            ],
            [
                'id' => $i++,
                'title' => '<h1>Samsung Galaxy S10</h1><div>Is a smartphone introduced by Samsung in 2019</div>',
                'content' => '',
            ],
            [
                'id' => $i++,
                'title' => '<h1>Samsung</h1><div>Galaxy,Note,A,J</div>',
                'content' => '',
            ],
            [
                'id' => $i++,
                'title' => 'Hello world',
                'content' => '',
            ],
            [
                'id' => $i++,
                'title' => '<h1>Hello</h1> <h1>world</h1>',
                'content' => '',
            ],
            [
                'id' => $i++,
                'title' => '<h1>Hello world</h1>',
                'content' => '',
            ],
            [
                'id' => $i++,
                'title' => 'The brown fox takes a step back. Then it jumps over the lazy dog',
                'content' => '',
            ],
        ];
    }


    public function testFullText()
    {
        $table = 'test1_' . uniqid();
        ManticoreDb::table($table)->drop(true);
        ManticoreDb::table($table)->create([
            'title' => 'text',
            'content' => 'text',
        ]);
        $insert = $this->dataFullText();
        ManticoreDb::table($table)->insert($insert);

        $res = ManticoreDb::table($table)->match('find me fast')->get();
        $this->assertSame([1, 2, 6, 5], array_column($res, 'id'));

        $res = ManticoreDb::table($table)->match('find me !fast')->get();
        $this->assertSame([3], array_column($res, 'id'));

        $res = ManticoreDb::table($table)->match('find me MAYBE slow')->get();
        $this->assertSame([3, 1, 2, 5, 6], array_column($res, 'id'));

        $res = ManticoreDb::table($table)->match('@title find me fast')->get();
        $this->assertSame([2, 6, 5], array_column($res, 'id'));

        $res = ManticoreDb::table($table)->match('@title lazy dog')->get();
        $this->assertSame([4, 7, 8, 14], array_column($res, 'id'));

        $res = ManticoreDb::table($table)->match('@title[5] lazy dog')->get();
        $this->assertEmpty($res);

        $res = ManticoreDb::table($table)->match('@@relaxed @(title,keywords) lazy dog')->get();
        $this->assertSame([4, 7, 8, 14], array_column($res, 'id'));

        $res = ManticoreDb::table($table)->match('"fox bird lazy dog"/3')->get();
        $this->assertSame([4, 7, 8, 14], array_column($res, 'id'));

        $res = ManticoreDb::table($table)->match('"find me fast"')->get();
        $this->assertSame([2, 6], array_column($res, 'id'));

        $res = ManticoreDb::table($table)->match('find << me << fast')->get();
        $this->assertSame([2, 6, 5], array_column($res, 'id'));

        $res = ManticoreDb::table($table)->match('"brown fox" NOTNEAR/5 jumps')->get();
        $this->assertSame([7, 14], array_column($res, 'id'));

        ManticoreDb::table($table)->drop();
    }


    protected function dataWhere(): array
    {
        $fields = [
            'time' => 'timestamp',
            'demo' => 'bool',
            'country' => 'string',
            'price' => 'float',
            'content' => 'text',
            'sizes' => 'multi',
            'values' => 'multi64',
        ];
        $i = 1;
        $inserts = [
            [
                'id' => $i++,
                'time' => time(),
                'demo' => false,
                'country' => 'US',
                'price' => 100.00,
                'content' => 'lorem ipsum',
                'sizes' => [1, 3, 5],
                'values' => [0, -1, 1],
            ],
            [
                'id' => $i++,
                'time' => time(),
                'demo' => false,
                'country' => 'DE',
                'price' => 200.00,
                'content' => 'Lorem ipsum dolor sit amet',
                'sizes' => [2, 4, 6],
                'values' => [PHP_INT_MIN, 0, PHP_INT_MAX],
            ],
            [
                'id' => $i++,
                'time' => time(),
                'demo' => true,
                'country' => 'US',
                'price' => 300.00,
                'content' => 'ipsum dolor sit amet',
                'sizes' => [1, 2, 3],
                'values' => [9, 36, 223, 372, 775, 807, 854],
            ],
            [
                'id' => $i++,
                'time' => time(),
                'demo' => false,
                'country' => 'DE',
                'price' => 180.00,
                'content' => 'amet',
                'sizes' => [4, 5, 6],
                'values' => [0, PHP_INT_MAX],
            ],
            [
                'id' => $i++,
                'time' => time(),
                'demo' => true,
                'country' => 'UK',
                'price' => 230.00,
                'content' => 'dolor sit',
                'sizes' => [1, 2, 3, 4, 5, 6],
                'values' => [PHP_INT_MIN],
            ],
            [
                'id' => $i++,
                'time' => time(),
                'demo' => false,
                'country' => 'UK',
                'price' => 310.00,
                'content' => 'ipsum dolor sit',
                'sizes' => [],
                'values' => [18, 446, 744, 73, 709, 551, 615],
            ],
            [
                'id' => $i++,
                'time' => time(),
                'demo' => false,
                'country' => 'DE',
                'price' => 185.00,
                'content' => 'ipsum',
                'sizes' => [2],
                'values' => [4, 294, 967, 295, 0],
            ],
            [
                'id' => $i++,
                'time' => time(),
                'demo' => true,
                'country' => 'US',
                'price' => 298.00,
                'content' => 'dolor sit',
                'sizes' => [4],
                'values' => [0, -2147483648],
            ],
        ];

        return ['fields' => $fields, 'inserts' => $inserts];
    }


    public function testWhere()
    {
        $data = $this->dataWhere();;
        $table = 'test1_' . uniqid();
        ManticoreDb::table($table)->drop(true);
        ManticoreDb::table($table)->create($data['fields']);
        ManticoreDb::table($table)->insert($data['inserts']);

        $res = ManticoreDb::table($table)->match('ipsum')
            ->where('country', 'de')
            ->get();
        $this->assertSame([2, 7], array_column($res, 'id'));

        $res = ManticoreDb::table($table)->match('ipsum')
            ->where('country', 'de')
            ->orWhere('price', '>', 150)
            ->get();
        $this->assertSame([2, 3, 6, 7], array_column($res, 'id'));

        $res = ManticoreDb::table($table)->match('ipsum')
            ->where(function ($cond) {
                $cond->where('country', 'de');
                $cond->orWhere('price', '>', 150);
            })
            ->get();
        $this->assertSame([2, 3, 6, 7], array_column($res, 'id'));

        $res = ManticoreDb::table($table)->match('ipsum')
            ->whereIn('country', ['de', 'us'])
            ->get();
        $this->assertSame([1, 2, 3,7], array_column($res, 'id'));

        $res = ManticoreDb::table($table)
            ->where('country', '!=', '12')
            ->where('price', '>', 250)
            ->pluck('id');
        $this->assertSame([3, 6, 8], array_values($res));

        $res = ManticoreDb::table($table)
            ->where('country', '!=', 'us')
            ->count();
        $this->assertSame(5, $res);

        $res = ManticoreDb::table($table)
            ->where('country=:de')
            ->bind([':de' => 'de'])
            ->get();
        $this->assertSame([2, 4, 7], array_column($res, 'id'));

        $res = ManticoreDb::table($table)
            ->where('demo', true)
            ->get();
        $this->assertSame([3, 5, 8], array_column($res, 'id'));

        $res = ManticoreDb::table($table)
            ->where('demo', 0)
            ->get();
        $this->assertSame([1, 2, 4, 6, 7], array_column($res, 'id'));

        $res = ManticoreDb::table($table)
            ->where('ANY(sizes)', 6)
            ->pluck('id');
        $this->assertSame([2, 4, 5], array_values($res));

        $res = ManticoreDb::table($table)
            ->where('ALL(values)', '>', 0)
            ->pluck('id');
        $this->assertSame([3, 6], array_values($res));

        $res = ManticoreDb::table($table)
            ->where('ANY(values)', PHP_INT_MIN)
            ->limit(1)
            ->orderByDesc('id')
            ->pluck('id');
        $this->assertSame([5], array_values($res));

        ManticoreDb::table($table)->drop();
    }


    public function testLogger()
    {
        $t1 = 'test1_' . uniqid() . '_';
        $t2 = 'test2_' . uniqid() . '_';
        $config = $this->getClientConfig();
        ManticoreDb::init($config);

        $fields = [
            'time' => 'timestamp',
            'demo' => 'bool',
            'country' => 'string',
        ];
        $insert = [
            'time' => time(),
            'demo' => true,
            'country' => 'US',
        ];

        $logger = new \Logger();
        ManticoreDb::table($t1)->drop(true);
        ManticoreDb::connection('test2')->table($t2)->drop(true);

        // without logging
        $res = ManticoreDb::table($t1)->create($fields);
        $res = ManticoreDb::connection('test2')->table($t2)->create($fields);
        $this->assertSame('created', $res->status());
        $this->assertSame([], $logger->data);

        // enable logging
        ManticoreDb::setLogger($logger);
        ManticoreDb::table($t1)->insert($insert);
        $this->assertSame('info', $logger->data[0]['level']);

        $logger->reset();
        ManticoreDb::connection('test2')->table($t2)->insert($insert);
        $this->assertSame('info', $logger->data[0]['level']);

        // disable logging
        $logger->reset();
        ManticoreDb::setLogger(false);
        ManticoreDb::table($t1)->insert($insert);
        $this->assertSame([], $logger->data);

        ManticoreDb::connection('test2')->table($t2)->insert($insert);
        $this->assertSame([], $logger->data);

        // enable logging for 'default' and disable for 'test2'
        $logger->reset();
        ManticoreDb::setLogger($logger);
        ManticoreDb::table($t1)->insert($insert);
        $this->assertSame('info', $logger->data[0]['level']);

        $logger->reset();
        ManticoreDb::connection('test2')->setLogger(false)->table($t2)->insert($insert);
        $this->assertSame([], $logger->data);

        // enable logging for one request
        $logger->reset();
        ManticoreDb::setLogger(false);
        ManticoreDb::table($t1)->insert($insert);
        $this->assertSame([], $logger->data);

        ManticoreDb::connection('test2')->table($t2)->insert($insert);
        $this->assertSame([], $logger->data);

        ManticoreDb::connection('test2')->table($t2)->setLogger($logger)->insert($insert);
        $this->assertSame('info', $logger->data[0]['level']);

        ManticoreDb::table($t1)->drop();
        ManticoreDb::connection('test2')->table($t2)->drop();
    }


    protected function dataPlaceholders(): array
    {
        return [
            [
                'create table ?products(title text, price float) morphology=\'stem_en\'',
                'create table second_products(title text, price float) morphology=\'stem_en\'',
                'create table products(title text, price float) morphology=\'stem_en\'',
            ],
            [
                'insert into ?products(title,price) values (\'crossbody bag with tassel\', 19.85), (\'microfiber sheet set\', 19.99), (\'pet hair remover glove\', 7.99)',
                'insert into second_products(title,price) values (\'crossbody bag with tassel\', 19.85), (\'microfiber sheet set\', 19.99), (\'pet hair remover glove\', 7.99)',
                'insert into products(title,price) values (\'crossbody bag with tassel\', 19.85), (\'microfiber sheet set\', 19.99), (\'pet hair remover glove\', 7.99)',
            ],
            [
                'select id, highlight(), price from ?products where match(\'remove hair\')',
                'select id, highlight(), price from second_products where match(\'remove hair\')',
                'select id, highlight(), price from products where match(\'remove hair\')',
            ],
            [
                'update ?products set price=18.5 where id = 1513686608316989452',
                'update second_products set price=18.5 where id = 1513686608316989452',
                'update products set price=18.5 where id = 1513686608316989452',
            ],
            [
                'delete from ?products where price < 10',
                'delete from second_products where price < 10',
                'delete from products where price < 10',
            ],
            [
                'TRUNCATE TABLE ?products with reconfigure;',
                'truncate table second_products with reconfigure',
                'truncate table products with reconfigure',
            ],
        ];
    }

    public function testPlaceholders()
    {
        $config = $this->getClientConfig();
        ManticoreDb::init($config);

        $data = $this->dataPlaceholders();
        foreach ($data as $pair) {
            $res = mb_strtolower(ManticoreDb::connection('test2')->sql($pair[0])->toSql());
            $this->assertSame($pair[1], $res);
        }

        $config['connections']['test2']['prefix'] = '';
        ManticoreDb::init($config);

        foreach ($data as $pair) {
            $res = mb_strtolower(ManticoreDb::connection('test2')->sql($pair[0])->toSql());
            $this->assertSame($pair[2], $res);
        }
    }
}

