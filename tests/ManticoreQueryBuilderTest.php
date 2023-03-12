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
}

