<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\Tests\Support\IntegrationTestCase;
use avadim\Manticore\Tests\Support\TestLogger;

/**
 * PSR-3 logging: the logger is passed down Builder -> Connection -> Query and can be
 * switched off at every one of those levels.
 */
final class LoggerTest extends IntegrationTestCase
{
    /** @var TestLogger */
    private TestLogger $logger;

    /** @var string */
    private string $table1;

    /** @var string */
    private string $table2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new TestLogger();
        $this->table1 = $this->createTable(['title' => 'text'], 'log1');
        $this->table2 = $this->createTable(['title' => 'text'], 'log2', [], self::CONNECTION_2);
    }

    public function testNothingIsLoggedWithoutLogger(): void
    {
        ManticoreDb::table($this->table1)->insert(['title' => 'a']);

        $this->assertTrue($this->logger->isEmpty());
    }

    public function testQueryIsLoggedAtInfoLevel(): void
    {
        ManticoreDb::setLogger($this->logger);
        ManticoreDb::table($this->table1)->insert(['title' => 'a']);

        $record = $this->logger->firstRecord('info');

        $this->assertNotNull($record);
        $this->assertSame('Manticore Query:', $record['message']);
    }

    public function testQueryContextContainsSqlAndCommand(): void
    {
        ManticoreDb::setLogger($this->logger);
        ManticoreDb::table($this->table1)->insert(['title' => 'logged value']);

        $context = $this->logger->firstRecord('info')['context'];

        $this->assertSame('INSERT', $context['command']);
        $this->assertStringContainsString($this->table1, $context['query']);
        $this->assertStringContainsString('logged value', $context['query']);
        $this->assertArrayHasKey('exec_time', $context);
        $this->assertArrayHasKey('response', $context);
    }

    public function testResultIsLoggedAtDebugLevel(): void
    {
        ManticoreDb::setLogger($this->logger);
        ManticoreDb::table($this->table1)->insert(['title' => 'a']);

        $record = $this->logger->firstRecord('debug');

        $this->assertNotNull($record, 'the result must be logged as debug');
        $this->assertSame('Manticore Result:', $record['message']);
        $this->assertArrayHasKey('result', $record['context']);
    }

    public function testSelectLogsQueryAndResult(): void
    {
        ManticoreDb::table($this->table1)->insert(['title' => 'a']);
        ManticoreDb::setLogger($this->logger);

        ManticoreDb::table($this->table1)->get();

        $this->assertSame(['info', 'debug'], $this->logger->levels());
    }

    public function testFailedQueryIsLoggedAsError(): void
    {
        // an empty table short-circuits the query before column resolution
        ManticoreDb::table($this->table1)->insert(['title' => 'a']);
        ManticoreDb::setLogger($this->logger);
        ManticoreDb::sql('SELECT * FROM ' . $this->table1 . ' WHERE nonexistent_column = 1')->exec();

        $errors = $this->logger->records('error');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('nonexistent_column', $errors[0]['message']);
    }

    public function testLoggerAppliesToEveryConnection(): void
    {
        ManticoreDb::setLogger($this->logger);

        ManticoreDb::table($this->table1)->insert(['title' => 'a']);
        $this->assertNotEmpty($this->logger->records('info'));

        $this->logger->reset();
        ManticoreDb::connection(self::CONNECTION_2)->table($this->table2)->insert(['title' => 'b']);
        $this->assertNotEmpty($this->logger->records('info'));
    }

    public function testLoggingCanBeDisabledGlobally(): void
    {
        ManticoreDb::setLogger($this->logger);
        ManticoreDb::setLogger(false);

        ManticoreDb::table($this->table1)->insert(['title' => 'a']);
        ManticoreDb::connection(self::CONNECTION_2)->table($this->table2)->insert(['title' => 'b']);

        $this->assertTrue($this->logger->isEmpty());
    }

    public function testLoggingCanBeDisabledForSingleConnection(): void
    {
        ManticoreDb::setLogger($this->logger);
        ManticoreDb::connection(self::CONNECTION_2)->setLogger(false);

        ManticoreDb::connection(self::CONNECTION_2)->table($this->table2)->insert(['title' => 'b']);
        $this->assertTrue($this->logger->isEmpty());

        ManticoreDb::table($this->table1)->insert(['title' => 'a']);
        $this->assertNotEmpty($this->logger->records('info'));
    }

    public function testLoggingCanBeEnabledForSingleQuery(): void
    {
        ManticoreDb::setLogger(false);

        ManticoreDb::table($this->table1)->insert(['title' => 'a']);
        $this->assertTrue($this->logger->isEmpty());

        ManticoreDb::table($this->table1)->setLogger($this->logger)->insert(['title' => 'b']);
        $this->assertNotEmpty($this->logger->records('info'));
    }

    public function testLoggerPassedToInitIsUsed(): void
    {
        ManticoreDb::init($this->config(), $this->logger);

        ManticoreDb::table($this->table1)->insert(['title' => 'a']);

        $this->assertNotEmpty($this->logger->records('info'));
    }
}
