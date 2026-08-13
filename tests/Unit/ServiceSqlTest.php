<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\Tests\Support\FakeClient;
use avadim\Manticore\Tests\Support\UnitTestCase;

/**
 * SQL of the service statements: SHOW TABLE ... STATUS / SETTINGS.
 */
final class ServiceSqlTest extends UnitTestCase
{
    public function testStatusUsesItsArgument(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'table_a')->status('table_b');

        $this->assertSame('SHOW TABLE table_b STATUS', $client->lastQuery());
    }

    public function testStatusFallsBackToTheTableOfTheQuery(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'table_a')->status();

        $this->assertSame('SHOW TABLE table_a STATUS', $client->lastQuery());
    }

    public function testStatusResolvesThePrefixOfItsArgument(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'table_a', ['prefix' => 'pre_'])->status('?table_b');

        $this->assertSame('SHOW TABLE pre_table_b STATUS', $client->lastQuery());
    }

    public function testSettingsUsesItsArgument(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'table_a')->settings('table_b');

        $this->assertSame('SHOW TABLE table_b SETTINGS', $client->lastQuery());
    }

    public function testSettingsFallsBackToTheTableOfTheQuery(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'table_a')->settings();

        $this->assertSame('SHOW TABLE table_a SETTINGS', $client->lastQuery());
    }
}
