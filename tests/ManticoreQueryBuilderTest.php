<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;

final class ManticoreQueryBuilderTest extends TestCase
{
    protected function getClientConfig()
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
                    'hosts' => [
                        'host' => 'localhost',
                        'port' => 9306,
                        'prefix' => 'second_', // prefix that will replace the placeholder "?<table_name>"
                    ],
                ],

            ],
        ];
    }


    public function testCreateAndDrop()
    {
        $prefix1 = 'test1_' . uniqid() . '_';
        $prefix2 = 'test2_' . uniqid() . '_';
        $config = $this->getClientConfig();
        $config['connections']['test1']['prefix'] = $prefix1;
        $config['connections']['test2']['prefix'] = $prefix2;

        ManticoreDb::init($config);

        // default connection "test1"
        ManticoreDb::table('?products')->drop(true);
        $res = ManticoreDb::table('?products')->create([
            'created_at' => 'timestamp',
            'manufacturer' => 'string',
            'title' => 'text',
            'info' => 'json',
            'price' => ['type' => 'float'],
            'categories' => 'multi',
            'on_sale' => 'bool',
        ]);
        $this->assertTrue($res->result());

        // connection "test2"
        ManticoreDb::connection('test2')->index('?products')->drop(true);
        $res = ManticoreDb::connection('test2')->create('?products', function (SchemaTable $index) {
            $index->timestamp('created_at');
            $index->string('manufacturer');
            $index->text('title');
            $index->json('info');
            $index->float('price');
            $index->multi('categories');
            $index->bool('on_sale');
        });
        $this->assertTrue($res->result());

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
        $table = 'test1_' . uniqid();
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

        $result = ManticoreDb::table($table)->select(['*', 'IN(metadata.color, :black, :white) as color_filter'])->where('color_filter=1')->bind([':black' => 'black', ':white' => 'white'])->get();
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
        $i = 1;
        return [
            [
                'id' => $i++,
                'time' => time(),
                'demo' => false,
                'country' => 'US',
                'price' => 100.00,
                'content' => 'lorem ipsum',
            ],
            [
                'id' => $i++,
                'time' => time(),
                'demo' => false,
                'country' => 'DE',
                'price' => 200.00,
                'content' => 'Lorem ipsum dolor sit amet',
            ],
            [
                'id' => $i++,
                'time' => time(),
                'demo' => true,
                'country' => 'US',
                'price' => 300.00,
                'content' => 'ipsum dolor sit amet',
            ],
            [
                'id' => $i++,
                'time' => time(),
                'demo' => false,
                'country' => 'DE',
                'price' => 180.00,
                'content' => 'amet',
            ],
            [
                'id' => $i++,
                'time' => time(),
                'demo' => true,
                'country' => 'UK',
                'price' => 230.00,
                'content' => 'dolor sit',
            ],
            [
                'id' => $i++,
                'time' => time(),
                'demo' => false,
                'country' => 'UK',
                'price' => 310.00,
                'content' => 'ipsum dolor sit',
            ],
            [
                'id' => $i++,
                'time' => time(),
                'demo' => false,
                'country' => 'DE',
                'price' => 185.00,
                'content' => 'ipsum',
            ],
            [
                'id' => $i++,
                'time' => time(),
                'demo' => true,
                'country' => 'US',
                'price' => 298.00,
                'content' => 'dolor sit',
            ],
        ];
    }

    public function testWhere()
    {
        $table = 'test1_' . uniqid();
        ManticoreDb::table($table)->drop(true);
        ManticoreDb::table($table)->create([
            'time' => 'timestamp',
            'demo' => 'bool',
            'country' => 'string',
            'price' => 'float',
            'content' => 'text',
        ]);
        $insert = $this->dataWhere();
        ManticoreDb::table($table)->insert($insert);

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

        ManticoreDb::table($table)->drop();
    }

}

